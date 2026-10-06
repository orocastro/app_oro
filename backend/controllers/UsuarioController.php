<?php
class UsuarioController {
    private $db;

    public function __construct($db) {
        $this->db = $db;
    }

    // LISTAR USUARIOS
    public function index() {
        // Solo admin puede listar usuarios
        if (class_exists('SessionManager')) {
            $rol = SessionManager::get('user_rol') ?? 'operador';
            if ($rol !== 'admin') {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Acceso denegado.'], JSON_UNESCAPED_UNICODE);
                return;
            }
        }
        try {
            $stmt = $this->db->prepare("SELECT id, nombre, usuario, rol, ver_reportes, activo, fecha_creacion FROM Usuarios ORDER BY id DESC");
            $stmt->execute();
            $usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($usuarios as &$u) {
                $u['ver_reportes'] = (int)($u['ver_reportes'] ?? 0);
                $u['activo'] = (int)($u['activo'] ?? 1);
            }
            unset($u);
            // Marcar único admin
            $countStmt = $this->db->prepare("SELECT COUNT(*) AS c FROM Usuarios WHERE rol = 'admin'");
            $countStmt->execute();
            $row = $countStmt->fetch(PDO::FETCH_ASSOC);
            $adminCount = (int)($row['c'] ?? 0);
            if ($adminCount === 1) {
                foreach ($usuarios as &$u) {
                    if (($u['rol'] ?? 'operador') === 'admin') { $u['is_only_admin'] = true; break; }
                }
                unset($u);
            }
            http_response_code(200);
            echo json_encode(['success' => true, 'data' => $usuarios], JSON_UNESCAPED_UNICODE);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Error al listar usuarios: ' . $e->getMessage()]);
        }
    }

