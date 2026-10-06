<?php
/**
 * Configuración de Base de Datos
 * Usa constantes definidas en config.php
 */

// Asegurar que config.php esté cargado
if (!defined('DB_HOST')) {
    require_once __DIR__ . '/config.php';
}

class Database {
    private static $instance = null;
    private $connection;

    private function __construct() {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        try {
            $this->connection = new PDO($dsn, DB_USER, DB_PASSWORD, $options);
            $this->connection->exec("SET NAMES utf8mb4");
            $this->ensureTables();
        } catch (PDOException $e) {
            error_log("Error de conexión DB: " . $e->getMessage());
            die("Error de conexión a la base de datos. Verifica la configuración en backend/config/config.php");
        }
    }

    private function ensureTables() {
        $this->connection->exec("
            CREATE TABLE IF NOT EXISTS Usuarios (
                id INT AUTO_INCREMENT PRIMARY KEY,
                nombre VARCHAR(255) NOT NULL,
                usuario VARCHAR(255) NOT NULL UNIQUE,
                clave VARCHAR(255) NOT NULL,
                rol VARCHAR(20) NOT NULL DEFAULT 'operador',
                reset_token VARCHAR(255) NULL,
                ver_reportes TINYINT(1) NOT NULL DEFAULT 0,
                activo TINYINT(1) NOT NULL DEFAULT 1,
                fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB
        ");

        // Migración suave: agregar 'ver_reportes' si la tabla ya existía sin la columna
        try {
            $colCheck = $this->connection->prepare("
                SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'Usuarios' AND COLUMN_NAME = 'ver_reportes'
            ");
            $colCheck->execute();
            if ((int)$colCheck->fetchColumn() === 0) {
                $this->connection->exec("ALTER TABLE Usuarios ADD COLUMN ver_reportes TINYINT(1) NOT NULL DEFAULT 0");
                error_log("Migración aplicada: columna ver_reportes agregada a Usuarios");
            }
        } catch (PDOException $e) {
            // No romper el arranque si la migración falla; se reintentará en el próximo request
            error_log("No se pudo verificar/aplicar migración de ver_reportes: " . $e->getMessage());
        }

        // Migración suave: agregar 'activo' si la tabla ya existía sin la columna
        try {
            $this->addColumnIfMissing('Usuarios', 'activo', "activo TINYINT(1) NOT NULL DEFAULT 1");
        } catch (PDOException $e) {
            // No romper el arranque si la migración falla; se reintentará en el próximo request
            error_log("No se pudo verificar/aplicar migración de activo: " . $e->getMessage());
        }
        
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
                estado ENUM('pendiente','parcial','completado','merma_a_favor','cancelado','cerrada') DEFAULT 'pendiente',
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

        // Fase 2: productos anexados a una orden (quien creó la orden o el admin)
        try {
            $this->connection->exec("
                CREATE TABLE IF NOT EXISTS OrdenProductos (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    consecutivo INT NOT NULL,
                    producto_id INT NOT NULL,
                    cantidad INT NOT NULL DEFAULT 1,
                    peso_oro DECIMAL(10,2) NOT NULL DEFAULT 0,
                    peso_materiales DECIMAL(10,2) NOT NULL DEFAULT 0,
                    observaciones VARCHAR(500) NULL,
                    fotos_path TEXT NULL,
                    creado_por VARCHAR(100) NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_ordenproductos_consecutivo (consecutivo)
                ) ENGINE=InnoDB
            ");
            // Migración suave: por si la tabla ya existía con una versión incompleta del esquema
            $this->addColumnIfMissing('OrdenProductos', 'peso_materiales', "peso_materiales DECIMAL(10,2) NOT NULL DEFAULT 0");
            $this->addColumnIfMissing('OrdenProductos', 'fotos_path', "fotos_path TEXT NULL");
            $this->addColumnIfMissing('OrdenProductos', 'creado_por', "creado_por VARCHAR(100) NULL");
        } catch (PDOException $e) {
            // No romper el arranque si la migración falla; se reintentará en el próximo request
            error_log("No se pudo crear/verificar la tabla OrdenProductos: " . $e->getMessage());
        }

        // Fase 3: solicitudes de edición de movimientos con aprobación del admin
        try {
            $this->connection->exec("
                CREATE TABLE IF NOT EXISTS SolicitudEdicion (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    movimiento_id INT NOT NULL,
                    consecutivo INT NOT NULL,
                    valores_propuestos TEXT NOT NULL,
                    motivo VARCHAR(500) NULL,
                    estado ENUM('pendiente','aprobada','rechazada') NOT NULL DEFAULT 'pendiente',
                    solicitado_por VARCHAR(100) NULL,
                    revisado_por VARCHAR(100) NULL,
                    fecha_solicitud TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    fecha_revision TIMESTAMP NULL,
                    INDEX idx_solicitud_edicion_movimiento (movimiento_id),
                    INDEX idx_solicitud_edicion_consecutivo (consecutivo)
                ) ENGINE=InnoDB
            ");
            // Migración suave: por si la tabla ya existía con una versión incompleta del esquema
            $this->addColumnIfMissing('SolicitudEdicion', 'valores_propuestos', "valores_propuestos TEXT NOT NULL");
            $this->addColumnIfMissing('SolicitudEdicion', 'revisado_por', "revisado_por VARCHAR(100) NULL");
            $this->addColumnIfMissing('SolicitudEdicion', 'fecha_revision', "fecha_revision TIMESTAMP NULL");
        } catch (PDOException $e) {
            // No romper el arranque si la migración falla; se reintentará en el próximo request
            error_log("No se pudo crear/verificar la tabla SolicitudEdicion: " . $e->getMessage());
        }
    }

    /**
     * Migración suave: agrega una columna a una tabla si todavía no existe.
     * Mismo patrón que la migración de 'ver_reportes'. $table y $column son
     * literales hardcodeados en las llamadas (no input del usuario).
     */
    private function addColumnIfMissing($table, $column, $definition) {
        $colCheck = $this->connection->prepare("
            SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
        ");
        $colCheck->execute([$table, $column]);
        if ((int)$colCheck->fetchColumn() === 0) {
            $this->connection->exec("ALTER TABLE {$table} ADD COLUMN {$definition}");
            error_log("Migración aplicada: columna {$column} agregada a {$table}");
        }
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
