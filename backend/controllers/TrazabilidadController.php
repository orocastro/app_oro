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
     * Verifica que exista una sesión activa (usuario logueado).
     * Si no hay sesión, responde 401 y devuelve false.
     */
    private function requiereSesion() {
        if (!SessionManager::get('logged_in')) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'No autenticado.'], JSON_UNESCAPED_UNICODE);
            return false;
        }
        return true;
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
        if (!$this->requiereSesion()) { return; }
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
        if ($peso_entregado <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Debe ingresar un peso de oro o de materiales mayor a cero.'], JSON_UNESCAPED_UNICODE);
            return;
        }
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
        if (!$this->requiereSesion()) { return; }
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

        if ($peso_oro <= 0 && $peso_materiales <= 0 && empty($input['materiales_json'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Debe ingresar un peso de oro o de materiales mayor a cero.'], JSON_UNESCAPED_UNICODE);
            return;
        }

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

            // Verificar que la orden existe y no esta cerrada
            $stmtCheck = $this->db->prepare("SELECT consecutivo, proceso_id, estado FROM Trazabilidad WHERE consecutivo = ?");
            $stmtCheck->execute([$consecutivo]);
            $orden = $stmtCheck->fetch(PDO::FETCH_ASSOC);

            if (!$orden) {
                $this->db->rollBack();
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Orden no encontrada.'], JSON_UNESCAPED_UNICODE);
                return;
            }
            if ($orden['estado'] === 'cerrada') {
                $this->db->rollBack();
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'La orden esta cerrada. No se pueden agregar mas movimientos.'], JSON_UNESCAPED_UNICODE);
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

            // Recalcular totales y actualizar estado (estado y merma sobre peso TOTAL: oro + materiales)
            $totales = $this->calcularTotalesOrden($consecutivo);
            $entregado_total = $totales['total_oro_entregado'] + $totales['total_mat_entregado'];
            $recibido_total = $totales['total_oro_recibido'] + $totales['total_mat_recibido'];
            $nuevo_estado = $this->determinarEstado($entregado_total, $recibido_total);
            $merma = $totales['saldo_total'];

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
                    'total_entregado' => $entregado_total,
                    'total_recibido' => $recibido_total,
                    'saldo' => $totales['saldo_total'],
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
        // Merma sobre el peso TOTAL (oro + materiales): las piedras entregadas que
        // regresan engastadas en la joya cuentan dentro del peso devuelto
        $saldo_total = round(($total_oro_entregado + $total_mat_entregado) - ($total_oro_recibido + $total_mat_recibido), 2);
        $merma = $saldo_total;

        return [
            'total_oro_entregado' => $total_oro_entregado,
            'total_mat_entregado' => $total_mat_entregado,
            'total_oro_recibido' => $total_oro_recibido,
            'total_mat_recibido' => $total_mat_recibido,
            'saldo_oro' => $saldo_oro,
            'saldo_total' => $saldo_total,
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
     * Amplía el ENUM estado de Trazabilidad para incluir TODOS los valores que
     * usa el código ('pendiente','parcial','completado','merma_a_favor','cancelado','cerrada').
     * Lee primero los valores actuales para conservar los que existan en producción.
     */
    private function repararEnumEstado() {
        $stmt = $this->db->prepare("
            SELECT COLUMN_TYPE FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'Trazabilidad' AND COLUMN_NAME = 'estado'
        ");
        $stmt->execute();
        $colType = (string)$stmt->fetchColumn();

        $valores = ['pendiente', 'parcial', 'completado', 'merma_a_favor', 'cancelado', 'cerrada'];
        // Conservar valores actuales del enum (por si la BD de producción tiene extras)
        if (preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $colType, $mm)) {
            foreach ($mm[1] as $v) {
                if (!in_array($v, $valores, true)) {
                    $valores[] = $v;
                }
            }
        }
        $enumDef = implode(',', array_map(function ($v) { return "'" . str_replace("'", "''", $v) . "'"; }, $valores));

        $sql = "ALTER TABLE Trazabilidad MODIFY COLUMN estado ENUM($enumDef) NOT NULL DEFAULT 'pendiente'";
        $this->db->exec($sql);
        Logger::info('ENUM estado de Trazabilidad actualizado', ['column_type' => "ENUM($enumDef)"]);
    }

    /**
     * Obtiene las ordenes abiertas (no cerradas) para dropdown.
     * GET /api/ordenes/abiertas
     */
    public function getOrdenesAbiertas() {
        header('Content-Type: application/json; charset=utf-8');
        if (!$this->requiereSesion()) { return; }
        try {
            $sql = "SELECT
                t.consecutivo,
                r.nombre AS responsable_nombre,
                prod.nombre AS producto_nombre,
                t.estado,
                COALESCE((SELECT SUM(peso_oro) + SUM(peso_materiales) FROM Movimientos WHERE consecutivo = t.consecutivo AND tipo = 'entrega'), 0) AS total_entregado
            FROM Trazabilidad t
            LEFT JOIN Responsables r ON t.responsable_id = r.id
            LEFT JOIN Productos prod ON t.producto_id = prod.id
            WHERE t.estado != 'cerrada'
            ORDER BY t.consecutivo DESC";
            $stmt = $this->db->query($sql);
            $ordenes = $stmt->fetchAll(PDO::FETCH_ASSOC);
            http_response_code(200);
            echo json_encode(['success' => true, 'data' => $ordenes], JSON_UNESCAPED_UNICODE);
        } catch (PDOException $e) {
            Logger::error('Error en getOrdenesAbiertas', ['exception' => $e->getMessage()]);
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Error al obtener ordenes abiertas: ' . $e->getMessage()]);
        }
    }

    /**
     * Bandeja de ordenes abiertas (pendiente/parcial) con adeudo por responsable.
     * GET /api/ordenes/bandeja
     */
    public function getBandeja() {
        header('Content-Type: application/json; charset=utf-8');
        if (!$this->requiereSesion()) { return; }
        try {
            $sql = "SELECT
                t.consecutivo,
                t.estado,
                t.responsable_id,
                r.nombre AS responsable,
                p.nombre AS proceso,
                prod.nombre AS producto,
                t.cantidad,
                t.fecha_entrega AS fecha_creacion,
                COALESCE((SELECT SUM(peso_oro) FROM Movimientos WHERE consecutivo = t.consecutivo AND tipo = 'entrega'), 0) AS oro_entregado,
                COALESCE((SELECT SUM(peso_oro) FROM Movimientos WHERE consecutivo = t.consecutivo AND tipo = 'recibido'), 0) AS oro_recibido,
                COALESCE((SELECT SUM(peso_materiales) FROM Movimientos WHERE consecutivo = t.consecutivo AND tipo = 'entrega'), 0) AS materiales_entregados,
                COALESCE((SELECT SUM(peso_materiales) FROM Movimientos WHERE consecutivo = t.consecutivo AND tipo = 'recibido'), 0) AS materiales_recibidos,
                DATEDIFF(NOW(), DATE(t.fecha_entrega)) AS dias_abierta
            FROM Trazabilidad t
            LEFT JOIN Procesos p ON t.proceso_id = p.id
            LEFT JOIN Productos prod ON t.producto_id = prod.id
            LEFT JOIN Responsables r ON t.responsable_id = r.id
            WHERE t.estado IN ('pendiente', 'parcial')
            ORDER BY r.nombre ASC, t.fecha_entrega ASC";

            $stmt = $this->db->query($sql);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $ordenes = [];
            $porResponsable = [];
            $totalAdeudado = 0.0;

            foreach ($rows as $row) {
                $oroEnt = round((float)$row['oro_entregado'], 2);
                $oroRec = round((float)$row['oro_recibido'], 2);
                $matEnt = round((float)$row['materiales_entregados'], 2);
                $matRec = round((float)$row['materiales_recibidos'], 2);
                $saldo = round(($oroEnt + $matEnt) - ($oroRec + $matRec), 2);

                $orden = [
                    'consecutivo' => (int)$row['consecutivo'],
                    'estado' => $row['estado'],
                    'responsable_id' => (int)$row['responsable_id'],
                    'responsable' => $row['responsable'],
                    'proceso' => $row['proceso'],
                    'producto' => $row['producto'],
                    'cantidad' => (int)$row['cantidad'],
                    'fecha_creacion' => $row['fecha_creacion'],
                    'oro_entregado' => $oroEnt,
                    'oro_recibido' => $oroRec,
                    'materiales_entregados' => $matEnt,
                    'materiales_recibidos' => $matRec,
                    'saldo' => $saldo,
                    'dias_abierta' => (int)$row['dias_abierta']
                ];
                $ordenes[] = $orden;

                $key = (int)$row['responsable_id'];
                if (!isset($porResponsable[$key])) {
                    $porResponsable[$key] = [
                        'responsable_id' => $key,
                        'responsable' => $row['responsable'],
                        'ordenes' => 0,
                        'oro_adeudado' => 0.0,
                        'total_adeudado' => 0.0,
                        'mas_antigua' => $row['fecha_creacion']
                    ];
                }
                $porResponsable[$key]['ordenes']++;
                $porResponsable[$key]['oro_adeudado'] = round($porResponsable[$key]['oro_adeudado'] + ($oroEnt - $oroRec), 2);
                $porResponsable[$key]['total_adeudado'] = round($porResponsable[$key]['total_adeudado'] + $saldo, 2);
                if ($row['fecha_creacion'] < $porResponsable[$key]['mas_antigua']) {
                    $porResponsable[$key]['mas_antigua'] = $row['fecha_creacion'];
                }

                $totalAdeudado = round($totalAdeudado + $saldo, 2);
            }

            // Ordenar agrupación por adeudo total descendente
            $porResponsable = array_values($porResponsable);
            usort($porResponsable, function ($a, $b) {
                if ($b['total_adeudado'] === $a['total_adeudado']) { return 0; }
                return $b['total_adeudado'] > $a['total_adeudado'] ? 1 : -1;
            });

            http_response_code(200);
            echo json_encode([
                'success' => true,
                'data' => [
                    'por_responsable' => $porResponsable,
                    'ordenes' => $ordenes,
                    'total_ordenes' => count($ordenes),
                    'total_adeudado' => $totalAdeudado
                ]
            ], JSON_UNESCAPED_UNICODE);
        } catch (PDOException $e) {
            Logger::error('Error en getBandeja', ['exception' => $e->getMessage()]);
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Error al obtener la bandeja de ordenes: ' . $e->getMessage()]);
        }
    }

    /**
     * Cierra una orden y calcula la merma final.
     * POST /api/ordenes/{consecutivo}/cerrar
     */
    public function cerrarOrden($data) {
        header('Content-Type: application/json; charset=utf-8');
        if (!$this->requiereSesion()) { return; }
        $consecutivo = isset($data['consecutivo']) ? (int)$data['consecutivo'] : 0;

        if (empty($consecutivo)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'El consecutivo es obligatorio.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        try {
            // Verificar que la orden existe y no esta cerrada
            $stmtCheck = $this->db->prepare("SELECT consecutivo, estado FROM Trazabilidad WHERE consecutivo = ?");
            $stmtCheck->execute([$consecutivo]);
            $orden = $stmtCheck->fetch(PDO::FETCH_ASSOC);

            if (!$orden) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Orden no encontrada.'], JSON_UNESCAPED_UNICODE);
                return;
            }
            if ($orden['estado'] === 'cerrada') {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'La orden ya esta cerrada.'], JSON_UNESCAPED_UNICODE);
                return;
            }

            // Calcular totales finales
            $totales = $this->calcularTotalesOrden($consecutivo);
            $entregado_total = $totales['total_oro_entregado'] + $totales['total_mat_entregado'];
            $recibido_total = $totales['total_oro_recibido'] + $totales['total_mat_recibido'];
            $merma_final = round($entregado_total - $recibido_total, 2);

            // Cerrar la orden
            $stmtUpd = $this->db->prepare("UPDATE Trazabilidad SET estado = 'cerrada', merma = ? WHERE consecutivo = ?");
            try {
                $stmtUpd->execute([$merma_final, $consecutivo]);
            } catch (PDOException $eUpd) {
                // Si la BD existente tiene un ENUM antiguo sin 'cerrada', lo ampliamos y reintentamos una vez
                $msg = $eUpd->getMessage();
                if (stripos($msg, 'enum') !== false || stripos($msg, 'Data truncated') !== false || strpos($msg, '01000') !== false) {
                    Logger::info('ENUM estado desactualizado detectado, intentando migración', ['exception' => $msg]);
                    $this->repararEnumEstado();
                    $stmtUpd->execute([$merma_final, $consecutivo]);
                } else {
                    throw $eUpd;
                }
            }

            http_response_code(200);
            echo json_encode([
                'success' => true,
                'message' => 'Orden cerrada exitosamente.',
                'data' => [
                    'consecutivo' => $consecutivo,
                    'total_entregado' => $entregado_total,
                    'total_recibido' => $recibido_total,
                    'merma' => $merma_final,
                    'estado' => 'cerrada'
                ]
            ], JSON_UNESCAPED_UNICODE);
        } catch (PDOException $e) {
            Logger::error('Error en cerrarOrden', ['exception' => $e->getMessage()]);
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Error al cerrar la orden: ' . $e->getMessage()]);
        }
    }

    /**
     * Lista todas las ordenes con totales calculados y paginación.
     * GET /api/ordenes
     */
    public function index() {
        header('Content-Type: application/json; charset=utf-8');
        if (!$this->requiereSesion()) { return; }
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
                (COALESCE((SELECT SUM(peso_oro) FROM Movimientos WHERE consecutivo = t.consecutivo AND tipo = 'entrega'), 0)
                + COALESCE((SELECT SUM(peso_materiales) FROM Movimientos WHERE consecutivo = t.consecutivo AND tipo = 'entrega'), 0))
                - (COALESCE((SELECT SUM(peso_oro) FROM Movimientos WHERE consecutivo = t.consecutivo AND tipo = 'recibido'), 0)
                + COALESCE((SELECT SUM(peso_materiales) FROM Movimientos WHERE consecutivo = t.consecutivo AND tipo = 'recibido'), 0)) AS saldo_total,
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
        if (!$this->requiereSesion()) { return; }
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
                t.creado_por,
                p.nombre AS proceso_nombre,
                prod.nombre AS producto_nombre,
                r.nombre AS responsable_nombre,
                COALESCE((SELECT SUM(peso_oro) FROM Movimientos WHERE consecutivo = t.consecutivo AND tipo = 'entrega'), 0) AS total_oro_entregado,
                COALESCE((SELECT SUM(peso_materiales) FROM Movimientos WHERE consecutivo = t.consecutivo AND tipo = 'entrega'), 0) AS total_mat_entregado,
                COALESCE((SELECT SUM(peso_oro) FROM Movimientos WHERE consecutivo = t.consecutivo AND tipo = 'recibido'), 0) AS total_oro_recibido,
                COALESCE((SELECT SUM(peso_materiales) FROM Movimientos WHERE consecutivo = t.consecutivo AND tipo = 'recibido'), 0) AS total_mat_recibido,
                COALESCE((SELECT SUM(peso_oro) FROM Movimientos WHERE consecutivo = t.consecutivo AND tipo = 'entrega'), 0)
                - COALESCE((SELECT SUM(peso_oro) FROM Movimientos WHERE consecutivo = t.consecutivo AND tipo = 'recibido'), 0) AS saldo_oro,
                (COALESCE((SELECT SUM(peso_oro) FROM Movimientos WHERE consecutivo = t.consecutivo AND tipo = 'entrega'), 0)
                + COALESCE((SELECT SUM(peso_materiales) FROM Movimientos WHERE consecutivo = t.consecutivo AND tipo = 'entrega'), 0))
                - (COALESCE((SELECT SUM(peso_oro) FROM Movimientos WHERE consecutivo = t.consecutivo AND tipo = 'recibido'), 0)
                + COALESCE((SELECT SUM(peso_materiales) FROM Movimientos WHERE consecutivo = t.consecutivo AND tipo = 'recibido'), 0)) AS saldo_total,
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

            // Resumen de materiales por tipo de movimiento (para la tabla del detalle)
            $stmtMat = $this->db->prepare("SELECT m.nombre, tm.movimiento, SUM(tm.peso) AS peso_total
                FROM TrazabilidadMateriales tm
                JOIN Materiales m ON m.id = tm.material_id
                WHERE tm.consecutivo = ? AND tm.peso > 0
                GROUP BY m.nombre, tm.movimiento
                ORDER BY m.nombre ASC");
            $stmtMat->execute([$consecutivo]);
            $materialesResumen = [];
            foreach ($stmtMat->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $nombre = $r['nombre'];
                if (!isset($materialesResumen[$nombre])) {
                    $materialesResumen[$nombre] = ['nombre' => $nombre, 'entrega' => 0.0, 'recibido' => 0.0];
                }
                $materialesResumen[$nombre][$r['movimiento']] = round((float)$r['peso_total'], 2);
            }
            $orden['materiales_resumen'] = array_values($materialesResumen);

            // Productos anexados a la orden (Fase 2)
            $stmtProd = $this->db->prepare("SELECT
                    op.id,
                    op.producto_id,
                    p.nombre AS producto,
                    op.cantidad,
                    op.peso_oro,
                    op.peso_materiales,
                    op.observaciones,
                    op.fotos_path,
                    op.created_at
                FROM OrdenProductos op
                LEFT JOIN Productos p ON p.id = op.producto_id
                WHERE op.consecutivo = ?
                ORDER BY op.id ASC");
            $stmtProd->execute([$consecutivo]);
            $productos = $stmtProd->fetchAll(PDO::FETCH_ASSOC);
            foreach ($productos as &$prod) {
                $prod['id'] = (int)$prod['id'];
                $prod['producto_id'] = (int)$prod['producto_id'];
                $prod['cantidad'] = (int)$prod['cantidad'];
                $prod['peso_oro'] = (float)$prod['peso_oro'];
                $prod['peso_materiales'] = (float)$prod['peso_materiales'];
                $prod['fotos'] = [];
                if (!empty($prod['fotos_path'])) {
                    $arr = json_decode($prod['fotos_path'], true);
                    if (is_array($arr)) {
                        $prod['fotos'] = $arr;
                    }
                }
                unset($prod['fotos_path']);
            }
            $orden['productos'] = $productos;

            // Flag para el frontend: mostrar/ocultar el botón de anexar producto
            $orden['puede_anexar'] = $this->puedeAnexarProducto($orden);
            unset($orden['creado_por']);

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
        if (!$this->requiereSesion()) { return; }
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
        if (!$this->requiereSesion()) { return; }
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
        if (!$this->requiereSesion()) { return; }
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
        if (!$this->requiereSesion()) { return; }

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
        if (!$this->requiereSesion()) { return; }
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
     * FASE 2/3: Productos anexados + Edición de movimientos
     * =========================================================
     */

    /**
     * Convierte un valor de peso a float, aceptando coma como separador decimal.
     * Devuelve $default si el valor es nulo/vacío/no numérico.
     */
    private function parsePeso($valor, $default = 0.0) {
        if ($valor === null || $valor === '') {
            return $default;
        }
        $limpio = str_replace(',', '.', (string)$valor);
        return is_numeric($limpio) ? (float)$limpio : $default;
    }

    /**
     * true si el usuario de la sesión es admin o es el creador de la orden.
     * Trazabilidad.creado_por guarda el user_id (int) de la sesión al crear la orden;
     * si está vacío/0 (órdenes antiguas), solo el admin puede gestionarla.
     */
    private function usuarioEsCreadorOAdmin($orden) {
        if (SessionManager::get('is_admin')) {
            return true;
        }
        $userId = (int)(SessionManager::get('user_id') ?? 0);
        if ($userId <= 0) {
            return false;
        }
        $creadoPor = (int)($orden['creado_por'] ?? 0);
        return $creadoPor > 0 && $creadoPor === $userId;
    }

    /**
     * true si el usuario puede anexar productos a la orden:
     * admin o creador, y la orden está pendiente o parcial.
     */
    private function puedeAnexarProducto($orden) {
        $estado = $orden['estado'] ?? '';
        if (!in_array($estado, ['pendiente', 'parcial'], true)) {
            return false;
        }
        return $this->usuarioEsCreadorOAdmin($orden);
    }

    /**
     * Recalcula totales/merma/estado de una orden con la lógica de Fase 2.
     * No pisa estados terminales manuales ('cerrada','cancelado'): en ese caso
     * solo actualiza la merma para no cambiar el comportamiento existente.
     */
    private function recalcularOrden($consecutivo) {
        $totales = $this->calcularTotalesOrden($consecutivo);
        $entregado_total = $totales['total_oro_entregado'] + $totales['total_mat_entregado'];
        $recibido_total = $totales['total_oro_recibido'] + $totales['total_mat_recibido'];
        $merma = $totales['saldo_total'];
        $nuevo_estado = $this->determinarEstado($entregado_total, $recibido_total);

        $stmtEst = $this->db->prepare("SELECT estado FROM Trazabilidad WHERE consecutivo = ?");
        $stmtEst->execute([$consecutivo]);
        $estadoActual = (string)$stmtEst->fetchColumn();

        if (in_array($estadoActual, ['cerrada', 'cancelado'], true)) {
            $stmtUpd = $this->db->prepare("UPDATE Trazabilidad SET merma = ? WHERE consecutivo = ?");
            $stmtUpd->execute([$merma, $consecutivo]);
        } else {
            $stmtUpd = $this->db->prepare("UPDATE Trazabilidad SET estado = ?, merma = ? WHERE consecutivo = ?");
            $stmtUpd->execute([$nuevo_estado, $merma, $consecutivo]);
        }

        return [
            'total_entregado' => $entregado_total,
            'total_recibido' => $recibido_total,
            'saldo' => $totales['saldo_total'],
            'merma' => $merma,
            'estado' => $estadoActual
        ];
    }

    /**
     * Aplica pesos/observaciones a un movimiento y recalcula la orden asociada.
     * Procedimiento reutilizado por la edición directa (admin) y por aprobación.
     */
    private function aplicarCambioMovimiento($movimientoId, $pesoOro, $pesoMateriales, $observaciones) {
        $peso = round($pesoOro + $pesoMateriales, 2);
        $stmt = $this->db->prepare("UPDATE Movimientos SET peso_oro = ?, peso_materiales = ?, peso = ?, observaciones = ? WHERE id = ?");
        $stmt->execute([$pesoOro, $pesoMateriales, $peso, $observaciones, $movimientoId]);

        $stmtCon = $this->db->prepare("SELECT consecutivo FROM Movimientos WHERE id = ?");
        $stmtCon->execute([$movimientoId]);
        $consecutivo = (int)$stmtCon->fetchColumn();
        if ($consecutivo > 0) {
            $this->recalcularOrden($consecutivo);
        }
        return $consecutivo;
    }

    /**
     * Nombre del usuario de la sesión para auditoría (con respaldo del id).
     */
    private function nombreUsuarioSesion() {
        $nombre = trim((string)(SessionManager::get('user_nombre') ?? ''));
        if ($nombre !== '') {
            return $nombre;
        }
        return 'ID ' . (int)(SessionManager::get('user_id') ?? 0);
    }

    /**
     * Anexa un producto a una orden (multipart/form-data, estilo registerMovimiento).
     * POST /api/ordenes/{consecutivo}/productos
     * Solo el admin o quien creó la orden; la orden debe estar pendiente o parcial.
     * Si trae pesos > 0, además crea un Movimiento tipo 'entrega' y recalcula totales.
     */
    public function anexarProducto($data) {
        header('Content-Type: application/json; charset=utf-8');
        if (!$this->requiereSesion()) { return; }
        Logger::info('Iniciando anexarProducto', ['POST_DATA' => $_POST, 'FILES_DATA' => $_FILES]);

        $consecutivo = isset($data['consecutivo']) ? (int)$data['consecutivo'] : 0;
        if ($consecutivo <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'El consecutivo es obligatorio.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        // El router ya mezcla $_POST y el body JSON en $data
        $input = !empty($data) ? $data : (json_decode(file_get_contents('php://input'), true) ?? []);

        $productoId = isset($input['producto_id']) ? (int)$input['producto_id'] : 0;
        if ($productoId <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Campo obligatorio faltante: producto_id'], JSON_UNESCAPED_UNICODE);
            return;
        }
        $cantidad = (isset($input['cantidad']) && is_numeric($input['cantidad'])) ? (int)$input['cantidad'] : 1;
        if ($cantidad < 1) {
            $cantidad = 1;
        }
        $pesoOro = $this->parsePeso($input['peso_oro'] ?? null, 0.0);
        $pesoMateriales = $this->parsePeso($input['peso_materiales'] ?? null, 0.0);
        $observaciones = trim((string)($input['observaciones'] ?? ''));

        try {
            // Verificar que la orden existe, su estado y permiso del usuario
            $stmtCheck = $this->db->prepare("SELECT consecutivo, estado, creado_por FROM Trazabilidad WHERE consecutivo = ?");
            $stmtCheck->execute([$consecutivo]);
            $orden = $stmtCheck->fetch(PDO::FETCH_ASSOC);

            if (!$orden) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Orden no encontrada.'], JSON_UNESCAPED_UNICODE);
                return;
            }
            if (!in_array($orden['estado'], ['pendiente', 'parcial'], true)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'La orden no permite anexar productos en su estado actual (' . $orden['estado'] . ').'], JSON_UNESCAPED_UNICODE);
                return;
            }
            if (!$this->usuarioEsCreadorOAdmin($orden)) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Acceso denegado. Solo el administrador o quien creó la orden puede anexar productos.'], JSON_UNESCAPED_UNICODE);
                return;
            }

            // Verificar que el producto existe
            $stmtProd = $this->db->prepare("SELECT nombre FROM Productos WHERE id = ?");
            $stmtProd->execute([$productoId]);
            $nombreProducto = $stmtProd->fetchColumn();
            if (!$nombreProducto) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'El producto indicado no existe.'], JSON_UNESCAPED_UNICODE);
                return;
            }

            // Subir fotos opcionales (misma lógica que los movimientos)
            $fotos = $this->uploadMultipleFiles($consecutivo, 'foto');
            if ($fotos === null || $fotos === false) {
                $fotos = $this->uploadMultipleFiles($consecutivo, 'foto_producto');
            }
            if ($fotos === false) {
                Logger::error('Error grave al procesar las fotos del producto anexado.', ['consecutivo' => $consecutivo]);
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'Error grave al procesar las fotos.'], JSON_UNESCAPED_UNICODE);
                return;
            }
            // Fotos opcionales - continuar sin foto si no se subió ninguna
            if ($fotos === null) {
                $fotos = null;
            }

            $creadoPor = $this->nombreUsuarioSesion();
            $userId = (int)(SessionManager::get('user_id') ?? 0);

            $this->db->beginTransaction();

            // Insertar la línea en OrdenProductos
            $stmtIns = $this->db->prepare("INSERT INTO OrdenProductos (
                consecutivo, producto_id, cantidad, peso_oro, peso_materiales, observaciones, fotos_path, creado_por
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmtIns->execute([
                $consecutivo, $productoId, $cantidad, $pesoOro, $pesoMateriales,
                ($observaciones !== '' ? $observaciones : null), $fotos, $creadoPor
            ]);
            $idOrdenProducto = (int)$this->db->lastInsertId();

            // Si hay pesos, crear movimiento de entrega y recalcular totales/merma/estado
            if ($pesoOro > 0 || $pesoMateriales > 0) {
                $obsMov = 'Anexo de producto: ' . $nombreProducto;
                if ($observaciones !== '') {
                    $obsMov .= "\n" . $observaciones;
                }
                $peso = round($pesoOro + $pesoMateriales, 2);
                $stmtMov = $this->db->prepare("INSERT INTO Movimientos (
                    consecutivo, tipo, peso, peso_oro, peso_materiales, fecha, fotos_path, observaciones, registrado_por
                ) VALUES (?, 'entrega', ?, ?, ?, NOW(), ?, ?, ?)");
                $stmtMov->execute([$consecutivo, $peso, $pesoOro, $pesoMateriales, $fotos, $obsMov, $userId]);

                $this->recalcularOrden($consecutivo);
            }

            $this->db->commit();

            Logger::info('Producto anexado a la orden', ['consecutivo' => $consecutivo, 'producto_id' => $productoId, 'id' => $idOrdenProducto]);
            http_response_code(200);
            echo json_encode([
                'success' => true,
                'message' => 'Producto anexado correctamente.',
                'data' => [
                    'id' => $idOrdenProducto,
                    'consecutivo' => $consecutivo
                ]
            ], JSON_UNESCAPED_UNICODE);
        } catch (PDOException $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            Logger::error('Error de DB en anexarProducto', ['exception' => $e->getMessage()]);
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Error de base de datos al anexar el producto: ' . $e->getMessage()]);
        }
    }

    /**
     * Solicita la edición de un movimiento (operator) o la aplica directo (admin).
     * POST /api/movimientos/{id}/solicitar-edicion
     * JSON: {peso_oro, peso_materiales, observaciones, motivo}
     */
    public function solicitarEdicionMovimiento($data) {
        header('Content-Type: application/json; charset=utf-8');
        if (!$this->requiereSesion()) { return; }
        Logger::info('Iniciando solicitarEdicionMovimiento', ['data' => $data]);

        $movimientoId = isset($data['id']) ? (int)$data['id'] : 0;
        if ($movimientoId <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'El id del movimiento es obligatorio.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        try {
            $stmt = $this->db->prepare("SELECT id, consecutivo, tipo, peso_oro, peso_materiales, observaciones FROM Movimientos WHERE id = ?");
            $stmt->execute([$movimientoId]);
            $mov = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$mov) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Movimiento no encontrado.'], JSON_UNESCAPED_UNICODE);
                return;
            }

            // La orden asociada no debe estar cancelada
            $stmtOrd = $this->db->prepare("SELECT consecutivo, estado FROM Trazabilidad WHERE consecutivo = ?");
            $stmtOrd->execute([(int)$mov['consecutivo']]);
            $orden = $stmtOrd->fetch(PDO::FETCH_ASSOC);
            if (!$orden) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Orden no encontrada.'], JSON_UNESCAPED_UNICODE);
                return;
            }
            if ($orden['estado'] === 'cancelado') {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'La orden está cancelada. No se pueden editar sus movimientos.'], JSON_UNESCAPED_UNICODE);
                return;
            }

            // Resolver valores propuestos (los no enviados quedan con el valor actual)
            $pesoOroActual = (float)$mov['peso_oro'];
            $pesoMatActual = (float)$mov['peso_materiales'];
            $obsActual = (string)($mov['observaciones'] ?? '');

            $hayCambio = false;
            $propuestos = [];
            if (array_key_exists('peso_oro', $data)) {
                $propuestos['peso_oro'] = $this->parsePeso($data['peso_oro'], $pesoOroActual);
                if (abs($propuestos['peso_oro'] - $pesoOroActual) > 0.0001) { $hayCambio = true; }
            }
            if (array_key_exists('peso_materiales', $data)) {
                $propuestos['peso_materiales'] = $this->parsePeso($data['peso_materiales'], $pesoMatActual);
                if (abs($propuestos['peso_materiales'] - $pesoMatActual) > 0.0001) { $hayCambio = true; }
            }
            if (array_key_exists('observaciones', $data)) {
                $propuestos['observaciones'] = trim((string)$data['observaciones']);
                if ($propuestos['observaciones'] !== $obsActual) { $hayCambio = true; }
            }

            if (!$hayCambio) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'No se detectaron cambios respecto a los valores actuales.'], JSON_UNESCAPED_UNICODE);
                return;
            }

            // Rellenar los campos no enviados con los valores actuales del movimiento
            if (!array_key_exists('peso_oro', $propuestos)) { $propuestos['peso_oro'] = $pesoOroActual; }
            if (!array_key_exists('peso_materiales', $propuestos)) { $propuestos['peso_materiales'] = $pesoMatActual; }
            if (!array_key_exists('observaciones', $propuestos)) { $propuestos['observaciones'] = $obsActual; }

            $esAdmin = (bool)SessionManager::get('is_admin');

            if ($esAdmin) {
                // Admin: aplicar el cambio directo y recalcular la orden
                $this->db->beginTransaction();
                $this->aplicarCambioMovimiento(
                    $movimientoId,
                    (float)$propuestos['peso_oro'],
                    (float)$propuestos['peso_materiales'],
                    (string)$propuestos['observaciones']
                );
                $this->db->commit();

                Logger::info('Edición de movimiento aplicada directamente (admin)', ['movimiento_id' => $movimientoId, 'consecutivo' => (int)$mov['consecutivo']]);
                http_response_code(200);
                echo json_encode([
                    'success' => true,
                    'aplicado_directo' => true,
                    'message' => 'Cambio aplicado directamente.',
                    'data' => [
                        'movimiento_id' => $movimientoId,
                        'consecutivo' => (int)$mov['consecutivo']
                    ]
                ], JSON_UNESCAPED_UNICODE);
                return;
            }

            // Operador: crear solicitud pendiente de aprobación del admin
            $motivo = isset($data['motivo']) ? trim((string)$data['motivo']) : null;
            $stmtIns = $this->db->prepare("INSERT INTO SolicitudEdicion (
                movimiento_id, consecutivo, valores_propuestos, motivo, estado, solicitado_por
            ) VALUES (?, ?, ?, ?, 'pendiente', ?)");
            $stmtIns->execute([
                $movimientoId,
                (int)$mov['consecutivo'],
                json_encode($propuestos, JSON_UNESCAPED_UNICODE),
                ($motivo !== '' ? $motivo : null),
                $this->nombreUsuarioSesion()
            ]);
            $solicitudId = (int)$this->db->lastInsertId();

            Logger::info('Solicitud de edición creada', ['solicitud_id' => $solicitudId, 'movimiento_id' => $movimientoId]);
            http_response_code(200);
            echo json_encode([
                'success' => true,
                'aplicado_directo' => false,
                'solicitud_id' => $solicitudId,
                'message' => 'Solicitud enviada. Queda pendiente de aprobación del administrador.'
            ], JSON_UNESCAPED_UNICODE);
        } catch (PDOException $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            Logger::error('Error de DB en solicitarEdicionMovimiento', ['exception' => $e->getMessage()]);
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Error de base de datos al procesar la solicitud: ' . $e->getMessage()]);
        }
    }

    /**
     * Lista las solicitudes de edición pendientes (solo admin), con los valores
     * actuales del movimiento para comparación.
     * GET /api/ediciones/pendientes
     */
    public function getEdicionesPendientes() {
        header('Content-Type: application/json; charset=utf-8');
        if (!$this->requiereSesion()) { return; }
        if (!SessionManager::get('is_admin')) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Acceso no autorizado.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        try {
            $sql = "SELECT
                    s.id,
                    s.movimiento_id,
                    s.consecutivo,
                    s.valores_propuestos,
                    s.motivo,
                    s.estado,
                    s.solicitado_por,
                    s.fecha_solicitud,
                    m.tipo,
                    m.fecha,
                    m.peso AS actual_peso,
                    m.peso_oro AS actual_peso_oro,
                    m.peso_materiales AS actual_peso_materiales,
                    m.observaciones AS actual_observaciones
                FROM SolicitudEdicion s
                JOIN Movimientos m ON m.id = s.movimiento_id
                WHERE s.estado = 'pendiente'
                ORDER BY s.fecha_solicitud ASC, s.id ASC";
            $stmt = $this->db->query($sql);
            $solicitudes = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $propuestos = json_decode($row['valores_propuestos'] ?? '[]', true);
                $row['valores_propuestos'] = is_array($propuestos) ? $propuestos : [];
                $row['id'] = (int)$row['id'];
                $row['movimiento_id'] = (int)$row['movimiento_id'];
                $row['consecutivo'] = (int)$row['consecutivo'];
                $row['actual_peso'] = (float)$row['actual_peso'];
                $row['actual_peso_oro'] = (float)$row['actual_peso_oro'];
                $row['actual_peso_materiales'] = (float)$row['actual_peso_materiales'];
                $solicitudes[] = $row;
            }

            http_response_code(200);
            echo json_encode(['success' => true, 'data' => $solicitudes], JSON_UNESCAPED_UNICODE);
        } catch (PDOException $e) {
            Logger::error('Error en getEdicionesPendientes', ['exception' => $e->getMessage()]);
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Error al obtener las solicitudes: ' . $e->getMessage()]);
        }
    }

    /**
     * Aprueba una solicitud de edición y aplica los valores al movimiento (solo admin).
     * POST /api/ediciones/{id}/aprobar
     */
    public function aprobarEdicion($data) {
        header('Content-Type: application/json; charset=utf-8');
        if (!$this->requiereSesion()) { return; }
        if (!SessionManager::get('is_admin')) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Acceso no autorizado.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        $id = isset($data['id']) ? (int)$data['id'] : 0;
        if ($id <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'El id de la solicitud es obligatorio.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        try {
            $stmt = $this->db->prepare("SELECT id, movimiento_id, consecutivo, valores_propuestos, estado FROM SolicitudEdicion WHERE id = ?");
            $stmt->execute([$id]);
            $sol = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$sol) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Solicitud no encontrada.'], JSON_UNESCAPED_UNICODE);
                return;
            }
            if ($sol['estado'] !== 'pendiente') {
                http_response_code(409);
                echo json_encode(['success' => false, 'message' => 'La solicitud ya fue revisada.'], JSON_UNESCAPED_UNICODE);
                return;
            }

            $propuestos = json_decode($sol['valores_propuestos'] ?? '[]', true);
            if (!is_array($propuestos)) {
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'Los valores propuestos de la solicitud están corruptos.'], JSON_UNESCAPED_UNICODE);
                return;
            }

            $this->db->beginTransaction();
            $this->aplicarCambioMovimiento(
                (int)$sol['movimiento_id'],
                (float)($propuestos['peso_oro'] ?? 0),
                (float)($propuestos['peso_materiales'] ?? 0),
                (string)($propuestos['observaciones'] ?? '')
            );

            $stmtUpd = $this->db->prepare("UPDATE SolicitudEdicion SET estado = 'aprobada', revisado_por = ?, fecha_revision = NOW() WHERE id = ?");
            $stmtUpd->execute([$this->nombreUsuarioSesion(), $id]);
            $this->db->commit();

            $consecutivo = (int)$sol['consecutivo'];
            Logger::info('Solicitud de edición aprobada', ['solicitud_id' => $id, 'movimiento_id' => (int)$sol['movimiento_id']]);
            http_response_code(200);
            echo json_encode([
                'success' => true,
                'message' => 'Cambio aplicado a la orden #' . str_pad($consecutivo, 4, '0', STR_PAD_LEFT) . '.'
            ], JSON_UNESCAPED_UNICODE);
        } catch (PDOException $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            Logger::error('Error de DB en aprobarEdicion', ['exception' => $e->getMessage()]);
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Error de base de datos al aprobar la solicitud: ' . $e->getMessage()]);
        }
    }

    /**
     * Rechaza una solicitud de edición (solo admin).
     * POST /api/ediciones/{id}/rechazar
     * JSON opcional: {motivo}
     */
    public function rechazarEdicion($data) {
        header('Content-Type: application/json; charset=utf-8');
        if (!$this->requiereSesion()) { return; }
        if (!SessionManager::get('is_admin')) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Acceso no autorizado.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        $id = isset($data['id']) ? (int)$data['id'] : 0;
        if ($id <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'El id de la solicitud es obligatorio.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        try {
            $stmt = $this->db->prepare("SELECT id, estado, motivo FROM SolicitudEdicion WHERE id = ?");
            $stmt->execute([$id]);
            $sol = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$sol) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Solicitud no encontrada.'], JSON_UNESCAPED_UNICODE);
                return;
            }
            if ($sol['estado'] !== 'pendiente') {
                http_response_code(409);
                echo json_encode(['success' => false, 'message' => 'La solicitud ya fue revisada.'], JSON_UNESCAPED_UNICODE);
                return;
            }

            // Si llegó motivo de rechazo, se anexa al motivo original para auditoría
            $motivo = (string)($sol['motivo'] ?? '');
            if (isset($data['motivo']) && trim((string)$data['motivo']) !== '') {
                $motivo = ($motivo !== '' ? $motivo . ' | ' : '') . 'Rechazo: ' . trim((string)$data['motivo']);
            }

            $stmtUpd = $this->db->prepare("UPDATE SolicitudEdicion SET estado = 'rechazada', motivo = ?, revisado_por = ?, fecha_revision = NOW() WHERE id = ?");
            $stmtUpd->execute([($motivo !== '' ? $motivo : null), $this->nombreUsuarioSesion(), $id]);

            Logger::info('Solicitud de edición rechazada', ['solicitud_id' => $id]);
            http_response_code(200);
            echo json_encode([
                'success' => true,
                'message' => 'Solicitud rechazada.'
            ], JSON_UNESCAPED_UNICODE);
        } catch (PDOException $e) {
            Logger::error('Error de DB en rechazarEdicion', ['exception' => $e->getMessage()]);
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Error de base de datos al rechazar la solicitud: ' . $e->getMessage()]);
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

    /**
     * Elimina una orden completa (solo administrador).
     * Borra movimientos y materiales asociados, e intenta eliminar los archivos de foto.
     * DELETE /api/ordenes/{consecutivo}
     */
    public function delete($data) {
        header('Content-Type: application/json; charset=utf-8');

        // Solo admin
        if (class_exists('SessionManager')) {
            $rol = SessionManager::get('user_rol') ?? 'operador';
            if ($rol !== 'admin') {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Acceso denegado. Solo administradores pueden eliminar órdenes.'], JSON_UNESCAPED_UNICODE);
                return;
            }
        }

        $consecutivo = isset($data['consecutivo']) ? (int)$data['consecutivo'] : 0;
        if ($consecutivo <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Consecutivo inválido.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        try {
            // Verificar que la orden existe y reunir rutas de fotos para limpieza
            $fotoPaths = [];
            $stmt = $this->db->prepare("SELECT foto_entrega_path, foto_recibido_path FROM Trazabilidad WHERE consecutivo = ?");
            $stmt->execute([$consecutivo]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => "Orden #$consecutivo no encontrada."], JSON_UNESCAPED_UNICODE);
                return;
            }
            foreach (['foto_entrega_path', 'foto_recibido_path'] as $col) {
                $decoded = json_decode($row[$col] ?? '[]', true);
                if (is_array($decoded)) $fotoPaths = array_merge($fotoPaths, $decoded);
            }
            $stmt = $this->db->prepare("SELECT fotos_path FROM Movimientos WHERE consecutivo = ?");
            $stmt->execute([$consecutivo]);
            while ($m = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $decoded = json_decode($m['fotos_path'] ?? '[]', true);
                if (is_array($decoded)) $fotoPaths = array_merge($fotoPaths, $decoded);
            }
            $stmt = $this->db->prepare("SELECT fotos_path FROM TrazabilidadMateriales WHERE consecutivo = ?");
            $stmt->execute([$consecutivo]);
            while ($m = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $decoded = json_decode($m['fotos_path'] ?? '[]', true);
                if (is_array($decoded)) $fotoPaths = array_merge($fotoPaths, $decoded);
            }

            // Borrar movimientos y materiales explícitamente (por si las FK sin cascada)
            $this->db->prepare("DELETE FROM Movimientos WHERE consecutivo = ?")->execute([$consecutivo]);
            $this->db->prepare("DELETE FROM TrazabilidadMateriales WHERE consecutivo = ?")->execute([$consecutivo]);

            // Borrar la orden
            $del = $this->db->prepare("DELETE FROM Trazabilidad WHERE consecutivo = ?");
            $del->execute([$consecutivo]);
            if ($del->rowCount() === 0) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => "Orden #$consecutivo no encontrada."], JSON_UNESCAPED_UNICODE);
                return;
            }

            // Limpiar archivos de foto (best effort)
            $root = realpath(__DIR__ . '/../../');
            $removed = 0;
            if ($root) {
                foreach ($fotoPaths as $p) {
                    if (!is_string($p) || $p === '') continue;
                    $full = $root . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, ltrim($p, '/\\'));
                    if (is_file($full) && @unlink($full)) $removed++;
                }
                $dir = $root . DIRECTORY_SEPARATOR . 'frontend' . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'img' . DIRECTORY_SEPARATOR . $consecutivo;
                if (is_dir($dir) && count(glob($dir . '/*') ?: []) === 0) @rmdir($dir);
            }

            echo json_encode([
                'success' => true,
                'message' => "Orden #" . str_pad($consecutivo, 4, '0', STR_PAD_LEFT) . " eliminada correctamente." . ($removed > 0 ? " ($removed foto(s) borrada(s))" : '')
            ], JSON_UNESCAPED_UNICODE);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Error al eliminar la orden: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }
}
