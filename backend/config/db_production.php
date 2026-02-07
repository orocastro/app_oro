<?php
/**
 * Clase Database para PRODUCCIÓN en Hostinger
 * Maneja la conexión a la base de datos MySQL usando PDO.
 * Usa el patrón Singleton para asegurar una única conexión.
 */
class Database {
    private static $instance = null;
    private $connection;

    // Configuración de la base de datos para HOSTINGER
    // ⚠️ IMPORTANTE: Reemplaza estos valores con los datos reales de tu hosting
    private $host = 'localhost';           // Normalmente 'localhost' en Hostinger
    private $db_name = 'u123456789_trazabilidad'; // Nombre de tu base de datos en Hostinger
    private $username = 'u123456789_user';         // Tu usuario de base de datos
    private $password = 'TU_PASSWORD_SEGURO';      // Tu contraseña de base de datos

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
            // En producción, no mostrar detalles del error por seguridad
            error_log("Error de conexión DB: " . $e->getMessage());
            die("Error de conexión a la base de datos. Contacte al administrador.");
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