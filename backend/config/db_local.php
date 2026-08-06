<?php
/**
 * Configuración de Base de Datos para XAMPP (Local)
 * Copiar este archivo como db.php para usar en XAMPP
 */
class Database {
    private static $instance = null;
    private $connection;

    // Configuración para XAMPP (local)
    private $host = 'localhost';
    private $db_name = 'trazabilidad';
    private $username = 'root';
    private $password = '';        // XAMPP por defecto no tiene password

    private function __construct() {
        $dsn = "mysql:host={$this->host};dbname={$this->db_name};charset=utf8mb4";
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        try {
            $this->connection = new PDO($dsn, $this->username, $this->password, $options);
            $this->ensureTables();
        } catch (PDOException $e) {
            error_log("Error de conexión DB: " . $e->getMessage());
            die("Error de conexión a la base de datos. Asegúrate de que MySQL esté corriendo y la base de datos 'trazabilidad' exista.");
        }
    }

    private function ensureTables() {
        // Crear tablas si no existen (solo las esenciales)
        $this->connection->exec("
            CREATE TABLE IF NOT EXISTS Usuarios (
                id INT AUTO_INCREMENT PRIMARY KEY,
                nombre VARCHAR(255) NOT NULL,
                usuario VARCHAR(255) NOT NULL UNIQUE,
                clave VARCHAR(255) NOT NULL,
                rol VARCHAR(20) NOT NULL DEFAULT 'operador',
                reset_token VARCHAR(255) NULL,
                fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB
        ");
        
        $this->connection->exec("
            CREATE TABLE IF NOT EXISTS Responsables (
                id INT AUTO_INCREMENT PRIMARY KEY,
                nombre VARCHAR(255) NOT NULL UNIQUE
            ) ENGINE=InnoDB
        ");
        
        $this->connection->exec("
            CREATE TABLE IF NOT EXISTS Procesos (
                id INT AUTO_INCREMENT PRIMARY KEY,
                nombre VARCHAR(255) NOT NULL UNIQUE
            ) ENGINE=InnoDB
        ");
        
        $this->connection->exec("
            CREATE TABLE IF NOT EXISTS Productos (
                id INT AUTO_INCREMENT PRIMARY KEY,
                nombre VARCHAR(255) NOT NULL UNIQUE
            ) ENGINE=InnoDB
        ");
        
        $this->connection->exec("
            CREATE TABLE IF NOT EXISTS Materiales (
                id INT AUTO_INCREMENT PRIMARY KEY,
                nombre VARCHAR(255) NOT NULL UNIQUE,
                protegido TINYINT(1) NOT NULL DEFAULT 0
            ) ENGINE=InnoDB
        ");
        
        $this->connection->exec("
            CREATE TABLE IF NOT EXISTS Trazabilidad (
                consecutivo INT AUTO_INCREMENT PRIMARY KEY,
                fecha_entrega DATETIME NOT NULL,
                proceso_id INT NOT NULL,
                responsable_id INT NOT NULL,
                peso_entregado DECIMAL(10, 2) NOT NULL,
                peso_ley DECIMAL(10, 2) NOT NULL DEFAULT 0,
                producto_id INT NOT NULL,
                foto_entrega_path TEXT NULL,
                fecha_recibido DATETIME NULL,
                peso_recibido DECIMAL(10, 2) NULL,
                foto_recibido_path TEXT NULL,
                merma DECIMAL(10, 2) NOT NULL DEFAULT 0,
                observaciones TEXT NULL,
                estado ENUM('pendiente','parcial','completado','merma_a_favor','cancelado') DEFAULT 'pendiente',
                cantidad INT DEFAULT 1,
                creado_por INT DEFAULT 0,
                observaciones_header TEXT NULL,
                FOREIGN KEY (proceso_id) REFERENCES Procesos(id),
                FOREIGN KEY (responsable_id) REFERENCES Responsables(id),
                FOREIGN KEY (producto_id) REFERENCES Productos(id)
            ) ENGINE=InnoDB
        ");
        
        $this->connection->exec("
            CREATE TABLE IF NOT EXISTS Movimientos (
                id INT AUTO_INCREMENT PRIMARY KEY,
                consecutivo INT NOT NULL,
                tipo ENUM('entrega','recibido') NOT NULL,
                peso DECIMAL(10,2) NOT NULL,
                peso_oro DECIMAL(10,2) NOT NULL DEFAULT 0,
                peso_materiales DECIMAL(10,2) NOT NULL DEFAULT 0,
                fecha DATETIME NOT NULL,
                fotos_path TEXT NULL,
                observaciones TEXT NULL,
                registrado_por INT NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (consecutivo) REFERENCES Trazabilidad(consecutivo) ON DELETE CASCADE
            ) ENGINE=InnoDB
        ");
        
        $this->connection->exec("
            CREATE TABLE IF NOT EXISTS TrazabilidadMateriales (
                id INT AUTO_INCREMENT PRIMARY KEY,
                consecutivo INT NOT NULL,
                material_id INT NOT NULL,
                movimiento ENUM('entrega','recibido') NOT NULL,
                peso DECIMAL(10,2) NULL,
                fotos_path TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (material_id) REFERENCES Materiales(id),
                FOREIGN KEY (consecutivo) REFERENCES Trazabilidad(consecutivo) ON DELETE CASCADE
            ) ENGINE=InnoDB
        ");
    }

    public static function getInstance() {
        if (self::$instance == null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getConnection() {
        return $this->connection;
    }

    private function __clone() {}
    public function __wakeup() {}
}
