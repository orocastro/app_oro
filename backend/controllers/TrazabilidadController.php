<?php
/**
 * Clase TrazabilidadController
 * Maneja las operaciones de Trazabilidad (Ordenes, Movimientos, Consecutivos, etc.)
 */
class TrazabilidadController {
    private $db;
    private $upload_dir;

    /**
     * Constructor que recibe la conexión PDO.
     * @param PDO $db Objeto de conexión a la base de datos.
     */
    public function __construct($db) {
        $this->db = $db;
        // Directorio base para subir fotos dentro de assets del frontend
        $this->upload_dir = __DIR__ . '/../../frontend/assets/img';

        // Asegurarse de que el directorio de subida existe
        if (!is_dir($this->upload_dir)) {
            mkdir($this->upload_dir, 0777, true);
        }
    }

    /**
     * =========================================================
     * NUEVO: Gestión de Ordenes y Movimientos (Fase 2)
     * =========================================================
     */

    /**
     * Crea una nueva Orden (header) + movimiento de entrega inicial.
     * POST /api/ordenes
     * Compatible con POST /api/trazabilidad/entrega (legacy)
     */
    public function createOrden() {
        header('Content-Type: application/json; charset=utf-8');
        Logger::info('Iniciando createOrden', ['POST_DATA' => $_POST, 'FILES_DATA' => $_FILES]);

        // Soportar tanto JSON como form-data (legacy)
        $input = !empty($_POST) ? $_POST : (json_decode(file_get_contents('php://input'), true) ?? []);
        $isLegacy = (isset($input['fecha_movimiento_hidden']) || isset($_FILES['foto_entrega']));

        $required_fields = ['proceso_id', 'responsable_id', 'producto_id'];
        $hasPesoFields = (isset($input['peso_oro']) && $input['peso_oro'] !== '' && $input['peso_oro'] !== null)
                      || (isset($input['peso_materiales']) && $input['peso_materiales'] !== '' && $input['peso_materiales'] !== null);
        if (!$hasPesoFields) {
            $required_fields[] = 'peso_gr';
        }
        foreach ($required_fields as $field) {
            if (!isset($input[$field]) || $input[$field] === '' || $input[$field] === null) {
                $error_msg = "Campo obligatorio faltante: {$field}";
                Logger::warn($error_msg, ['request_data' => $input]);
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => $error_msg], JSON_UNESCAPED_UNICODE);
                return;
            }
        }

        $consecutivo = (isset($input['consecutivo']) && $input['consecutivo'] !== '' && is_numeric($input['consecutivo'])) ? (int)$input['consecutivo'] : 0;
        $proceso_id = (int)$input['proceso_id'];
        $responsable_id = (int)$input['responsable_id'];
        $producto_id = (int)$input['producto_id'];
        $cantidad = isset($input['cantidad']) && is_numeric($input['cantidad']) ? (int)$input['cantidad'] : 1;
        $observaciones = $input['observaciones'] ?? '';
        $observaciones_header = $input['observaciones_header'] ?? $observaciones;
        $fecha = $input['fecha'] ?? ($input['fecha_movimiento_hidden'] ?? date('Y-m-d H:i:s'));
        $creado_por = (int)(SessionManager::get('user_id') ?? 0);

        // Calcular peso_oro y peso_materiales
        $peso_oro_input = isset($input['peso_oro']) ? str_replace(',', '.', (string)$input['peso_oro']) : '0';
        $peso_materiales_input = isset($input['peso_materiales']) ? str_replace(',', '.', (string)$input['peso_materiales']) : '0';
        $peso_oro = is_numeric($peso_oro_input) ? (float)$peso_oro_input : 0.0;
        $peso_materiales = is_numeric($peso_materiales_input) ? (float)$peso_materiales_input : 0.0;

        // Backward compat: si llega peso_gr y no los nuevos campos, asumir todo como oro
        if (!$hasPesoFields) {
            $peso_gr_input = str_replace(',', '.', (string)$input['peso_gr']);
            $peso_oro = is_numeric($peso_gr_input) ? (float)$peso_gr_input : 0.0;
            $peso_materiales = 0.0;
        }

        $peso_entregado = round($peso_oro + $peso_materiales, 2);

        // Si llegan materiales de entrega en JSON, recalcular peso_materiales como suma de pesos
        $materiales = [];
        if (!empty($input['materiales_json'])) {
            $parsed = json_decode($input['materiales_json'], true);
            if (is_array($parsed)) {
                $materiales = $parsed;
                $sum = 0.0;
                foreach ($materiales as $m) {
                    $w = isset($m['peso']) ? (float)str_replace(',', '.', (string)$m['peso']) : 0.0;
                    $sum += $w;
                }
                $peso_materiales = round($sum, 2);
                $peso_entregado = round($peso_oro + $peso_materiales, 2);
            }
        }

        // Calcular peso_ley según proceso_id (solo para procesos reales > 4, o default)
        if ($proceso_id > 4) {
            $peso_ley = round($peso_entregado, 2);
        } else {
            $peso_ley = 0;
        }

        $estado_inicial = 'pendiente';

        // Si no llegó consecutivo, tomar el siguiente disponible (también se usa para la carpeta de fotos)
        if ($consecutivo <= 0) {
            $stmtAuto = $this->db->query("SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'Trazabilidad'");
            $consecutivo = (int)$stmtAuto->fetchColumn();
            if ($consecutivo <= 0) {
                $stmtMax = $this->db->query("SELECT COALESCE(MAX(consecutivo), 0) + 1 FROM Trazabilidad");
                $consecutivo = (int)$stmtMax->fetchColumn();
            }
        }

        // Subir fotos de entrega (soportar clave legacy 'foto_entrega' y nueva 'movimiento_entrega')
        $fotos = $this->uploadMultipleFiles($consecutivo, 'foto_entrega');
        if ($fotos === null || $fotos === false) {
            $fotos = $this->uploadMultipleFiles($consecutivo, 'movimiento_entrega');
        }
        if ($fotos === false) {
            Logger::error('Error grave al procesar las fotos de entrega.', ['consecutivo' => $consecutivo]);
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Error grave al procesar las fotos de entrega.'], JSON_UNESCAPED_UNICODE);
            return;
        }
        // Fotos opcionales - continuar sin foto si no se subio ninguna
        if ($fotos === null) {
            $fotos = null;
        }

        try {
            $this->db->beginTransaction();

            // Insertar header en Trazabilidad
            $sqlHeader = "INSERT INTO Trazabilidad (
                consecutivo, proceso_id, responsable_id, producto_id, cantidad,
                estado, fecha_entrega, peso_entregado, peso_ley, foto_entrega_path,
                observaciones, creado_por, observaciones_header
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmtHeader = $this->db->prepare($sqlHeader);
            try {
                $stmtHeader->execute([
                    $consecutivo, $proceso_id, $responsable_id, $producto_id, $cantidad,
                    $estado_inicial, $fecha, $peso_entregado, $peso_ley, $fotos,
                    $observaciones, $creado_por, $observaciones_header
                ]);
            } catch (PDOException $e) {
                // Consecutivo duplicado (otro usuario lo tomó): reintentar con el siguiente disponible
                if ($e->getCode() === '23000') {
                    $stmtMax = $this->db->query("SELECT COALESCE(MAX(consecutivo), 0) + 1 FROM Trazabilidad");
                    $consecutivo = (int)$stmtMax->fetchColumn();
                    $stmtHeader->execute([
                        $consecutivo, $proceso_id, $responsable_id, $producto_id, $cantidad,
                        $estado_inicial, $fecha, $peso_entregado, $peso_ley, $fotos,
                        $observaciones, $creado_por, $observaciones_header
                    ]);
                } else {
                    throw $e;
                }
            }

            // Insertar movimiento de entrega en Movimientos (con peso_oro y peso_materiales)
            $sqlMov = "INSERT INTO Movimientos (
                consecutivo, tipo, peso, peso_oro, peso_materiales, fecha, fotos_path, observaciones, registrado_por
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmtMov = $this->db->prepare($sqlMov);
            $stmtMov->execute([
                $consecutivo, 'entrega', $peso_entregado, $peso_oro, $peso_materiales, $fecha, $fotos,
                $observaciones_header, $creado_por
            ]);

            // Insertar materiales de entrega (detalle)
            if (!empty($materiales)) {
                $stmtIns = $this->db->prepare("INSERT INTO TrazabilidadMateriales (consecutivo, material_id, movimiento, peso, fotos_path) VALUES (?, ?, 'entrega', ?, ?)");
                foreach ($materiales as $m) {
                    $mid = (int)($m['material_id'] ?? 0);
                    if ($mid <= 0) continue;
                    $w = isset($m['peso']) ? (float)str_replace(',', '.', (string)$m['peso']) : 0.0;
                    $fileKey = 'material_fotos_' . $mid;
                    $paths_json = $this->uploadMultipleFiles($consecutivo, $fileKey);
                    $stmtIns->execute([$consecutivo, $mid, $w, $paths_json]);
                }
            }

            $this->db->commit();

            $status_message = 'Orden creada con éxito. Consecutivo: ' . $consecutivo;

            Logger::info($status_message, ['consecutivo' => $consecutivo]);
            http_response_code(201);
            echo json_encode([
                'success' => true,
                'message' => $status_message,
                'data' => [
                    'consecutivo' => $consecutivo,
                    'estado' => $estado_inicial,
                    'total_entregado' => $peso_entregado,
                    'total_recibido' => 0.00,
                    'saldo' => $peso_entregado,
                    'merma' => $peso_entregado
                ]
            ], JSON_UNESCAPED_UNICODE);
        } catch (PDOException $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            Logger::error('Error de DB en createOrden', ['exception' => $e->getMessage()]);
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Error de base de datos al crear la orden: ' . $e->getMessage()]);
        }
    }

    /**
     * Registra un movimiento (entrega adicional o recibido parcial) para una orden.
     * POST /api/movimientos
     * Compatible con POST /api/trazabilidad/recibido (legacy)
     */
    public function registerMovimiento() {
        header('Content-Type: application/json; charset=utf-8');
        Logger::info('Iniciando registerMovimiento', ['POST_DATA' => $_POST, 'FILES_DATA' => $_FILES]);

        $input = !empty($_POST) ? $_POST : (json_decode(file_get_contents('php://input'), true) ?? []);

        $required = ['consecutivo'];
        $hasPesoFields = (isset($input['peso_oro']) && $input['peso_oro'] !== '' && $input['peso_oro'] !== null)
                      || (isset($input['peso_materiales']) && $input['peso_materiales'] !== '' && $input['peso_materiales'] !== null);
        if (!$hasPesoFields) {
            $required[] = 'peso_gr';
        }
        foreach ($required as $field) {
            if (!isset($input[$field]) || $input[$field] === '' || $input[$field] === null) {
                $error_msg = "Campo obligatorio faltante: {$field}";
                Logger::warn($error_msg, ['request_data' => $input]);
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => $error_msg], JSON_UNESCAPED_UNICODE);
                return;
            }
        }

        $consecutivo = (int)$input['consecutivo'];
        $tipo = $input['tipo'] ?? 'recibido'; // Legacy no envía 'tipo', asumimos recibido
        $tipo = in_array($tipo, ['entrega', 'recibido'], true) ? $tipo : 'recibido';

        // Calcular peso_oro y peso_materiales
        $peso_oro_input = isset($input['peso_oro']) ? str_replace(',', '.', (string)$input['peso_oro']) : '0';
        $peso_materiales_input = isset($input['peso_materiales']) ? str_replace(',', '.', (string)$input['peso_materiales']) : '0';
        $peso_oro = is_numeric($peso_oro_input) ? (float)$peso_oro_input : 0.0;
        $peso_materiales = is_numeric($peso_materiales_input) ? (float)$peso_materiales_input : 0.0;

        // Backward compat: si llega peso_gr y no los nuevos campos, asumir todo como oro
        if (!$hasPesoFields) {
            $peso_gr_input = str_replace(',', '.', (string)$input['peso_gr']);
            $peso_oro = is_numeric($peso_gr_input) ? (float)$peso_gr_input : 0.0;
            $peso_materiales = 0.0;
        }

        $peso = round($peso_oro + $peso_materiales, 2);

        $fecha = $input['fecha'] ?? ($input['fecha_movimiento_hidden'] ?? date('Y-m-d H:i:s'));
        $observaciones = $input['observaciones'] ?? '';
        $registrado_por = (int)(SessionManager::get('user_id') ?? 0);

        // Si llegan materiales en JSON, recalcular peso_materiales como suma de pesos
        $materiales = [];
        if (!empty($input['materiales_json'])) {
            $parsed = json_decode($input['materiales_json'], true);
            if (is_array($parsed)) {
                $materiales = $parsed;
                $sum = 0.0;
                foreach ($materiales as $m) {
                    $w = isset($m['peso']) ? (float)str_replace(',', '.', (string)$m['peso']) : 0.0;
                    $sum += $w;
                }
                $peso_materiales = round($sum, 2);
                $peso = round($peso_oro + $peso_materiales, 2);
            }
        }

        // Subir fotos (soportar clave legacy 'foto_recibido' y nueva 'movimiento_recibido')
        $file_key = $tipo === 'entrega' ? 'foto_entrega' : 'foto_recibido';
        $fotos = $this->uploadMultipleFiles($consecutivo, $file_key);
        if ($fotos === null || $fotos === false) {
            $alt_key = 'movimiento_' . $tipo;
            $fotos = $this->uploadMultipleFiles($consecutivo, $alt_key);
        }
        if ($fotos === false) {
            Logger::error('Error grave al procesar las fotos.', ['consecutivo' => $consecutivo, 'tipo' => $tipo]);
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Error grave al procesar las fotos.'], JSON_UNESCAPED_UNICODE);
            return;
        }
        // Fotos opcionales - continuar sin foto si no se subio ninguna
        if ($fotos === null) {
            $fotos = null;
        }

        try {
            $this->db->beginTransaction();

            // Verificar que la orden existe
            $stmtCheck = $this->db->prepare("SELECT consecutivo, proceso_id FROM Trazabilidad WHERE consecutivo = ?");
            $stmtCheck->execute([$consecutivo]);
            $orden = $stmtCheck->fetch(PDO::FETCH_ASSOC);

            if (!$orden) {
                $this->db->rollBack();
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Orden no encontrada.'], JSON_UNESCAPED_UNICODE);
                return;
            }

            // Insertar movimiento (con peso_oro y peso_materiales)
            $sqlMov = "INSERT INTO Movimientos (
                consecutivo, tipo, peso, peso_oro, peso_materiales, fecha, fotos_path, observaciones, registrado_por
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmtMov = $this->db->prepare($sqlMov);
            $stmtMov->execute([
                $consecutivo, $tipo, $peso, $peso_oro, $peso_materiales, $fecha, $fotos, $observaciones, $registrado_por
            ]);

            // Insertar materiales si existen
            if (!empty($materiales)) {
                $stmtIns = $this->db->prepare("INSERT INTO TrazabilidadMateriales (consecutivo, material_id, movimiento, peso, fotos_path) VALUES (?, ?, ?, ?, ?)");
                foreach ($materiales as $m) {
                    $mid = (int)($m['material_id'] ?? 0);
                    if ($mid <= 0) continue;
                    $w = isset($m['peso']) ? (float)str_replace(',', '.', (string)$m['peso']) : 0.0;
                    $fileKey = 'material_fotos_' . $mid;
                    $paths_json = $this->uploadMultipleFiles($consecutivo, $fileKey);
                    $stmtIns->execute([$consecutivo, $mid, $tipo, $w, $paths_json]);
                }
            }

            // Recalcular totales y actualizar estado
            $totales = $this->calcularTotalesOrden($consecutivo);
            $nuevo_estado = $this->determinarEstado($totales['total_oro_entregado'], $totales['total_oro_recibido']);
            $merma = round($totales['total_oro_entregado'] - $totales['total_oro_recibido'], 2);

            $stmtUpd = $this->db->prepare("UPDATE Trazabilidad SET estado = ?, merma = ? WHERE consecutivo = ?");
            $stmtUpd->execute([$nuevo_estado, $merma, $consecutivo]);

            $this->db->commit();

            Logger::info('Movimiento registrado', ['consecutivo' => $consecutivo, 'tipo' => $tipo, 'peso' => $peso]);
            http_response_code(200);
            echo json_encode([
                'success' => true,
                'message' => 'Movimiento registrado con éxito.',
                'data' => [
                    'consecutivo' => $consecutivo,
                    'tipo' => $tipo,
                    'peso' => $peso,
                    'total_entregado' => $totales['total_oro_entregado'] + $totales['total_mat_entregado'],
                    'total_recibido' => $totales['total_oro_recibido'] + $totales['total_mat_recibido'],
                    'saldo' => $totales['saldo_oro'],
                    'merma' => $merma,
                    'estado' => $nuevo_estado
                ]
            ], JSON_UNESCAPED_UNICODE);
        } catch (PDOException $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            Logger::error('Error de DB en registerMovimiento', ['exception' => $e->getMessage()]);
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Error de base de datos al registrar el movimiento: ' . $e->getMessage()]);
        }
    }

    /**
     * Calcula totales de entrega y recibido para un consecutivo.
     */
    private function calcularTotalesOrden($consecutivo) {
        $stmt = $this->db->prepare("SELECT tipo, SUM(peso_oro) as total_oro, SUM(peso_materiales) as total_mat FROM Movimientos WHERE consecutivo = ? GROUP BY tipo");
        $stmt->execute([$consecutivo]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $total_oro_entregado = 0.0;
        $total_mat_entregado = 0.0;
        $total_oro_recibido = 0.0;
        $total_mat_recibido = 0.0;

        foreach ($rows as $r) {
            if ($r['tipo'] === 'entrega') {
                $total_oro_entregado = (float)$r['total_oro'];
                $total_mat_entregado = (float)$r['total_mat'];
            } elseif ($r['tipo'] === 'recibido') {
                $total_oro_recibido = (float)$r['total_oro'];
                $total_mat_recibido = (float)$r['total_mat'];
            }
        }

        $saldo_oro = round($total_oro_entregado - $total_oro_recibido, 2);
        $merma = $saldo_oro;

        return [
            'total_oro_entregado' => $total_oro_entregado,
            'total_mat_entregado' => $total_mat_entregado,
            'total_oro_recibido' => $total_oro_recibido,
            'total_mat_recibido' => $total_mat_recibido,
            'saldo_oro' => $saldo_oro,
            'merma' => $merma
        ];
    }

    /**
     * Determina el estado de la orden según totales.
     */
    private function determinarEstado($oro_entregado, $oro_recibido) {
        if ($oro_recibido > $oro_entregado && $oro_entregado > 0) {
            return 'merma_a_favor';
        } elseif ($oro_recibido >= $oro_entregado && $oro_entregado > 0) {
            return 'completado';
        } elseif ($oro_recibido > 0 && $oro_recibido < $oro_entregado) {
            return 'parcial';
        } else {
            return 'pendiente';
        }
    }

    /**
     * Lista todas las ordenes con totales calculados y paginación.
     * GET /api/ordenes
     */
    public function index() {
        header('Content-Type: application/json; charset=utf-8');
        try {
            $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) ? (int)$_GET['limit'] : 50;
            $offset = isset($_GET['offset']) && is_numeric($_GET['offset']) ? (int)$_GET['offset'] : 0;
            $estado = $_GET['estado'] ?? null;
            $search = $_GET['search'] ?? null;

            $sql = "SELECT
                t.consecutivo,
                t.proceso_id,
                t.producto_id,
                t.responsable_id,
                t.cantidad,
                t.estado,
                t.fecha_entrega AS fecha_creacion,
                t.fecha_entrega,
                p.nombre AS proceso_nombre,
                prod.nombre AS producto_nombre,
                r.nombre AS responsable_nombre,
                COALESCE((SELECT SUM(peso_oro) FROM Movimientos WHERE consecutivo = t.consecutivo AND tipo = 'entrega'), 0) AS total_oro_entregado,
                COALESCE((SELECT SUM(peso_materiales) FROM Movimientos WHERE consecutivo = t.consecutivo AND tipo = 'entrega'), 0) AS total_mat_entregado,
                COALESCE((SELECT SUM(peso_oro) FROM Movimientos WHERE consecutivo = t.consecutivo AND tipo = 'recibido'), 0) AS total_oro_recibido,
                COALESCE((SELECT SUM(peso_materiales) FROM Movimientos WHERE consecutivo = t.consecutivo AND tipo = 'recibido'), 0) AS total_mat_recibido,
                COALESCE((SELECT SUM(peso_oro) FROM Movimientos WHERE consecutivo = t.consecutivo AND tipo = 'entrega'), 0)
                - COALESCE((SELECT SUM(peso_oro) FROM Movimientos WHERE consecutivo = t.consecutivo AND tipo = 'recibido'), 0) AS saldo_oro,
                t.merma AS merma
            FROM Trazabilidad t
            LEFT JOIN Procesos p ON t.proceso_id = p.id
            LEFT JOIN Productos prod ON t.producto_id = prod.id
            LEFT JOIN Responsables r ON t.responsable_id = r.id
            WHERE 1=1";

            $params = [];
            if ($estado) {
                $sql .= " AND t.estado = ?";
                $params[] = $estado;
            }
            if ($search) {
                $sql .= " AND (p.nombre LIKE ? OR prod.nombre LIKE ? OR r.nombre LIKE ? OR t.consecutivo LIKE ?)";
                $like = '%' . $search . '%';
                $params[] = $like;
                $params[] = $like;
                $params[] = $like;
                $params[] = $like;
            }

            $sql .= " ORDER BY t.consecutivo DESC LIMIT ? OFFSET ?";

            $stmt = $this->db->prepare($sql);
            $idx = 1;
            foreach ($params as $p) {
                $stmt->bindValue($idx++, $p);
            }
            $stmt->bindValue($idx++, $limit, PDO::PARAM_INT);
            $stmt->bindValue($idx++, $offset, PDO::PARAM_INT);
            $stmt->execute();
            $ordenes = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Contar total para paginación
            $countSql = "SELECT COUNT(*) as total FROM Trazabilidad t WHERE 1=1";
            $countParams = [];
            if ($estado) {
                $countSql .= " AND t.estado = ?";
                $countParams[] = $estado;
            }
            if ($search) {
                $countSql .= " AND (p.nombre LIKE ? OR prod.nombre LIKE ? OR r.nombre LIKE ? OR t.consecutivo LIKE ?)";
                $like = '%' . $search . '%';
                $countParams[] = $like;
                $countParams[] = $like;
                $countParams[] = $like;
                $countParams[] = $like;
            }
            // Re-join para búsqueda en count
            if ($search) {
                $countSql = str_replace('FROM Trazabilidad t WHERE 1=1', 'FROM Trazabilidad t LEFT JOIN Procesos p ON t.proceso_id = p.id LEFT JOIN Productos prod ON t.producto_id = prod.id LEFT JOIN Responsables r ON t.responsable_id = r.id WHERE 1=1', $countSql);
            }
            $countStmt = $this->db->prepare($countSql);
            $countStmt->execute($countParams);
            $total = (int)$countStmt->fetch(PDO::FETCH_ASSOC)['total'];

            http_response_code(200);
            echo json_encode([
                'success' => true,
                'data' => $ordenes,
                'pagination' => [
                    'page' => ($offset / $limit) + 1,
                    'limit' => $limit,
                    'total' => $total
                ]
            ]);
        } catch (PDOException $e) {
            Logger::error('Error en index', ['exception' => $e->getMessage()]);
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Error al obtener las ordenes: ' . $e->getMessage()]);
        }
    }

    /**
     * Obtiene una orden completa con todos sus movimientos.
     * GET /api/ordenes/{consecutivo}
     */
    public function getByConsecutivo($data) {
        header('Content-Type: application/json; charset=utf-8');
        $consecutivo = isset($data['consecutivo']) ? (int)$data['consecutivo'] : 0;

        if (empty($consecutivo)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'El consecutivo es obligatorio.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        try {
            $sql = "SELECT
                t.consecutivo,
                t.proceso_id,
                t.producto_id,
                t.responsable_id,
                t.cantidad,
                t.estado,
                t.fecha_entrega AS fecha_creacion,
                t.observaciones_header,
                p.nombre AS proceso_nombre,
                prod.nombre AS producto_nombre,
                r.nombre AS responsable_nombre,
                COALESCE((SELECT SUM(peso_oro) FROM Movimientos WHERE consecutivo = t.consecutivo AND tipo = 'entrega'), 0) AS total_oro_entregado,
                COALESCE((SELECT SUM(peso_materiales) FROM Movimientos WHERE consecutivo = t.consecutivo AND tipo = 'entrega'), 0) AS total_mat_entregado,
                COALESCE((SELECT SUM(peso_oro) FROM Movimientos WHERE consecutivo = t.consecutivo AND tipo = 'recibido'), 0) AS total_oro_recibido,
                COALESCE((SELECT SUM(peso_materiales) FROM Movimientos WHERE consecutivo = t.consecutivo AND tipo = 'recibido'), 0) AS total_mat_recibido,
                COALESCE((SELECT SUM(peso_oro) FROM Movimientos WHERE consecutivo = t.consecutivo AND tipo = 'entrega'), 0)
                - COALESCE((SELECT SUM(peso_oro) FROM Movimientos WHERE consecutivo = t.consecutivo AND tipo = 'recibido'), 0) AS saldo_oro,
                t.merma AS merma
            FROM Trazabilidad t
            LEFT JOIN Procesos p ON t.proceso_id = p.id
            LEFT JOIN Productos prod ON t.producto_id = prod.id
            LEFT JOIN Responsables r ON t.responsable_id = r.id
            WHERE t.consecutivo = ?";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([$consecutivo]);
            $orden = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$orden) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Orden no encontrada.'], JSON_UNESCAPED_UNICODE);
                return;
            }

            // Obtener movimientos
            $stmtMov = $this->db->prepare("SELECT id, tipo, peso, peso_oro, peso_materiales, fecha, fotos_path, observaciones, registrado_por, created_at FROM Movimientos WHERE consecutivo = ? ORDER BY fecha ASC, id ASC");
            $stmtMov->execute([$consecutivo]);
            $movimientos = $stmtMov->fetchAll(PDO::FETCH_ASSOC);

            foreach ($movimientos as &$mov) {
                $mov['fotos'] = [];
                if (!empty($mov['fotos_path'])) {
                    $arr = json_decode($mov['fotos_path'], true);
                    if (is_array($arr)) {
                        $mov['fotos'] = $arr;
                    }
                }
                unset($mov['fotos_path']);
            }

            $orden['movimientos'] = $movimientos;
            $orden['total_oro_entregado'] = (float)$orden['total_oro_entregado'];
            $orden['total_mat_entregado'] = (float)$orden['total_mat_entregado'];
            $orden['total_oro_recibido'] = (float)$orden['total_oro_recibido'];
            $orden['total_mat_recibido'] = (float)$orden['total_mat_recibido'];
            $orden['saldo_oro'] = (float)$orden['saldo_oro'];
            $orden['merma'] = (float)$orden['merma'];

            http_response_code(200);
            echo json_encode(['success' => true, 'data' => $orden], JSON_UNESCAPED_UNICODE);
        } catch (PDOException $e) {
            Logger::error('Error en getByConsecutivo', ['exception' => $e->getMessage()]);
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Error al obtener la orden: ' . $e->getMessage()]);
        }
    }

    /**
     * Obtiene solo los movimientos de una orden.
     * GET /api/ordenes/{consecutivo}/movimientos
     */
    public function getMovimientosByConsecutivo($data) {
        header('Content-Type: application/json; charset=utf-8');
        $consecutivo = isset($data['consecutivo']) ? (int)$data['consecutivo'] : 0;

        if (empty($consecutivo)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'El consecutivo es obligatorio.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        try {
            $stmt = $this->db->prepare("SELECT id, tipo, peso, peso_oro, peso_materiales, fecha, fotos_path, observaciones, registrado_por, created_at FROM Movimientos WHERE consecutivo = ? ORDER BY fecha ASC, id ASC");
            $stmt->execute([$consecutivo]);
            $movimientos = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($movimientos as &$mov) {
                $mov['fotos'] = [];
                if (!empty($mov['fotos_path'])) {
                    $arr = json_decode($mov['fotos_path'], true);
                    if (is_array($arr)) {
                        $mov['fotos'] = $arr;
                    }
                }
                unset($mov['fotos_path']);
            }

            http_response_code(200);
            echo json_encode(['success' => true, 'data' => $movimientos], JSON_UNESCAPED_UNICODE);
        } catch (PDOException $e) {
            Logger::error('Error en getMovimientosByConsecutivo', ['exception' => $e->getMessage()]);
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Error al obtener movimientos: ' . $e->getMessage()]);
        }
    }

    /**
     * =========================================================
     * EXISTENTES: Consecutivos, Edición, Materiales, Utils
     * =========================================================
     */

    /**
     * Obtiene el siguiente número de consecutivo (GET /api/trazabilidad/consecutivo).
     */
    public function getNextConsecutivo() {
        header('Content-Type: application/json; charset=utf-8');
        try {
            $stmt = $this->db->prepare("SELECT MAX(consecutivo) as max_consecutivo FROM Trazabilidad");
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            $next_consecutivo = (int)($result['max_consecutivo'] ?? 0) + 1;

            http_response_code(200);
            echo json_encode([
                'success' => true,
                'consecutivo' => $next_consecutivo,
                'consecutivo_display' => str_pad($next_consecutivo, 4, '0', STR_PAD_LEFT)
            ]);
        } catch (PDOException $e) {
            Logger::error('Error en getNextConsecutivo', ['exception' => $e->getMessage()]);
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => "Error al obtener consecutivo: " . $e->getMessage()]);
        }
    }

    /**
     * Obtiene todos los datos de un registro para el formulario de edición.
     * (GET /api/trazabilidad/edit/{consecutivo})
     */
    public function getForEdit($data) {
        header('Content-Type: application/json; charset=utf-8');
        // Solo administradores pueden editar
        if (!SessionManager::get('is_admin')) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Acceso no autorizado.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        $consecutivo = $data['consecutivo'] ?? null;
        if (empty($consecutivo)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'El consecutivo es obligatorio.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        try {
            $sql = "SELECT
                t.consecutivo,
                t.fecha_entrega,
                t.proceso_id,
                t.responsable_id,
                t.producto_id,
                t.peso_entregado AS peso_entregado_gr,
                t.peso_ley AS peso_ley_gr,
                t.observaciones_header,
                t.cantidad,
                t.estado,
                t.foto_entrega_path,
                p.nombre AS proceso_nombre,
                r.nombre AS responsable_nombre,
                prod.nombre AS producto_nombre
            FROM Trazabilidad t
            LEFT JOIN Procesos p ON t.proceso_id = p.id
            LEFT JOIN Responsables r ON t.responsable_id = r.id
            LEFT JOIN Productos prod ON t.producto_id = prod.id
            WHERE t.consecutivo = ?";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([$consecutivo]);
            $registro = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($registro) {
                // Parsear observaciones para el frontend (legacy)
                $obs = $registro['observaciones_header'] ?? '';
                $obsEntrega = '';
                $obsRecibido = '';
                foreach (preg_split("/\r?\n/", (string)$obs) as $line) {
                    $lineTrim = trim($line);
                    if (stripos($lineTrim, 'ENTREGA:') === 0) {
                        $obsEntrega = trim(substr($lineTrim, 8));
                    } elseif (stripos($lineTrim, 'RECIBIDO:') === 0) {
                        $obsRecibido = trim(substr($lineTrim, 9));
                    }
                }
                $registro['obs_entrega_pura'] = $obsEntrega;
                $registro['obs_recibido_pura'] = $obsRecibido;

                http_response_code(200);
                echo json_encode(['success' => true, 'data' => $registro], JSON_UNESCAPED_UNICODE);
            } else {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Registro no encontrado para editar.'], JSON_UNESCAPED_UNICODE);
            }
        } catch (PDOException $e) {
            Logger::error('Error en getForEdit', ['exception' => $e->getMessage()]);
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => "Error de base de datos: " . $e->getMessage()]);
        }
    }

    /**
     * Actualiza un registro de trazabilidad existente (header).
     * (POST /api/trazabilidad/update)
     */
    public function update() {
        header('Content-Type: application/json; charset=utf-8');

        // Validar permisos
        if (!SessionManager::get('is_admin')) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Acceso no autorizado.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        // Validar consecutivo
        $consecutivo = $_POST['consecutivo'] ?? null;
        if (empty($consecutivo)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'No se proporcionó el consecutivo del registro a actualizar.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        // Manejar nuevas fotos de header (legacy: foto_entrega)
        $new_entrega_paths_json = $this->uploadMultipleFiles($consecutivo, 'foto_entrega');
        $new_mov_paths_json = $this->uploadMultipleFiles($consecutivo, 'movimiento_entrega');
        $final_mov_paths = [];
        if (is_string($new_entrega_paths_json)) {
            $final_mov_paths = array_merge($final_mov_paths, json_decode($new_entrega_paths_json, true) ?? []);
        }
        if (is_string($new_mov_paths_json)) {
            $final_mov_paths = array_merge($final_mov_paths, json_decode($new_mov_paths_json, true) ?? []);
        }

        // Combinar con existentes (desde inputs hidden)
        $existing_entrega_paths = json_decode($_POST['existing_fotos_entrega'] ?? '[]', true);
        if (!is_array($existing_entrega_paths)) $existing_entrega_paths = [];
        $final_entrega_paths = array_values(array_filter(array_merge($existing_entrega_paths, $final_mov_paths), 'is_string'));

        $existing_recibido_paths = json_decode($_POST['existing_fotos_recibido'] ?? '[]', true);
        if (!is_array($existing_recibido_paths)) $existing_recibido_paths = [];
        $new_recibido_paths = $this->uploadMultipleFiles($consecutivo, 'foto_recibido');
        $new_recibido_arr = is_string($new_recibido_paths) ? (json_decode($new_recibido_paths, true) ?? []) : [];
        $final_recibido_paths = array_values(array_filter(array_merge($existing_recibido_paths, $new_recibido_arr), 'is_string'));

        // Observaciones
        $obs_entrega = !empty($_POST['obs_entrega']) ? 'ENTREGA: ' . $_POST['obs_entrega'] : '';
        $obs_recibido = !empty($_POST['obs_recibido']) ? 'RECIBIDO: ' . $_POST['obs_recibido'] : '';
        $observaciones_final = trim($obs_entrega . "\n" . $obs_recibido);
        $observaciones_header = !empty($_POST['observaciones_header']) ? $_POST['observaciones_header'] : $observaciones_final;

        // Números y merma (pueden ajustarse si llegan materiales)
        $peso_entregado = (float)str_replace(',', '.', $_POST['peso_entregado'] ?? '0');
        $peso_recibido_str = $_POST['peso_recibido'] ?? '';
        $peso_recibido = ($peso_recibido_str !== '') ? (float)str_replace(',', '.', $peso_recibido_str) : null;

        // Materiales (opcional en edición)
        $materialesEntrega = $this->decodeMaterialesJson($_POST['materiales_entrega_json'] ?? '');
        $materialesRecibido = $this->decodeMaterialesJson($_POST['materiales_recibido_json'] ?? '');
        $hasMatEntrega = !empty($materialesEntrega);
        $hasMatRecibido = !empty($materialesRecibido);

        if ($hasMatEntrega) {
            $peso_entregado = $this->sumMaterialesPeso($materialesEntrega);
        }
        if ($hasMatRecibido) {
            $peso_recibido = $this->sumMaterialesPeso($materialesRecibido);
        }

        $merma = ($peso_recibido !== null) ? round($peso_entregado - $peso_recibido, 2) : null;

        // Recalcular peso_ley según reglas de proceso
        $proceso_id_int = (int)($_POST['proceso_id'] ?? 0);
        if ($proceso_id_int === 1) {
            $peso_ley = round($peso_entregado * -1, 2);
        } elseif (in_array($proceso_id_int, [2, 3, 4])) {
            $peso_ley = round($peso_entregado * 1.39, 2);
        } elseif ($proceso_id_int > 4) {
            $peso_ley = round($peso_entregado, 2);
        } else {
            $peso_ley = 0;
        }

        // Recalcular estado según movimientos existentes + posible ajuste manual
        try {
            $totales = $this->calcularTotalesOrden($consecutivo);
            $nuevo_estado = $this->determinarEstado($totales['total_oro_entregado'], $totales['total_oro_recibido']);

            $sql = "UPDATE Trazabilidad SET
                fecha_entrega = ?, proceso_id = ?, responsable_id = ?, producto_id = ?,
                peso_entregado = ?, peso_ley = ?, observaciones = ?, observaciones_header = ?, foto_entrega_path = ?,
                fecha_recibido = ?, peso_recibido = ?, foto_recibido_path = ?, merma = ?, estado = ?, cantidad = ?
            WHERE consecutivo = ?";

            $stmt = $this->db->prepare($sql);
            $params = [
                $_POST['fecha_entrega'] ?: null,
                $_POST['proceso_id'] ?: null,
                $_POST['responsable_id'] ?: null,
                $_POST['producto_id'] ?: null,
                $peso_entregado,
                $peso_ley,
                $observaciones_final,
                $observaciones_header,
                json_encode($final_entrega_paths, JSON_UNESCAPED_UNICODE),
                $_POST['fecha_recibido'] ?: null,
                $peso_recibido,
                json_encode($final_recibido_paths, JSON_UNESCAPED_UNICODE),
                $merma,
                $nuevo_estado,
                (int)($_POST['cantidad'] ?? 1),
                $consecutivo
            ];

            $stmt->execute($params);

            // Guardar materiales editados (si se enviaron)
            if ($hasMatEntrega) {
                $this->saveMaterialesForUpdate($consecutivo, 'entrega', $materialesEntrega, 'material_fotos_entrega_');
            }
            if ($hasMatRecibido) {
                $this->saveMaterialesForUpdate($consecutivo, 'recibido', $materialesRecibido, 'material_fotos_recibido_');
            }

            http_response_code(200);
            echo json_encode(['success' => true, 'message' => 'Registro actualizado con éxito.'], JSON_UNESCAPED_UNICODE);
        } catch (PDOException $e) {
            Logger::error('Error de DB en update', ['exception' => $e->getMessage()]);
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Error de base de datos al actualizar.'], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * Devuelve materiales (entrega/recibido) asociados a un consecutivo.
     * GET /api/trazabilidad/materiales/{consecutivo}
     */
    public function getMaterialsByConsecutivo($data) {
        header('Content-Type: application/json; charset=utf-8');
        $consecutivo = $data['consecutivo'] ?? null;
        if (empty($consecutivo)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'El consecutivo es obligatorio.'], JSON_UNESCAPED_UNICODE);
            return;
        }
        try {
            $sql = "SELECT tm.material_id, m.nombre, tm.movimiento, tm.peso, tm.fotos_path
                FROM TrazabilidadMateriales tm
                JOIN Materiales m ON m.id = tm.material_id
                WHERE tm.consecutivo = ?
                ORDER BY m.nombre ASC";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$consecutivo]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $entrega = [];
            $recibido = [];
            foreach ($rows as $r) {
                $r['fotos'] = [];
                if (!empty($r['fotos_path'])) {
                    $arr = json_decode($r['fotos_path'], true);
                    if (is_array($arr)) $r['fotos'] = $arr;
                }
                if ($r['movimiento'] === 'entrega') $entrega[] = $r;
                else $recibido[] = $r;
            }
            http_response_code(200);
            echo json_encode(['success' => true, 'data' => ['entrega' => $entrega, 'recibido' => $recibido]], JSON_UNESCAPED_UNICODE);
        } catch (PDOException $e) {
            Logger::error('Error en getMaterialsByConsecutivo', ['exception' => $e->getMessage()]);
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Error al obtener materiales: ' . $e->getMessage()]);
        }
    }

    /**
     * =========================================================
     * UTILIDADES
     * =========================================================
     */

    /**
     * Función utilitaria para subir múltiples archivos.
     */
    private function uploadMultipleFiles($consecutivo, $file_key) {
        if (!isset($_FILES[$file_key])) {
            return null;
        }

        $uploaded_paths = [];
        $file_count = count($_FILES[$file_key]['name']);
        $consecutivo_dir = $this->upload_dir . '/' . $consecutivo;

        if (!is_dir($consecutivo_dir)) {
            if (!mkdir($consecutivo_dir, 0777, true)) {
                error_log("Error al crear directorio: " . $consecutivo_dir);
                return false;
            }
        }

        for ($i = 0; $i < $file_count; $i++) {
            if ($_FILES[$file_key]['error'][$i] === UPLOAD_ERR_OK) {
                $tmp_name = $_FILES[$file_key]['tmp_name'][$i];
                $original_name = $_FILES[$file_key]['name'][$i];

                $file_extension = pathinfo($original_name, PATHINFO_EXTENSION);
                $filename = uniqid($file_key . '_', true) . '.' . $file_extension;
                $destination = $consecutivo_dir . '/' . $filename;

                if (move_uploaded_file($tmp_name, $destination)) {
                    $uploaded_paths[] = 'frontend/assets/img/' . $consecutivo . '/' . $filename;
                } else {
                    error_log("Error al mover el archivo subido a: " . $destination);
                }
            }
        }

        return !empty($uploaded_paths) ? json_encode($uploaded_paths, JSON_UNESCAPED_UNICODE) : null;
    }

    /**
     * Decodifica JSON de materiales para edición.
     */
    private function decodeMaterialesJson($json) {
        if (!$json) return [];
        $data = json_decode($json, true);
        return is_array($data) ? $data : [];
    }

    /**
     * Suma pesos de materiales (redondeado a 2 decimales).
     */
    private function sumMaterialesPeso($items) {
        $sum = 0.0;
        foreach ($items as $it) {
            $raw = $it['peso'] ?? 0;
            $num = (float)str_replace(',', '.', (string)$raw);
            $sum += $num;
        }
        return round($sum, 2);
    }

    /**
     * Guarda materiales editados (upsert + manejo de fotos).
     */
    private function saveMaterialesForUpdate($consecutivo, $movimiento, $items, $fileKeyPrefix) {
        if (!in_array($movimiento, ['entrega', 'recibido'], true)) {
            return;
        }

        foreach ($items as $it) {
            $mid = (int)($it['material_id'] ?? 0);
            if ($mid <= 0) continue;

            $rawPeso = $it['peso'] ?? 0;
            $peso = (float)str_replace(',', '.', (string)$rawPeso);

            $existing = $it['existing_fotos'] ?? ($it['fotos'] ?? []);
            if (!is_array($existing)) $existing = [];
            $existing = array_values(array_filter($existing, 'is_string'));

            // Subir nuevas fotos si existen
            $newJson = $this->uploadMultipleFiles($consecutivo, $fileKeyPrefix . $mid);
            $newArr = [];
            if (is_string($newJson)) {
                $tmp = json_decode($newJson, true);
                if (is_array($tmp)) $newArr = $tmp;
            }

            $photos = array_values(array_merge($existing, $newArr, JSON_UNESCAPED_UNICODE));
            $hasData = ($peso > 0) || !empty($photos);

            if (!$hasData) {
                $del = $this->db->prepare("DELETE FROM TrazabilidadMateriales WHERE consecutivo = ? AND material_id = ? AND movimiento = ?");
                $del->execute([$consecutivo, $mid, $movimiento]);
                continue;
            }

            // Upsert manual
            $chk = $this->db->prepare("SELECT id FROM TrazabilidadMateriales WHERE consecutivo = ? AND material_id = ? AND movimiento = ? LIMIT 1");
            $chk->execute([$consecutivo, $mid, $movimiento]);
            if ($chk->fetch()) {
                $upd = $this->db->prepare("UPDATE TrazabilidadMateriales SET peso = ?, fotos_path = ? WHERE consecutivo = ? AND material_id = ? AND movimiento = ?");
                $upd->execute([$peso, json_encode($photos, JSON_UNESCAPED_UNICODE), $consecutivo, $mid, $movimiento]);
            } else {
                $ins = $this->db->prepare("INSERT INTO TrazabilidadMateriales (consecutivo, material_id, movimiento, peso, fotos_path) VALUES (?, ?, ?, ?, ?)");
                $ins->execute([$consecutivo, $mid, $movimiento, $peso, json_encode($photos, JSON_UNESCAPED_UNICODE)]);
            }
        }
    }
}