    // CREAR USUARIO
    public function create($data) {
        // Solo admin
        if (class_exists('SessionManager')) {
            $rol = SessionManager::get('user_rol') ?? 'operador';
            if ($rol !== 'admin') {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Acceso denegado.'], JSON_UNESCAPED_UNICODE);
                return;
            }
        }
        $nombre = trim($data['nombre'] ?? '');
        $usuario = trim($data['usuario'] ?? '');
        $clave = $data['clave'] ?? '';
        $rol = in_array(($data['rol'] ?? 'operador'), ['admin','operador'], true) ? $data['rol'] : 'operador';
        $ver_reportes = isset($data['ver_reportes']) ? (int)(bool)$data['ver_reportes'] : 0;

        if ($nombre === '' || $usuario === '' || $clave === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Nombre, usuario y clave son obligatorios.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        try {
            // Verificar duplicado
            $stmt = $this->db->prepare("SELECT id FROM Usuarios WHERE usuario = ?");
            $stmt->execute([$usuario]);
            if ($stmt->fetch()) {
                http_response_code(409);
                echo json_encode(['success' => false, 'message' => 'El usuario ya existe.'], JSON_UNESCAPED_UNICODE);
                return;
            }

            $hash = password_hash($clave, PASSWORD_BCRYPT);
            $stmt = $this->db->prepare("INSERT INTO Usuarios (nombre, usuario, clave, rol, ver_reportes) VALUES (?, ?, ?, ?, ?)");
            $ok = $stmt->execute([$nombre, $usuario, $hash, $rol, $ver_reportes]);
            if ($ok) {
                http_response_code(201);
                echo json_encode(['success' => true, 'message' => 'Usuario creado correctamente.'], JSON_UNESCAPED_UNICODE);
            } else {
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'No se pudo crear el usuario.'], JSON_UNESCAPED_UNICODE);
            }
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Error al crear usuario: ' . $e->getMessage()]);
        }
    }

    // ACTUALIZAR USUARIO
    public function update($data) {
        // Solo admin
        if (class_exists('SessionManager')) {
            $rolSess = SessionManager::get('user_rol') ?? 'operador';
            if ($rolSess !== 'admin') {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Acceso denegado.'], JSON_UNESCAPED_UNICODE);
                return;
            }
        }
        $id = isset($data['id']) ? (int)$data['id'] : 0;
        $nombre = trim($data['nombre'] ?? '');
        $usuario = trim($data['usuario'] ?? '');
        $clave = $data['clave'] ?? null; // opcional
        $rol = isset($data['rol']) && in_array($data['rol'], ['admin','operador'], true) ? $data['rol'] : null;
        $ver_reportes = isset($data['ver_reportes']) ? (int)(bool)$data['ver_reportes'] : null;
        $activo = isset($data['activo']) ? (int)(bool)$data['activo'] : null;

        // Actualización parcial: permisos "ver reportes" y/o "activo" (toggle desde la tabla de usuarios)
        if ($nombre === '' && $usuario === '' && ($ver_reportes !== null || $activo !== null)) {
            if ($id <= 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'ID obligatorio.'], JSON_UNESCAPED_UNICODE);
                return;
            }
            try {
                $stmtInfo = $this->db->prepare("SELECT id, rol FROM Usuarios WHERE id = ?");
                $stmtInfo->execute([$id]);
                $info = $stmtInfo->fetch(PDO::FETCH_ASSOC);
                if (!$info) {
                    http_response_code(404);
                    echo json_encode(['success' => false, 'message' => 'Usuario no encontrado.'], JSON_UNESCAPED_UNICODE);
                    return;
                }

                // Protección al desactivar: no permitir desactivarse a sí mismo
                if ($activo === 0 && class_exists('SessionManager')) {
                    $selfId = (int)(SessionManager::get('user_id') ?? 0);
                    if ($selfId === $id) {
                        http_response_code(400);
                        echo json_encode(['success' => false, 'message' => 'No puedes desactivar tu propio usuario.'], JSON_UNESCAPED_UNICODE);
                        return;
                    }
                }

                // Protección al desactivar: no dejar el sistema sin admins activos
                if ($activo === 0 && ($info['rol'] ?? 'operador') === 'admin') {
                    $countStmt = $this->db->prepare("SELECT COUNT(*) AS c FROM Usuarios WHERE rol = 'admin' AND activo = 1 AND id <> ?");
                    $countStmt->execute([$id]);
                    $row = $countStmt->fetch(PDO::FETCH_ASSOC);
                    $adminsActivos = (int)($row['c'] ?? 0);
                    if ($adminsActivos <= 0) {
                        http_response_code(400);
                        echo json_encode(['success' => false, 'message' => 'No puedes desactivar al último administrador activo. Activa o crea otro admin primero.'], JSON_UNESCAPED_UNICODE);
                        return;
                    }
                }

                if ($ver_reportes !== null) {
                    $stmtVR = $this->db->prepare("UPDATE Usuarios SET ver_reportes = ? WHERE id = ?");
                    $stmtVR->execute([$ver_reportes, $id]);
                }
                if ($activo !== null) {
                    $stmtAC = $this->db->prepare("UPDATE Usuarios SET activo = ? WHERE id = ?");
                    $stmtAC->execute([$activo, $id]);
                    if (class_exists('Logger')) {
                        Logger::info('Estado activo de usuario actualizado', ['user_id' => $id, 'activo' => $activo, 'admin_id' => SessionManager::get('user_id')]);
                    }
                }

                $mensaje = 'Usuario actualizado correctamente.';
                if ($ver_reportes !== null && $activo === null) {
                    $mensaje = 'Permiso de reportes actualizado.';
                } elseif ($activo !== null && $ver_reportes === null) {
                    $mensaje = $activo === 1 ? 'Usuario activado correctamente.' : 'Usuario desactivado correctamente.';
                }
                http_response_code(200);
                echo json_encode(['success' => true, 'message' => $mensaje], JSON_UNESCAPED_UNICODE);
            } catch (PDOException $e) {
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'Error al actualizar usuario: ' . $e->getMessage()]);
            }
            return;
        }

        if ($id <= 0 || $nombre === '' || $usuario === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'ID, nombre y usuario son obligatorios.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        try {
            // Verificar duplicado de usuario (excluyendo el propio ID)
            $stmt = $this->db->prepare("SELECT id FROM Usuarios WHERE usuario = ? AND id <> ?");
            $stmt->execute([$usuario, $id]);
            if ($stmt->fetch()) {
                http_response_code(409);
                echo json_encode(['success' => false, 'message' => 'Ya existe otro usuario con ese correo.'], JSON_UNESCAPED_UNICODE);
                return;
            }

            // Proteger rol del único admin si se intenta cambiar a operador
            if ($rol !== null) {
                $currStmt = $this->db->prepare("SELECT rol FROM Usuarios WHERE id = ?");
                $currStmt->execute([$id]);
                $curr = $currStmt->fetch(PDO::FETCH_ASSOC);
                $currRol = $curr['rol'] ?? 'operador';
                if ($currRol === 'admin' && $rol !== 'admin') {
                    $countStmt = $this->db->prepare("SELECT COUNT(*) AS c FROM Usuarios WHERE rol = 'admin'");
                    $countStmt->execute();
                    $row = $countStmt->fetch(PDO::FETCH_ASSOC);
                    $adminCount = (int)($row['c'] ?? 0);
                    if ($adminCount <= 1) {
                        http_response_code(400);
                        echo json_encode(['success' => false, 'message' => 'No puedes cambiar el rol del único administrador. Debe existir al menos un admin.'], JSON_UNESCAPED_UNICODE);
                        return;
                    }
                }
            }

            if ($clave !== null && $clave !== '') {
                $hash = password_hash($clave, PASSWORD_BCRYPT);
                if ($rol !== null) {
                    $stmt = $this->db->prepare("UPDATE Usuarios SET nombre = ?, usuario = ?, clave = ?, rol = ? WHERE id = ?");
                    $ok = $stmt->execute([$nombre, $usuario, $hash, $rol, $id]);
                } else {
                    $stmt = $this->db->prepare("UPDATE Usuarios SET nombre = ?, usuario = ?, clave = ? WHERE id = ?");
                    $ok = $stmt->execute([$nombre, $usuario, $hash, $id]);
                }
            } else {
                if ($rol !== null) {
                    $stmt = $this->db->prepare("UPDATE Usuarios SET nombre = ?, usuario = ?, rol = ? WHERE id = ?");
                    $ok = $stmt->execute([$nombre, $usuario, $rol, $id]);
                } else {
                    $stmt = $this->db->prepare("UPDATE Usuarios SET nombre = ?, usuario = ? WHERE id = ?");
                    $ok = $stmt->execute([$nombre, $usuario, $id]);
                }
            }

            if ($ver_reportes !== null) {
                $stmtVR = $this->db->prepare("UPDATE Usuarios SET ver_reportes = ? WHERE id = ?");
                $stmtVR->execute([$ver_reportes, $id]);
            }

            if ($ok && $stmt->rowCount() >= 0) {
                http_response_code(200);
                echo json_encode(['success' => true, 'message' => 'Usuario actualizado correctamente.'], JSON_UNESCAPED_UNICODE);
            } else {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Usuario no encontrado.'], JSON_UNESCAPED_UNICODE);
            }
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Error al actualizar usuario: ' . $e->getMessage()]);
        }
    }

    // ELIMINAR USUARIO
    public function delete($data) {
        // Solo admin
        if (class_exists('SessionManager')) {
            $rol = SessionManager::get('user_rol') ?? 'operador';
            if ($rol !== 'admin') {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Acceso denegado.'], JSON_UNESCAPED_UNICODE);
                return;
            }
        }
        $id = isset($data['id']) ? (int)$data['id'] : 0;
        if ($id <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'ID inválido.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        // Evitar que se elimine a sí mismo
        if (class_exists('SessionManager')) {
            $selfId = (int)(SessionManager::get('user_id') ?? 0);
            if ($selfId === $id) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'No puedes eliminar tu propio usuario.'], JSON_UNESCAPED_UNICODE);
                return;
            }
        }

        try {
            // Proteger eliminación del único admin
            $info = $this->db->prepare("SELECT rol FROM Usuarios WHERE id = ?");
            $info->execute([$id]);
            $u = $info->fetch(PDO::FETCH_ASSOC);
            if ($u && ($u['rol'] ?? 'operador') === 'admin') {
                $countStmt = $this->db->prepare("SELECT COUNT(*) AS c FROM Usuarios WHERE rol = 'admin'");
                $countStmt->execute();
                $row = $countStmt->fetch(PDO::FETCH_ASSOC);
                $adminCount = (int)($row['c'] ?? 0);
                if ($adminCount <= 1) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'message' => 'No puedes eliminar al único administrador. Crea otro admin antes.'], JSON_UNESCAPED_UNICODE);
                    return;
                }
            }
            $stmt = $this->db->prepare("DELETE FROM Usuarios WHERE id = ?");
            $ok = $stmt->execute([$id]);
            if ($ok && $stmt->rowCount() > 0) {
                http_response_code(200);
                echo json_encode(['success' => true, 'message' => 'Usuario eliminado correctamente.'], JSON_UNESCAPED_UNICODE);
            } else {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Usuario no encontrado.'], JSON_UNESCAPED_UNICODE);
            }
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Error al eliminar usuario: ' . $e->getMessage()]);
        }
    }

    // RESET DE CLAVE POR ADMIN (POST /api/usuarios/{id}/reset-clave)
    // Genera una clave temporal legible de 8 caracteres y la devuelve para que
    // el administrador la comunique al usuario por otro canal.
    public function resetClave($data) {
        header('Content-Type: application/json; charset=utf-8');
        // Solo admin
        if (class_exists('SessionManager')) {
            $rol = SessionManager::get('user_rol') ?? 'operador';
            if ($rol !== 'admin') {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Acceso denegado.'], JSON_UNESCAPED_UNICODE);
                return;
            }
        }
        $id = isset($data['id']) ? (int)$data['id'] : 0;
        if ($id <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'ID inválido.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        // Caracteres legibles sin ambiguos (sin 0, O, 1, l, I)
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
        $maxIdx = strlen($chars) - 1;
        $temp = '';
        for ($i = 0; $i < 8; $i++) {
            $temp .= $chars[random_int(0, $maxIdx)];
        }

        try {
            $hash = password_hash($temp, PASSWORD_BCRYPT);
            $stmt = $this->db->prepare("UPDATE Usuarios SET clave = ? WHERE id = ?");
            $ok = $stmt->execute([$hash, $id]);
            if ($ok && $stmt->rowCount() > 0) {
                if (class_exists('Logger')) {
                    Logger::info('Clave temporal generada por admin', ['user_id' => $id, 'admin_id' => SessionManager::get('user_id')]);
                }
                http_response_code(200);
                echo json_encode(['success' => true, 'temp_clave' => $temp], JSON_UNESCAPED_UNICODE);
            } else {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Usuario no encontrado.'], JSON_UNESCAPED_UNICODE);
            }
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Error al resetear la clave: ' . $e->getMessage()]);
        }
    }
}

?>