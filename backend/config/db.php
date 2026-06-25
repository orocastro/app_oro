<?php
/**
 * Clase Database
 * Maneja la conexión a la base de datos MySQL usando PDO.
 * Usa el patrón Singleton para asegurar una única conexión.
 */
class Database {
    private static $instance = null;
    private $connection;

    // Configuración de la base de datos (debe ser modificada en un entorno real)
    private $host = 'localhost';
    private $db_name = 'u942127396_massi_bd'; // Nombre de la DB
    private $username = 'u942127396_massi_user';          // Usuario de la DB
    private $password = 'g582UKXE1A!';              // Contraseña de la DB

    /**
     * Constructor privado para prevenir la instanciación directa.
     * Establece la conexión PDO.
     */
    private function __construct() {
        $dsn = "mysql:host={$this->host};dbname={$this->db_name};charset=utf8mb4";
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,        // Lanza excepciones en errores
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,   // Devuelve arrays asociativos
            PDO::ATTR_EMULATE_PREPARES => false,                // Preparación de sentencias nativa
        ];

        try {
            $this->connection = new PDO($dsn, $this->username, $this->password, $options);
            $this->ensureTrazabilidadMaterialesTable();
        } catch (PDOException $e) {
            // En producción, no mostrar detalles del error por seguridad
            error_log("Error de conexión DB: " . $e->getMessage());
            die("Error de conexión a la base de datos. Contacte al administrador.");
        }
    }

    /**
     * Asegura la existencia de la tabla TrazabilidadMateriales.
     */
    private function ensureTrazabilidadMaterialesTable() {
        try {
            $stmt = $this->connection->prepare(
                "SELECT 1 FROM information_schema.tables
                 WHERE table_schema = ? AND LOWER(table_name) = 'trazabilidadmateriales'
                 LIMIT 1"
            );
            $stmt->execute([$this->db_name]);
            if ($stmt->fetch()) {
                return;
            }

            $sql = "
                CREATE TABLE IF NOT EXISTS TrazabilidadMateriales (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    consecutivo INT NOT NULL,
                    material_id INT NOT NULL,
                    movimiento ENUM('entrega','recibido') NOT NULL,
                    peso DECIMAL(10,2) NULL,
                    fotos_path TEXT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (material_id) REFERENCES Materiales(id),
                    FOREIGN KEY (consecutivo) REFERENCES Trazabilidad(consecutivo)
                ) ENGINE=InnoDB
            ";
            $this->connection->exec($sql);
        } catch (PDOException $e) {
            error_log("Error creando TrazabilidadMateriales: " . $e->getMessage());
        }
    }

    /**
     * Obtiene la única instancia de la clase (Singleton).
     * @return Database
     */
    public static function getInstance() {
        if (self::$instance == null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Obtiene el objeto de conexión PDO.
     * @return PDO
     */
    public function getConnection() {
        return $this->connection;
    }

    /**
     * Previene la clonación del objeto.
     */
    private function __clone() {}

    /**
     * Previene la deserialización.
     */
    public function __wakeup() {}
}
