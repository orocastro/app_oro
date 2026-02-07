<?php
// Configuración de la aplicación
// Activar/desactivar modo depuración visible en frontend
if (!defined('APP_DEBUG')) {
    define('APP_DEBUG', false); // CAMBIADO A FALSE para producción
}

// Configuración de seguridad para producción
if (!defined('SECURE_HEADERS')) {
    define('SECURE_HEADERS', true);
}

// Nivel de reporte de errores para producción
if (!APP_DEBUG) {
    error_reporting(0);
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
}
