<?php
// Clase de utilidad para manejar la sesión del usuario.
class SessionManager {
    public static function start() {
        if (session_status() == PHP_SESSION_NONE) {
            session_start();
        }
    }

    public static function set($key, $value) {
        self::start();
        $_SESSION[$key] = $value;
    }

    public static function get($key) {
        self::start();
        return $_SESSION[$key] ?? null;
    }

    public static function destroy() {
        self::start();
        session_unset();
        session_destroy();
    }
}

/**
 * Clase AuthController
 * Maneja las operaciones de autenticación (Login y Recuperación).
 */
class AuthController {
    private $db;

    /**
     * Constructor que recibe la conexión PDO.
     * @param PDO $db Objeto de conexión a la base de datos.
     */
    public function __construct($db) {
        $this->db = $db;
    }

    /**
     * Procesa el intento de login (POST /api/login).
     * Establece una sesión simple si las credenciales son válidas.
     * @param array $data Datos recibidos (debe contener 'usuario' y 'clave').
     */
    public function login($data) {
        $usuario = trim($data['usuario'] ?? '');
        $clave = $data['clave'] ?? '';

        if (empty($usuario) || empty($clave)) {
            if (class_exists('Logger')) { Logger::warn('Login missing fields', ['usuario' => $usuario]); }
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Usuario y clave son obligatorios.']);
            return;
        }

        try {
            if (class_exists('Logger')) { Logger::info('Login attempt', ['usuario' => $usuario, 'ip' => $_SERVER['REMOTE_ADDR'] ?? '']); }
            $stmt = $this->db->prepare("SELECT id, nombre, clave, rol FROM Usuarios WHERE usuario = ?");
            $stmt->execute([$usuario]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user && password_verify($clave, $user['clave'])) {
                // Login exitoso. Creamos una sesión simple.
                SessionManager::set('user_id', $user['id']);
                SessionManager::set('user_nombre', $user['nombre']);
                SessionManager::set('user_rol', $user['rol'] ?? 'operador');
                SessionManager::set('logged_in', true);

                // Establecer flag de administrador (insensible a mayúsculas)
                $rol = strtolower($user['rol'] ?? '');
                SessionManager::set('is_admin', ($rol === 'admin'));

                if (class_exists('Logger')) { Logger::info('Login success', ['usuario' => $usuario, 'user_id' => $user['id']]); }
                http_response_code(200);
                echo json_encode(['success' => true, 'message' => 'Login exitoso.', 'user' => ['id' => $user['id'], 'nombre' => $user['nombre'], 'rol' => ($user['rol'] ?? 'operador')]]);
            } else {
                if (class_exists('Logger')) { Logger::warn('Login failed', ['usuario' => $usuario]); }
                http_response_code(401); // Unauthorized
                echo json_encode(['success' => false, 'message' => 'Usuario o clave incorrectos.']);
            }
        } catch (PDOException $e) {
            if (class_exists('Logger')) { Logger::error('Login DB error', ['usuario' => $usuario, 'error' => $e->getMessage()]); }
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => "Error de base de datos durante el login: " . $e->getMessage()]);
        }
    }

    /**
     * Simula la recuperación de contraseña (POST /api/recover).
     * @param array $data Datos recibidos (debe contener 'usuario').
     */
    public function recoverPassword($data) {
        $usuario = trim($data['usuario'] ?? '');

        if (empty($usuario)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'El correo de usuario es obligatorio para la recuperación.']);
            return;
        }

        try {
            $stmt = $this->db->prepare("SELECT id, nombre FROM Usuarios WHERE usuario = ?");
            $stmt->execute([$usuario]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user) {
                // En un entorno real, aquí se generaría un token único,
                // se guardaría en la BD y se enviaría por correo electrónico.
                // Aquí solo simulamos el proceso.
                
                // SIMULACIÓN DE ENVÍO DE CORREO
                // $to = $usuario;
                // $subject = "Recuperación de Contraseña";
                // $message = "Haga clic en el siguiente enlace para restablecer su contraseña: [Enlace Falso]";
                
                http_response_code(200);
                echo json_encode([
                    'success' => true, 
                    'message' => 'Si el usuario existe, se ha enviado un enlace de recuperación al correo.',
                    'debug' => "Usuario encontrado: " . $user['nombre']
                ]);
            } else {
                // Siempre devolver éxito para evitar dar pistas sobre la existencia de usuarios
                http_response_code(200); 
                echo json_encode([
                    'success' => true, 
                    'message' => 'Si el usuario existe, se ha enviado un enlace de recuperación al correo.',
                ]);
            }
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => "Error de base de datos durante la recuperación: " . $e->getMessage()]);
        }
    }

    /**
     * Cierra la sesión del usuario (GET /api/logout).
     */
    public function logout($data) {
        SessionManager::destroy();
        http_response_code(200);
        echo json_encode(['success' => true, 'message' => 'Sesión cerrada exitosamente.']);
    }

    /**
     * Cambiar contraseña del usuario autenticado (POST /api/change-password)
     * Campos: current_password, new_password, confirm_password
     */
    public function changePassword($data) {
        $userId = (int)(SessionManager::get('user_id') ?? 0);
        if ($userId <= 0) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'No autenticado.']);
            return;
        }

        $current = $data['current_password'] ?? '';
        $new = $data['new_password'] ?? '';
        $confirm = $data['confirm_password'] ?? '';

        if ($current === '' || $new === '' || $confirm === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Todos los campos son obligatorios.']);
            return;
        }
        if (strlen($new) < 8) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'La nueva contraseña debe tener al menos 8 caracteres.']);
            return;
        }
        if ($new !== $confirm) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'La confirmación no coincide.']);
            return;
        }

        try {
            $stmt = $this->db->prepare("SELECT clave FROM Usuarios WHERE id = ?");
            $stmt->execute([$userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row || !password_verify($current, $row['clave'])) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'La contraseña actual es incorrecta.']);
                return;
            }

            $hash = password_hash($new, PASSWORD_BCRYPT);
            $upd = $this->db->prepare("UPDATE Usuarios SET clave = ? WHERE id = ?");
            $ok = $upd->execute([$hash, $userId]);
            if ($ok) {
                http_response_code(200);
                echo json_encode(['success' => true, 'message' => 'Contraseña actualizada correctamente.']);
            } else {
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'No se pudo actualizar la contraseña.']);
            }
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Error al cambiar contraseña: ' . $e->getMessage()]);
        }
    }
}
