<?php
// Incluimos la clase Database si aún no ha sido cargada (aunque Router lo hace)
// require_once __DIR__ . '/../config/db.php'; 

/**
 * Clase ResponsableController
 * Maneja todas las operaciones CRUD para la tabla 'Responsables'.
 */
class ResponsableController {
    private $db;

    /**
     * Constructor que recibe la conexión PDO.
     * @param PDO $db Objeto de conexión a la base de datos.
     */
    public function __construct($db) {
        $this->db = $db;
    }

    /**
     * Devuelve todos los responsables (GET /api/responsables).
     * @param array $data Datos de la petición (usualmente vacíos para GET all).
     */
    public function index($data) {
        // Todos los usuarios pueden listar responsables (necesario para formularios)
        // La restricción de gestión (crear/editar/eliminar) se aplica en los otros métodos
        try {
            $stmt = $this->db->prepare(
                "SELECT r.id, r.nombre,
                        EXISTS(SELECT 1 FROM Trazabilidad t WHERE t.responsable_id = r.id LIMIT 1) AS in_use
                 FROM Responsables r
                 ORDER BY r.nombre ASC"
            );
            $stmt->execute();
            $responsables = $stmt->fetchAll(PDO::FETCH_ASSOC);

            http_response_code(200);
            echo json_encode(['success' => true, 'data' => $responsables], JSON_UNESCAPED_UNICODE);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => "Error al obtener responsables: " . $e->getMessage()]);
        }
    }

    /**
     * Crea un nuevo responsable (POST /api/responsables).
     * @param array $data Datos recibidos en el cuerpo de la petición (debe contener 'nombre').
     */
    public function create($data) {
        // Solo admin puede crear responsables
        if (class_exists('SessionManager')) {
            $rol = SessionManager::get('user_rol') ?? 'operador';
            if ($rol !== 'admin') {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Acceso denegado. Solo administradores pueden gestionar responsables.'], JSON_UNESCAPED_UNICODE);
                return;
            }
        }
        
        $nombre = $data['nombre'] ?? '';

        if (empty($nombre)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'El nombre del responsable es obligatorio.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        try {
            $stmt = $this->db->prepare("INSERT INTO Responsables (nombre) VALUES (?)");
            $stmt->execute([$nombre]);

            $new_id = $this->db->lastInsertId();
            http_response_code(201); // Created
            echo json_encode(['success' => true, 'message' => 'Responsable creado exitosamente.', 'id' => $new_id, 'nombre' => $nombre], JSON_UNESCAPED_UNICODE);
        } catch (PDOException $e) {
            // Error 23000 es usualmente una violación de restricción única (nombre duplicado)
            if ($e->getCode() == '23000') {
                http_response_code(409); // Conflict
                echo json_encode(['success' => false, 'message' => 'El nombre del responsable ya existe.'], JSON_UNESCAPED_UNICODE);
            } else {
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => "Error al crear responsable: " . $e->getMessage()]);
            }
        }
    }

    /**
     * Actualiza un responsable existente (PUT /api/responsables).
     * @param array $data Datos recibidos (debe contener 'id' y 'nombre').
     */
    public function update($data) {
        // Solo admin puede actualizar responsables
        if (class_exists('SessionManager')) {
            $rol = SessionManager::get('user_rol') ?? 'operador';
            if ($rol !== 'admin') {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Acceso denegado. Solo administradores pueden gestionar responsables.'], JSON_UNESCAPED_UNICODE);
                return;
            }
        }
        
        $id = $data['id'] ?? null;
        $nombre = $data['nombre'] ?? '';

        if (empty($id) || empty($nombre)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'ID y nombre son obligatorios para actualizar.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        // Bloquear edición si está en uso
        try {
            $chk = $this->db->prepare("SELECT 1 FROM Trazabilidad WHERE responsable_id = ? LIMIT 1");
            $chk->execute([$id]);
            if ($chk->fetch()) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'No se puede editar: el responsable está asociado a trazabilidad.'], JSON_UNESCAPED_UNICODE);
                return;
            }
        } catch (PDOException $e) {}

        try {
            $stmt = $this->db->prepare("UPDATE Responsables SET nombre = ? WHERE id = ?");
            $stmt->execute([$nombre, $id]);

            if ($stmt->rowCount() == 0) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Responsable no encontrado o no se realizaron cambios.'], JSON_UNESCAPED_UNICODE);
                return;
            }

            http_response_code(200);
            echo json_encode(['success' => true, 'message' => 'Responsable actualizado exitosamente.'], JSON_UNESCAPED_UNICODE);
        } catch (PDOException $e) {
            if ($e->getCode() == '23000') {
                http_response_code(409); // Conflict
                echo json_encode(['success' => false, 'message' => 'El nombre del responsable ya existe.'], JSON_UNESCAPED_UNICODE);
            } else {
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => "Error al actualizar responsable: " . $e->getMessage()]);
            }
        }
    }

    /**
     * Elimina un responsable (DELETE /api/responsables).
     * @param array $data Datos recibidos (debe contener 'id').
     */
    public function delete($data) {
        // Solo admin puede eliminar responsables
        if (class_exists('SessionManager')) {
            $rol = SessionManager::get('user_rol') ?? 'operador';
            if ($rol !== 'admin') {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Acceso denegado. Solo administradores pueden gestionar responsables.'], JSON_UNESCAPED_UNICODE);
                return;
            }
        }
        
        $id = $data['id'] ?? null;
        
        // La data para DELETE puede venir en el cuerpo (si se llama vía AJAX con body) o en la URL (si se usa GET o se simula)
        if (is_null($id)) {
            // Intentamos obtener el ID de los parámetros de la URL si no viene en el body
            $id = $_GET['id'] ?? null; 
        }

        if (empty($id)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'El ID del responsable es obligatorio para eliminar.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        // Bloquear eliminación si está en uso
        try {
            $chk = $this->db->prepare("SELECT 1 FROM Trazabilidad WHERE responsable_id = ? LIMIT 1");
            $chk->execute([$id]);
            if ($chk->fetch()) {
                http_response_code(409);
                echo json_encode(['success' => false, 'message' => 'No se puede eliminar: el responsable está asociado a trazabilidad.'], JSON_UNESCAPED_UNICODE);
                return;
            }
        } catch (PDOException $e) {}

        try {
            $stmt = $this->db->prepare("DELETE FROM Responsables WHERE id = ?");
            $stmt->execute([$id]);

            if ($stmt->rowCount() > 0) {
                http_response_code(200);
                echo json_encode(['success' => true, 'message' => 'Responsable eliminado exitosamente.'], JSON_UNESCAPED_UNICODE);
            } else {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Responsable no encontrado.'], JSON_UNESCAPED_UNICODE);
            }
        } catch (PDOException $e) {
            // Error 23000 es usualmente una violación de clave foránea (el responsable está en uso)
            if ($e->getCode() == '23000') {
                 http_response_code(409); // Conflict
                 echo json_encode(['success' => false, 'message' => 'No se puede eliminar: El responsable está asociado a un registro de trazabilidad.'], JSON_UNESCAPED_UNICODE);
            } else {
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => "Error al eliminar responsable: " . $e->getMessage()]);
            }
        }
    }
}
