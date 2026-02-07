<?php
/**
 * Clase TrazabilidadController
 * Maneja las operaciones de Trazabilidad (Entrega, Recibido, Consecutivos, etc.)
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
     * Obtiene todos los registros de trazabilidad con nombres relacionados (GET /api/trazabilidad).
     */
    public function index() {
        try {
            $sql = "
                SELECT 
                    t.consecutivo, 
                    t.fecha_entrega,
                    t.fecha_recibido,
                    t.proceso_id,
                    t.peso_entregado   AS peso_entregado_gr, 
                    t.peso_ley         AS peso_ley_gr,
                    t.peso_recibido    AS peso_recibido_gr,
                    t.merma            AS merma_gr,
                    t.foto_entrega_path,
                    t.foto_recibido_path,
                    t.observaciones    AS observaciones_entrega,
                    t.observaciones    AS observaciones_recibido,
                    p.nombre as proceso_nombre, 
                    r.nombre as responsable_nombre, 
                    prod.nombre as producto_nombre
                FROM Trazabilidad t
                LEFT JOIN Procesos p ON t.proceso_id = p.id
                LEFT JOIN Responsables r ON t.responsable_id = r.id
                LEFT JOIN Productos prod ON t.producto_id = prod.id
                ORDER BY t.consecutivo DESC";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute();
            $registros = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($registros as &$row) {
                $obs = $row['observaciones_entrega'] ?? '';
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
                $row['obs_entrega_pura'] = $obsEntrega;
                $row['obs_recibido_pura'] = $obsRecibido;
            }

            http_response_code(200);
            echo json_encode(['success' => true, 'data' => $registros]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => "Error al obtener la lista de trazabilidad: " . $e->getMessage()]);
        }
    }

    /**
     * Obtiene el siguiente número de consecutivo (GET /api/trazabilidad/consecutivo).
     */
    public function getNextConsecutivo() {
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
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => "Error al obtener consecutivo: " . $e->getMessage()]);
        }
    }

    /**
     * Busca un registro de Entrega pendiente por Consecutivo (GET /api/trazabilidad/{consecutivo}).
     */
    public function getByConsecutivo($data) {
        $consecutivo = $data['consecutivo'] ?? null;
        if ($consecutivo !== null) {
            $consecutivo = (int)$consecutivo;
        }

        if (empty($consecutivo)) {
            http_response_code(200);
            echo json_encode(['success' => false, 'message' => 'El consecutivo es obligatorio para la búsqueda.']);
            return;
        }

        try {
            $sql = "
                SELECT 
                    t.consecutivo AS id,
                    t.consecutivo, 
                    t.fecha_entrega,
                    t.proceso_id, 
                    t.responsable_id, 
                    t.producto_id,
                    t.peso_entregado AS peso_entregado_gr, 
                    p.nombre as proceso_nombre, 
                    r.nombre as responsable_nombre, 
                    prod.nombre as producto_nombre,
                    t.observaciones AS observaciones_entrega
                FROM Trazabilidad t
                LEFT JOIN Procesos p ON t.proceso_id = p.id
                LEFT JOIN Responsables r ON t.responsable_id = r.id
                LEFT JOIN Productos prod ON t.producto_id = prod.id
                WHERE t.consecutivo = ? AND t.fecha_recibido IS NULL AND t.proceso_id > 4
            ";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$consecutivo]);
            $registro = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($registro && isset($registro['observaciones_entrega'])) {
                $obs = $registro['observaciones_entrega'] ?? '';
                $obsEntrega = '';
                $obsRecibido = '';
                foreach (preg_split("/\r?\n/", $obs) as $line) {
                    $lineTrim = trim($line);
                    if (stripos($lineTrim, 'ENTREGA:') === 0) {
                        $obsEntrega = trim(substr($lineTrim, 8));
                    } elseif (stripos($lineTrim, 'RECIBIDO:') === 0) {
                        $obsRecibido = trim(substr($lineTrim, 9));
                    }
                }
                $registro['obs_entrega_pura'] = $obsEntrega;
                $registro['obs_recibido_pura'] = $obsRecibido;
            }

            if ($registro) {
                http_response_code(200);
                echo json_encode(['success' => true, 'data' => $registro]);
            } else {
                http_response_code(200);
                echo json_encode(['success' => false, 'message' => 'Consecutivo no encontrado o ya fue recibido.']);
            }

        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => "Error al buscar consecutivo: " . $e->getMessage()]);
        }
    }

    /**
     * Función utilitaria para subir múltiples archivos.
     */
    private function uploadMultipleFiles($consecutivo, $file_key) {
        if (!isset($_FILES[$file_key]) || !is_array($_FILES[$file_key]['name'])) {
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

        return !empty($uploaded_paths) ? json_encode($uploaded_paths) : null;
    }

    /**
     * Registra un nuevo movimiento de ENTREGA (POST /api/trazabilidad/entrega).
     */
    public function registerEntrega() {
        Logger::info('Iniciando registerEntrega', ['POST_DATA' => $_POST, 'FILES_DATA' => $_FILES]);

        $required_fields = ['consecutivo', 'fecha_movimiento_hidden', 'proceso_id', 'responsable_id', 'producto_id', 'peso_gr'];
        foreach ($required_fields as $field) {
            if (!isset($_POST[$field]) || empty($_POST[$field])) {
                $error_msg = "Campo obligatorio faltante: {$field}";
                Logger::warn($error_msg, ['request_data' => $_POST]);
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => $error_msg]);
                return;
            }
        }
        
        $consecutivo = $_POST['consecutivo'];
        $observaciones = $_POST['observaciones'] ?? '';
        if ($observaciones !== '') {
            if (stripos($observaciones, 'ENTREGA:') !== 0) {
                $observaciones = 'ENTREGA: ' . $observaciones;
            }
        }

        $proceso_id = (int)($_POST['proceso_id'] ?? 0);
        $peso_entregado_input = isset($_POST['peso_gr']) ? str_replace(',', '.', $_POST['peso_gr']) : '0';
        $peso_entregado = is_numeric($peso_entregado_input) ? (float)$peso_entregado_input : 0.0;

        // Si llegan materiales de entrega, recalcular peso_entregado como suma de pesos
        $materiales_json = $_POST['materiales_json'] ?? '';
        $materiales = [];
        if ($materiales_json) {
            $parsed = json_decode($materiales_json, true);
            if (is_array($parsed)) {
                $materiales = $parsed;
                $sum = 0.0;
                foreach ($materiales as $m) {
                    $w = isset($m['peso']) ? (float)str_replace(',', '.', (string)$m['peso']) : 0.0;
                    $sum += $w;
                }
                $peso_entregado = round($sum, 2);
            }
        }
        
        if ($proceso_id === 1) {
            $peso_ley = round($peso_entregado * -1, 2);
        } else if (in_array($proceso_id, [2, 3, 4])) {
            $peso_ley = round($peso_entregado * 1.39, 2);
        } else if ($proceso_id > 4) {
            $peso_ley = round($peso_entregado, 2);
        } else {
            $peso_ley = 0;
        }

        $procesos_auto_completados = [1, 2, 3, 4];
        $es_auto_completado = in_array($proceso_id, $procesos_auto_completados);
        
        $fecha_recibido = $es_auto_completado ? $_POST['fecha_movimiento_hidden'] : null;
        $merma = $es_auto_completado ? 0 : 0;

        // *** CORRECCIÓN CLAVE ***
        // El input se llama 'foto_entrega[]', pero PHP lo pone en $_FILES['foto_entrega']
        $foto_entrega_path = $this->uploadMultipleFiles($consecutivo, 'foto_entrega');
        
        if ($foto_entrega_path === false) {
             $error_msg = 'Error grave al procesar las fotos de entrega.';
             Logger::error($error_msg, ['consecutivo' => $consecutivo]);
             http_response_code(500);
             echo json_encode(['success' => false, 'message' => $error_msg]);
             return;
        }
        if ($foto_entrega_path === null) {
            $error_msg = 'Es obligatorio subir al menos una foto para la entrega.';
            Logger::warn($error_msg, ['consecutivo' => $consecutivo, 'files' => $_FILES]);
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => $error_msg]);
            return;
        }

        try {
            if ($es_auto_completado) {
                $sql = "INSERT INTO Trazabilidad (
                            consecutivo, fecha_entrega, proceso_id, responsable_id, producto_id, 
                            peso_entregado, peso_ley, foto_entrega_path, observaciones,
                            fecha_recibido, merma
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                
                $stmt = $this->db->prepare($sql);
                $params = [
                    $consecutivo, $_POST['fecha_movimiento_hidden'], $_POST['proceso_id'],
                    $_POST['responsable_id'], $_POST['producto_id'], $peso_entregado, 
                    $peso_ley, $foto_entrega_path, $observaciones, $fecha_recibido, $merma
                ];
                $success = $stmt->execute($params);
            } else {
                $sql = "INSERT INTO Trazabilidad (
                            consecutivo, fecha_entrega, proceso_id, responsable_id, producto_id, 
                            peso_entregado, peso_ley, foto_entrega_path, observaciones, merma
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                
                $stmt = $this->db->prepare($sql);
                $params = [
                    $consecutivo, $_POST['fecha_movimiento_hidden'], $_POST['proceso_id'],
                    $_POST['responsable_id'], $_POST['producto_id'], $peso_entregado, 
                    $peso_ley, $foto_entrega_path, $observaciones, 0
                ];
                $success = $stmt->execute($params);
            }

            if ($success) {
                $status_message = $es_auto_completado ? 
                    'Entrega registrada y completada automáticamente. Consecutivo: ' . $consecutivo :
                    'Entrega registrada con éxito. Consecutivo: ' . $consecutivo;
                
                // Insertar materiales de ENTREGA (detalle)
                if (!empty($materiales)) {
                    $stmtIns = $this->db->prepare("INSERT INTO TrazabilidadMateriales (consecutivo, material_id, movimiento, peso, fotos_path) VALUES (?, ?, 'entrega', ?, ?)");
                    foreach ($materiales as $m) {
                        $mid = (int)($m['material_id'] ?? 0);
                        if ($mid <= 0) continue;
                        $w = isset($m['peso']) ? (float)str_replace(',', '.', (string)$m['peso']) : 0.0;
                        // Subir fotos por material si existen: clave material_fotos_{id}[]
                        $fileKey = 'material_fotos_' . $mid;
                        $paths_json = $this->uploadMultipleFiles($consecutivo, $fileKey);
                        $stmtIns->execute([$consecutivo, $mid, $w, $paths_json]);
                    }
                }

                Logger::info($status_message, ['consecutivo' => $consecutivo, 'params' => $params]);
                http_response_code(201);
                echo json_encode(['success' => true, 'message' => $status_message]);
            } else {
                $error_msg = 'Error al guardar el registro en la base de datos.';
                Logger::error($error_msg, ['sql_error' => $stmt->errorInfo()]);
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => $error_msg]);
            }

        } catch (PDOException $e) {
            $error_msg = "Error de DB al registrar Entrega: " . $e->getMessage();
            Logger::error($error_msg, ['exception' => $e]);
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => $error_msg]);
        }
    }


    /**
     * Registra un movimiento de RECIBIDO (POST /api/trazabilidad/recibido).
     */
    public function registerRecibido() {
        Logger::info('Iniciando registerRecibido', ['POST_DATA' => $_POST, 'FILES_DATA' => $_FILES]);
        
        $required_fields = ['consecutivo', 'fecha_movimiento_hidden', 'peso_gr'];
        foreach ($required_fields as $field) {
            if (!isset($_POST[$field]) || $_POST[$field] === '') {
                $error_msg = "Campo obligatorio faltante para Recibido: {$field}";
                Logger::warn($error_msg, ['request_data' => $_POST]);
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => $error_msg]);
                return;
            }
        }
        
        $consecutivo = $_POST['consecutivo'] ?? '';
        $observacionesRec = $_POST['observaciones'] ?? '';
        $peso_recibido_input = isset($_POST['peso_gr']) ? str_replace(',', '.', $_POST['peso_gr']) : null;
        $peso_recibido = ($peso_recibido_input !== null && is_numeric($peso_recibido_input)) ? (float)$peso_recibido_input : null;

        // Si llegan materiales de recibido, recalcular peso_recibido como suma de pesos
        $materiales_json = $_POST['materiales_json'] ?? '';
        $materiales = [];
        if ($materiales_json) {
            $parsed = json_decode($materiales_json, true);
            if (is_array($parsed)) {
                $materiales = $parsed;
                $sum = 0.0;
                foreach ($materiales as $m) {
                    $w = isset($m['peso']) ? (float)str_replace(',', '.', (string)$m['peso']) : 0.0;
                    $sum += $w;
                }
                $peso_recibido = round($sum, 2);
            }
        }

        // *** CORRECCIÓN CLAVE ***
        // El input se llama 'foto_recibido[]', pero PHP lo pone en $_FILES['foto_recibido']
        $foto_recibido_path = $this->uploadMultipleFiles($consecutivo, 'foto_recibido');
        
        if ($foto_recibido_path === false) {
             http_response_code(500);
             echo json_encode(['success' => false, 'message' => 'Error grave al procesar las fotos de recibido.']);
             return;
        }
        if ($foto_recibido_path === null) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Es obligatorio subir al menos una foto para el recibido.']);
            return;
        }

        try {
            Logger::info("Buscando consecutivo para recibir: {$consecutivo}");
            $stmtSel = $this->db->prepare("SELECT proceso_id, peso_entregado, observaciones FROM Trazabilidad WHERE consecutivo = ? AND fecha_recibido IS NULL AND proceso_id > 4");
            $stmtSel->execute([$consecutivo]);
            $prev = $stmtSel->fetch(PDO::FETCH_ASSOC);

            if (!$prev) {
                Logger::warn("No se encontró registro pendiente para recibir", ['consecutivo' => $consecutivo]);
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Registro no encontrado, ya fue marcado como recibido, o es un proceso que se completa automáticamente.']);
                return;
            }
            
            Logger::info("Registro encontrado para recibir", ['data' => $prev]);

            $proceso_id = (int)$prev['proceso_id'];
            $peso_entregado = isset($prev['peso_entregado']) ? (float)$prev['peso_entregado'] : 0.0;

            $obsPrev = $prev['observaciones'] ?? '';
            $obsNueva = '';
            if ($observacionesRec !== '') {
                $obsNueva = (stripos($observacionesRec, 'RECIBIDO:') === 0) ? $observacionesRec : ('RECIBIDO: ' . $observacionesRec);
            }
            $observacionesFinal = trim($obsPrev . ( ($obsPrev && $obsNueva) ? "\n" : '' ) . $obsNueva);

            $merma = round($peso_entregado - $peso_recibido, 2);

            $sql = "UPDATE Trazabilidad SET
                        fecha_recibido = ?,
                        peso_recibido = ?,
                        foto_recibido_path = COALESCE(?, foto_recibido_path),
                        merma = ?,
                        observaciones = ?
                    WHERE consecutivo = ? AND fecha_recibido IS NULL AND proceso_id > 4";
            
            $stmt = $this->db->prepare($sql);
            $params = [
                $_POST['fecha_movimiento_hidden'], $peso_recibido, $foto_recibido_path, 
                $merma, $observacionesFinal, $consecutivo
            ];
            $success = $stmt->execute($params);

            if ($success && $stmt->rowCount() > 0) {
                // Insertar materiales de RECIBIDO (detalle)
                if (!empty($materiales)) {
                    $stmtIns = $this->db->prepare("INSERT INTO TrazabilidadMateriales (consecutivo, material_id, movimiento, peso, fotos_path) VALUES (?, ?, 'recibido', ?, ?)");
                    foreach ($materiales as $m) {
                        $mid = (int)($m['material_id'] ?? 0);
                        if ($mid <= 0) continue;
                        $w = isset($m['peso']) ? (float)str_replace(',', '.', (string)$m['peso']) : 0.0;
                        $fileKey = 'material_fotos_' . $mid;
                        $paths_json = $this->uploadMultipleFiles($consecutivo, $fileKey);
                        $stmtIns->execute([$consecutivo, $mid, $w, $paths_json]);
                    }
                }
                Logger::info("Recibido registrado con éxito", ['consecutivo' => $consecutivo, 'params' => $params]);
                http_response_code(200);
                echo json_encode(['success' => true, 'message' => 'Recibido registrado y registro actualizado con éxito. Consecutivo: ' . $consecutivo]);
            } else if ($stmt->rowCount() == 0) {
                 Logger::warn("Intento de actualizar registro de recibido, pero no se afectaron filas.", ['consecutivo' => $consecutivo]);
                 http_response_code(404);
                 echo json_encode(['success' => false, 'message' => 'Registro no encontrado, o ya fue marcado como recibido.']);
            } else {
                Logger::error("Error al actualizar el registro de recibido", ['sql_error' => $stmt->errorInfo()]);
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'Error al actualizar el registro en la base de datos.']);
            }

        } catch (PDOException $e) {
            Logger::error("Error de DB al registrar Recibido", ['exception' => $e]);
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => "Error de DB al registrar Recibido: " . $e->getMessage()]);
        }
    }

    /**
     * Obtiene todos los datos de un registro para el formulario de edición.
     * (GET /api/trazabilidad/edit/{consecutivo})
     */
    public function getForEdit($data) {
        // Solo administradores pueden editar
        if (!SessionManager::get('is_admin')) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Acceso no autorizado.']);
            return;
        }

        $consecutivo = $data['consecutivo'] ?? null;
        if (empty($consecutivo)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'El consecutivo es obligatorio.']);
            return;
        }

        try {
            $sql = "
                SELECT 
                    t.consecutivo,
                    t.fecha_entrega,
                    t.fecha_recibido,
                    t.proceso_id,
                    t.responsable_id,
                    t.producto_id,
                    t.peso_entregado   AS peso_entregado_gr,
                    t.peso_ley         AS peso_ley_gr,
                    t.peso_recibido    AS peso_recibido_gr,
                    t.merma            AS merma_gr,
                    t.foto_entrega_path,
                    t.foto_recibido_path,
                    t.observaciones,
                    p.nombre as proceso_nombre, 
                    r.nombre as responsable_nombre, 
                    prod.nombre as producto_nombre
                FROM Trazabilidad t
                LEFT JOIN Procesos p ON t.proceso_id = p.id
                LEFT JOIN Responsables r ON t.responsable_id = r.id
                LEFT JOIN Productos prod ON t.producto_id = prod.id
                WHERE t.consecutivo = ?
            ";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([$consecutivo]);
            $registro = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($registro) {
                // Parsear observaciones para el frontend
                $obs = $registro['observaciones'] ?? '';
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
                echo json_encode(['success' => true, 'data' => $registro]);
            } else {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Registro no encontrado para editar.']);
            }
        } catch (PDOException $e) {
            if (class_exists('Logger')) {
                Logger::error("Error en getForEdit", ['exception' => $e->getMessage()]);
            }
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => "Error de base de datos: " . $e->getMessage()]);
        }
    }

    /**
     * Actualiza un registro de trazabilidad existente.
     * (POST /api/trazabilidad/update)
     */
    public function update() {
        // Respuesta JSON
        header('Content-Type: application/json');

        // Validar permisos
        if (!SessionManager::get('is_admin')) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Acceso no autorizado.']);
            return;
        }

        // Validar consecutivo
        $consecutivo = $_POST['consecutivo'] ?? null;
        if (empty($consecutivo)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'No se proporcionó el consecutivo del registro a actualizar.']);
            return;
        }

        // Manejar nuevas fotos
        $new_entrega_paths_json = $this->uploadMultipleFiles($consecutivo, 'foto_entrega');
        $new_recibido_paths_json = $this->uploadMultipleFiles($consecutivo, 'foto_recibido');

        // Combinar con existentes (desde inputs hidden)
        $existing_entrega_paths = json_decode($_POST['existing_fotos_entrega'] ?? '[]', true);
        $new_entrega_paths = json_decode($new_entrega_paths_json ?? '[]', true);
        $final_entrega_paths = array_merge($existing_entrega_paths, $new_entrega_paths);

        $existing_recibido_paths = json_decode($_POST['existing_fotos_recibido'] ?? '[]', true);
        $new_recibido_paths = json_decode($new_recibido_paths_json ?? '[]', true);
        $final_recibido_paths = array_merge($existing_recibido_paths, $new_recibido_paths);

        // Observaciones
        $obs_entrega = !empty($_POST['obs_entrega']) ? 'ENTREGA: ' . $_POST['obs_entrega'] : '';
        $obs_recibido = !empty($_POST['obs_recibido']) ? 'RECIBIDO: ' . $_POST['obs_recibido'] : '';
        $observaciones_final = trim($obs_entrega . "\n" . $obs_recibido);

        // Números y merma
        $peso_entregado = (float)str_replace(',', '.', $_POST['peso_entregado'] ?? '0');
        $peso_recibido_str = $_POST['peso_recibido'] ?? '';
        $peso_recibido = ($peso_recibido_str !== '') ? (float)str_replace(',', '.', $peso_recibido_str) : null;
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

        try {
            $sql = "UPDATE Trazabilidad SET
                        fecha_entrega = ?, proceso_id = ?, responsable_id = ?, producto_id = ?,
                        peso_entregado = ?, peso_ley = ?, observaciones = ?, foto_entrega_path = ?,
                        fecha_recibido = ?, peso_recibido = ?, foto_recibido_path = ?, merma = ?
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
                json_encode($final_entrega_paths),
                $_POST['fecha_recibido'] ?: null,
                $peso_recibido,
                json_encode($final_recibido_paths),
                $merma,
                $consecutivo
            ];

            $stmt->execute($params);

            http_response_code(200);
            echo json_encode(['success' => true, 'message' => 'Registro actualizado con éxito.']);
        } catch (PDOException $e) {
            if (class_exists('Logger')) {
                Logger::error('Error de DB en update', ['exception' => $e->getMessage()]);
            }
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Error de base de datos al actualizar.']);
        }
    }

    /**
     * Devuelve materiales (entrega/recibido) asociados a un consecutivo.
     * GET /api/trazabilidad/materiales/{consecutivo}
     */
    public function getMaterialsByConsecutivo($data) {
        $consecutivo = $data['consecutivo'] ?? null;
        if (empty($consecutivo)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'El consecutivo es obligatorio.']);
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
                if ($r['movimiento'] === 'entrega') $entrega[] = $r; else $recibido[] = $r;
            }
            http_response_code(200);
            echo json_encode(['success' => true, 'data' => ['entrega' => $entrega, 'recibido' => $recibido]]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Error al obtener materiales: ' . $e->getMessage()]);
        }
    }
}