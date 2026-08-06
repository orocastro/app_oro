<?php
class Logger {
    private static $logDir;

    public static function init() {
        self::$logDir = __DIR__ . '/../../logs';
        if (!is_dir(self::$logDir)) {
            @mkdir(self::$logDir, 0777, true);
        }
    }

    private static function write($level, $message, $context = []) {
        if (!self::$logDir) { self::init(); }
        $date = date('Y-m-d');
        $file = self::$logDir . "/app-$date.log";
        $ts = date('Y-m-d H:i:s');
        // Sanitizar contexto y evitar volcar contraseñas
        if (isset($context['clave'])) { $context['clave'] = '***'; }
        $line = sprintf("[%s] %s %s %s\n", $ts, strtoupper($level), $message, $context ? json_encode($context, JSON_UNESCAPED_UNICODE) : '');
        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    }

    public static function info($message, $context = []) { self::write('info', $message, $context); }
    public static function warn($message, $context = []) { self::write('warn', $message, $context); }
    public static function error($message, $context = []) { self::write('error', $message, $context); }
}
