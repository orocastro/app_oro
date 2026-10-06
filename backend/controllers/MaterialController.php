<?php
/**
 * Clase MaterialController
 * Maneja las operaciones CRUD para la tabla 'Materiales'.
 */
class MaterialController {
    private $db;

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
     * Lista materiales (GET /api/materiales)
     */
    public function index($data) {
        try {
            $stmt = $this->db->prepare(
                "SELECT m.id, m.nombre, m.protegido,
                        EXISTS(SELECT 1 FROM TrazabilidadMateriales tm WHERE tm.material_id = m.id LIMIT 1) AS in_use
                 FROM Materiales m
                 ORDER BY m.nombre ASC"
            );
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            http_response_code(200);
            echo json_encode(['success' => true, 'data' => $rows], JSON_UNESCAPED_UNICODE);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Error al obtener materiales: ' . $e->getMessage()]);
        }
    }

    /**
     * Crear material (POST /api/materiales)
     */
    public function create($data) {
        if (!$this->requiereAdmin()) { return; }
        $nombre = trim($data['nombre'] ?? '');
        $protegido = isset($data['protegido']) ? (int)!!$data['protegido'] : 0;
        if ($nombre === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'El nombre es obligatorio.'], JSON_UNESCAPED_UNICODE);
            return;
        }
        try {
            $stmt = $this->db->prepare('INSERT INTO Materiales (nombre, protegido) VALUES (?, ?)');
            $stmt->execute([$nombre, $protegido]);
            http_response_code(201);
            echo json_encode(['success' => true, 'message' => 'Material creado.', 'id' => $this->db->lastInsertId()], JSON_UNESCAPED_UNICODE);
        } catch (PDOException $e) {
            if ($e->getCode() == '23000') {
                http_response_code(409);
                echo json_encode(['success' => false, 'message' => 'El nombre del material ya existe.'], JSON_UNESCAPED_UNICODE);
            } else {
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'Error al crear material: ' . $e->getMessage()]);
            }
        }
    }

    /**
     * Actualizar material (PUT /api/materiales)
     */
    public function update($data) {
        if (!$this->requiereAdmin()) { return; }
        $id = $data['id'] ?? null;
        $nombre = trim($data['nombre'] ?? '');
        $protegido = isset($data['protegido']) ? (int)!!$data['protegido'] : null;
        if (empty($id) || $nombre === '' || $protegido === null) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'ID, nombre y protegido son obligatorios.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        // Bloquear edición si material está en uso y se intenta cambiar nombre
        try {
            $chk = $this->db->prepare('SELECT 1 FROM TrazabilidadMateriales WHERE material_id = ? LIMIT 1');
            $chk->execute([$id]);
            $inUse = (bool)$chk->fetch();
            if ($inUse) {
                // Permitimos cambiar 'protegido', pero evitar nombre si está en uso
                $stmt = $this->db->prepare('UPDATE Materiales SET protegido = ? WHERE id = ?');
                $stmt->execute([$protegido, $id]);
                http_response_code(200);
                echo json_encode(['success' => true, 'message' => 'Estado protegido actualizado.'], JSON_UNESCAPED_UNICODE);
                return;
            }
        } catch (PDOException $e) {}

        try {
            $stmt = $this->db->prepare('UPDATE Materiales SET nombre = ?, protegido = ? WHERE id = ?');
            $stmt->execute([$nombre, $protegido, $id]);
            if ($stmt->rowCount() == 0) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Material no encontrado o sin cambios.'], JSON_UNESCAPED_UNICODE);
                return;
            }
            http_response_code(200);
            echo json_encode(['success' => true, 'message' => 'Material actualizado.'], JSON_UNESCAPED_UNICODE);
        } catch (PDOException $e) {
            if ($e->getCode() == '23000') {
                http_response_code(409);
                echo json_encode(['success' => false, 'message' => 'El nombre de material ya existe.'], JSON_UNESCAPED_UNICODE);
            } else {
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'Error al actualizar material: ' . $e->getMessage()]);
            }
        }
    }

    /**
     * Eliminar material (DELETE /api/materiales)
     */
    public function delete($data) {
        if (!$this->requiereAdmin()) { return; }
        $id = $data['id'] ?? ($_GET['id'] ?? null);
        if (empty($id)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'El ID es obligatorio.'], JSON_UNESCAPED_UNICODE);
            return;
        }
        // Bloquear eliminación si está en uso o protegido
        try {
            $stmtP = $this->db->prepare('SELECT protegido FROM Materiales WHERE id = ?');
            $stmtP->execute([$id]);
            $row = $stmtP->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Material no encontrado.'], JSON_UNESCAPED_UNICODE);
                return;
            }
            if ((int)$row['protegido'] === 1) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Material protegido: no se puede eliminar.'], JSON_UNESCAPED_UNICODE);
                return;
            }
            $chk = $this->db->prepare('SELECT 1 FROM TrazabilidadMateriales WHERE material_id = ? LIMIT 1');
            $chk->execute([$id]);
            if ($chk->fetch()) {
                http_response_code(409);
                echo json_encode(['success' => false, 'message' => 'No se puede eliminar: el material está asociado a trazabilidad.'], JSON_UNESCAPED_UNICODE);
                return;
            }
            $del = $this->db->prepare('DELETE FROM Materiales WHERE id = ?');
            $del->execute([$id]);
            if ($del->rowCount() > 0) {
                http_response_code(200);
                echo json_encode(['success' => true, 'message' => 'Material eliminado.'], JSON_UNESCAPED_UNICODE);
            } else {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Material no encontrado.'], JSON_UNESCAPED_UNICODE);
            }
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Error al eliminar material: ' . $e->getMessage()]);
        }
    }
}
