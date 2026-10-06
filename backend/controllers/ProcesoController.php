<?php
/**
 * Clase ProcesoController
 * Maneja todas las operaciones CRUD para la tabla 'Procesos'.
 */
class ProcesoController {
    private $db;
    // IDs de procesos protegidos que no se pueden editar ni eliminar
    private $protectedIds = [1, 2, 3, 4];

    /**
     * Constructor que recibe la conexión PDO.
     * @param PDO $db Objeto de conexión a la base de datos.
     */
    public function __construct($db) {
        $this->db = $db;
    }

    /**
     * Verifica que el usuario autenticado sea administrador.
     * Si no lo es, responde 403 y devuelve false.
     */
    private function requiereAdmin() {
        if (class_exists('SessionManager')) {
            $rol = SessionManager::get('user_rol') ?? 'operador';
            if ($rol !== 'admin') {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Acceso denegado. Solo administradores.'], JSON_UNESCAPED_UNICODE);
                return false;
            }
        }
        return true;
    }

    /**
     * Devuelve todos los procesos (GET /api/procesos).
     * @param array $data Datos de la petición (usualmente vacíos para GET all).
     */
    public function index($data) {
        try {
            $stmt = $this->db->prepare(
                "SELECT p.id, p.nombre,
                        EXISTS(SELECT 1 FROM Trazabilidad t WHERE t.proceso_id = p.id LIMIT 1) AS in_use
                 FROM Procesos p
                 ORDER BY p.nombre ASC"
            );
            $stmt->execute();
            $procesos = $stmt->fetchAll(PDO::FETCH_ASSOC);

            http_response_code(200);
            echo json_encode(['success' => true, 'data' => $procesos], JSON_UNESCAPED_UNICODE);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => "Error al obtener procesos: " . $e->getMessage()]);
        }
    }

    /**
     * Crea un nuevo proceso (POST /api/procesos).
     * @param array $data Datos recibidos en el cuerpo de la petición (debe contener 'nombre').
     */
    public function create($data) {
        if (!$this->requiereAdmin()) { return; }
        $nombre = $data['nombre'] ?? '';

        if (empty($nombre)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'El nombre del proceso es obligatorio.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        try {
            $stmt = $this->db->prepare("INSERT INTO Procesos (nombre) VALUES (?)");
            $stmt->execute([$nombre]);

            $new_id = $this->db->lastInsertId();
            http_response_code(201); // Created
            echo json_encode(['success' => true, 'message' => 'Proceso creado exitosamente.', 'id' => $new_id, 'nombre' => $nombre], JSON_UNESCAPED_UNICODE);
        } catch (PDOException $e) {
            // Error 23000 es usualmente una violación de restricción única (nombre duplicado)
            if ($e->getCode() == '23000') {
                http_response_code(409); // Conflict
                echo json_encode(['success' => false, 'message' => 'El nombre del proceso ya existe.'], JSON_UNESCAPED_UNICODE);
            } else {
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => "Error al crear proceso: " . $e->getMessage()]);
            }
        }
    }

    /**
     * Actualiza un proceso existente (PUT /api/procesos).
     * @param array $data Datos recibidos (debe contener 'id' y 'nombre').
     */
    public function update($data) {
        if (!$this->requiereAdmin()) { return; }
        $id = $data['id'] ?? null;
        $nombre = $data['nombre'] ?? '';

        if (empty($id) || empty($nombre)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'ID y nombre son obligatorios para actualizar.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        // Proteger registros críticos por ID
        if (in_array((int)$id, $this->protectedIds, true)) {
            http_response_code(403); // Forbidden
            echo json_encode(['success' => false, 'message' => 'Este proceso está protegido y no puede editarse.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        // Proteger si está en uso en Trazabilidad
        try {
            $chk = $this->db->prepare("SELECT 1 FROM Trazabilidad WHERE proceso_id = ? LIMIT 1");
            $chk->execute([$id]);
            if ($chk->fetch()) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'No se puede editar: el proceso ya está asociado a registros de trazabilidad.'], JSON_UNESCAPED_UNICODE);
                return;
            }
        } catch (PDOException $e) {
            // Si falla el check, continuamos con manejo estándar más abajo
        }

        try {
            $stmt = $this->db->prepare("UPDATE Procesos SET nombre = ? WHERE id = ?");
            $stmt->execute([$nombre, $id]);

            if ($stmt->rowCount() == 0) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Proceso no encontrado o no se realizaron cambios.'], JSON_UNESCAPED_UNICODE);
                return;
            }

            http_response_code(200);
            echo json_encode(['success' => true, 'message' => 'Proceso actualizado exitosamente.'], JSON_UNESCAPED_UNICODE);
        } catch (PDOException $e) {
            if ($e->getCode() == '23000') {
                http_response_code(409); // Conflict
                echo json_encode(['success' => false, 'message' => 'El nombre del proceso ya existe.'], JSON_UNESCAPED_UNICODE);
            } else {
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => "Error al actualizar proceso: " . $e->getMessage()]);
            }
        }
    }

    /**
     * Elimina un proceso (DELETE /api/procesos).
     * @param array $data Datos recibidos (debe contener 'id').
     */
    public function delete($data) {
        if (!$this->requiereAdmin()) { return; }
        $id = $data['id'] ?? null;
        
        // La data para DELETE puede venir en el cuerpo o en la URL
        if (is_null($id)) {
            $id = $_GET['id'] ?? null; 
        }

        if (empty($id)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'El ID del proceso es obligatorio para eliminar.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        // Proteger registros críticos por ID
        if (in_array((int)$id, $this->protectedIds, true)) {
            http_response_code(403); // Forbidden
            echo json_encode(['success' => false, 'message' => 'Este proceso está protegido y no puede eliminarse.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        // Proteger si está en uso en Trazabilidad
        try {
            $chk = $this->db->prepare("SELECT 1 FROM Trazabilidad WHERE proceso_id = ? LIMIT 1");
            $chk->execute([$id]);
            if ($chk->fetch()) {
                http_response_code(409); // Conflict
                echo json_encode(['success' => false, 'message' => 'No se puede eliminar: el proceso está asociado a registros de trazabilidad.'], JSON_UNESCAPED_UNICODE);
                return;
            }
        } catch (PDOException $e) {
            // continuará y se capturará en el catch principal si aplica
        }

        try {
            $stmt = $this->db->prepare("DELETE FROM Procesos WHERE id = ?");
            $stmt->execute([$id]);

            if ($stmt->rowCount() > 0) {
                http_response_code(200);
                echo json_encode(['success' => true, 'message' => 'Proceso eliminado exitosamente.'], JSON_UNESCAPED_UNICODE);
            } else {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Proceso no encontrado.'], JSON_UNESCAPED_UNICODE);
            }
        } catch (PDOException $e) {
            // Error 23000 es usualmente una violación de clave foránea (el proceso está en uso)
            if ($e->getCode() == '23000') {
                 http_response_code(409); // Conflict
                 echo json_encode(['success' => false, 'message' => 'No se puede eliminar: El proceso está asociado a un registro de trazabilidad.'], JSON_UNESCAPED_UNICODE);
            } else {
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => "Error al eliminar proceso: " . $e->getMessage()]);
            }
        }
    }
}
