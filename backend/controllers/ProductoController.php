<?php
/**
 * Clase ProductoController
 * Maneja todas las operaciones CRUD para la tabla 'Productos'.
 */
class ProductoController {
    private $db;

    /**
     * Constructor que recibe la conexión PDO.
     * @param PDO $db Objeto de conexión a la base de datos.
     */
    public function __construct($db) {
        $this->db = $db;
    }

    /**
     * Devuelve todos los productos (GET /api/productos).
     * @param array $data Datos de la petición (usualmente vacíos para GET all).
     */
    public function index($data) {
        try {
            $stmt = $this->db->prepare(
                "SELECT p.id, p.nombre,
                        EXISTS(SELECT 1 FROM Trazabilidad t WHERE t.producto_id = p.id LIMIT 1) AS in_use
                 FROM Productos p
                 ORDER BY p.nombre ASC"
            );
            $stmt->execute();
            $productos = $stmt->fetchAll(PDO::FETCH_ASSOC);

            http_response_code(200);
            echo json_encode(['success' => true, 'data' => $productos], JSON_UNESCAPED_UNICODE);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => "Error al obtener productos: " . $e->getMessage()]);
        }
    }

    /**
     * Crea un nuevo producto (POST /api/productos).
     * @param array $data Datos recibidos en el cuerpo de la petición (debe contener 'nombre').
     */
    public function create($data) {
        $nombre = $data['nombre'] ?? '';

        if (empty($nombre)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'El nombre del producto es obligatorio.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        try {
            $stmt = $this->db->prepare("INSERT INTO Productos (nombre) VALUES (?)");
            $stmt->execute([$nombre]);

            $new_id = $this->db->lastInsertId();
            http_response_code(201); // Created
            echo json_encode(['success' => true, 'message' => 'Producto creado exitosamente.', 'id' => $new_id, 'nombre' => $nombre], JSON_UNESCAPED_UNICODE);
        } catch (PDOException $e) {
            // Error 23000 es usualmente una violación de restricción única (nombre duplicado)
            if ($e->getCode() == '23000') {
                http_response_code(409); // Conflict
                echo json_encode(['success' => false, 'message' => 'El nombre del producto ya existe.'], JSON_UNESCAPED_UNICODE);
            } else {
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => "Error al crear producto: " . $e->getMessage()]);
            }
        }
    }

    /**
     * Actualiza un producto existente (PUT /api/productos).
     * @param array $data Datos recibidos (debe contener 'id' y 'nombre').
     */
    public function update($data) {
        $id = $data['id'] ?? null;
        $nombre = $data['nombre'] ?? '';

        if (empty($id) || empty($nombre)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'ID y nombre son obligatorios para actualizar.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        // Bloquear edición si está en uso
        try {
            $chk = $this->db->prepare("SELECT 1 FROM Trazabilidad WHERE producto_id = ? LIMIT 1");
            $chk->execute([$id]);
            if ($chk->fetch()) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'No se puede editar: el producto está asociado a trazabilidad.'], JSON_UNESCAPED_UNICODE);
                return;
            }
        } catch (PDOException $e) {}

        try {
            $stmt = $this->db->prepare("UPDATE Productos SET nombre = ? WHERE id = ?");
            $stmt->execute([$nombre, $id]);

            if ($stmt->rowCount() == 0) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Producto no encontrado o no se realizaron cambios.'], JSON_UNESCAPED_UNICODE);
                return;
            }

            http_response_code(200);
            echo json_encode(['success' => true, 'message' => 'Producto actualizado exitosamente.'], JSON_UNESCAPED_UNICODE);
        } catch (PDOException $e) {
            if ($e->getCode() == '23000') {
                http_response_code(409); // Conflict
                echo json_encode(['success' => false, 'message' => 'El nombre del producto ya existe.'], JSON_UNESCAPED_UNICODE);
            } else {
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => "Error al actualizar producto: " . $e->getMessage()]);
            }
        }
    }

    /**
     * Elimina un producto (DELETE /api/productos).
     * @param array $data Datos recibidos (debe contener 'id').
     */
    public function delete($data) {
        $id = $data['id'] ?? null;
        
        // Intentamos obtener el ID de los parámetros de la URL si no viene en el body
        if (is_null($id)) {
            $id = $_GET['id'] ?? null; 
        }

        if (empty($id)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'El ID del producto es obligatorio para eliminar.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        // Bloquear eliminación si está en uso
        try {
            $chk = $this->db->prepare("SELECT 1 FROM Trazabilidad WHERE producto_id = ? LIMIT 1");
            $chk->execute([$id]);
            if ($chk->fetch()) {
                http_response_code(409);
                echo json_encode(['success' => false, 'message' => 'No se puede eliminar: el producto está asociado a trazabilidad.'], JSON_UNESCAPED_UNICODE);
                return;
            }
        } catch (PDOException $e) {}

        try {
            $stmt = $this->db->prepare("DELETE FROM Productos WHERE id = ?");
            $stmt->execute([$id]);

            if ($stmt->rowCount() > 0) {
                http_response_code(200);
                echo json_encode(['success' => true, 'message' => 'Producto eliminado exitosamente.'], JSON_UNESCAPED_UNICODE);
            } else {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Producto no encontrado.'], JSON_UNESCAPED_UNICODE);
            }
        } catch (PDOException $e) {
            // Error 23000 es usualmente una violación de clave foránea (el producto está en uso)
            if ($e->getCode() == '23000') {
                 http_response_code(409); // Conflict
                 echo json_encode(['success' => false, 'message' => 'No se puede eliminar: El producto está asociado a un registro de trazabilidad.'], JSON_UNESCAPED_UNICODE);
            } else {
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => "Error al eliminar producto: " . $e->getMessage()]);
            }
        }
    }
}
