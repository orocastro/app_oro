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
    private $db_name = 'trazabilidad_db'; // Nombre de la DB u942127396_massi_bd
    private $username = 'root';          // Usuario de la DB u942127396_massi_user
    private $password = '';              // Contraseña de la DB g582UKXE1A!

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
        } catch (PDOException $e) {
            // Detener la ejecución si hay un error fatal de conexión
            die("Error de conexión a la base de datos: " . $e->getMessage());
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
