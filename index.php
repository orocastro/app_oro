<?php
// PHP BASE FILE: index.php
// Este archivo sirve como punto de entrada (Front Controller) para toda la aplicación.
// Maneja peticiones API y sirve el HTML principal.

// --- CONFIGURACIÓN Y AUTOLOAD ---
// Detectar dinámicamente la base del subdirectorio (p. ej. /app_oro/)
$scriptDir = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/\\');
$base_url = ($scriptDir === '' || $scriptDir === '/') ? '/' : ($scriptDir . '/');

// Incluimos las clases core
require_once __DIR__ . '/backend/config/db.php';
require_once __DIR__ . '/backend/config/app.php';
require_once __DIR__ . '/backend/core/Router.php';
require_once __DIR__ . '/backend/core/Logger.php';
// Incluimos el controlador de Auth para acceder a SessionManager y las clases necesarias
require_once __DIR__ . '/backend/controllers/AuthController.php'; 

// Iniciamos la sesión para el manejo de la autenticación
SessionManager::start();

// --- LÓGICA DE ENRUTAMIENTO ---
// Normalizar la ruta solicitada quitando el prefijo del subdirectorio de la app
$raw_path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$normalized = ltrim($raw_path, '/');
$appPrefix = trim($scriptDir, '/'); // ej: 'app_oro'
if ($appPrefix !== '' && strpos($normalized, $appPrefix . '/') === 0) {
    $normalized = substr($normalized, strlen($appPrefix) + 1);
} elseif ($appPrefix !== '' && $normalized === $appPrefix) {
    $normalized = '';
}
$request_uri = trim($normalized, '/');
Logger::info('HTTP Request', ['method' => $_SERVER['REQUEST_METHOD'] ?? 'CLI', 'uri' => $request_uri]);

// Servir archivos estáticos bajo frontend/assets/* (ya normalizado sin prefijo de subcarpeta)
if (strpos($request_uri, 'frontend/assets/') === 0) {
    $static_path = __DIR__ . '/' . $request_uri;
    if (file_exists($static_path) && is_file($static_path)) {
        $ext = strtolower(pathinfo($static_path, PATHINFO_EXTENSION));
        switch ($ext) {
            case 'css': header('Content-Type: text/css; charset=utf-8'); break;
            case 'js': header('Content-Type: application/javascript; charset=utf-8'); break;
            case 'png': header('Content-Type: image/png'); break;
            case 'jpg':
            case 'jpeg': header('Content-Type: image/jpeg'); break;
            case 'gif': header('Content-Type: image/gif'); break;
            case 'webp': header('Content-Type: image/webp'); break;
            case 'svg': header('Content-Type: image/svg+xml'); break;
            default: header('Content-Type: application/octet-stream');
        }
        readfile($static_path);
        exit();
    } else {
        http_response_code(404);
        echo "Archivo no encontrado";
        exit();
    }
}

// Si la URL comienza con 'api/', es una llamada al backend API
if (strpos($request_uri, 'api/') === 0) {
    // Es una petición API, configuramos las rutas y despachamos
    
    // Configuramos los encabezados para API REST
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *'); // Permitir CORS para desarrollo
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');

    // Manejar preflight request de CORS (OPTIONS)
    if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
        http_response_code(200);
        exit();
    }
    
    // Inicializar el router
    $router = new Router();

    // --------------------------------------------------------------------------------
    // DEFINICIÓN DE RUTAS API 
    // --------------------------------------------------------------------------------
    
    // Paso 7: Autenticación
    $router->post('login', 'AuthController@login');
    $router->post('recover', 'AuthController@recoverPassword');
    $router->get('logout', 'AuthController@logout'); 
    $router->post('change-password', 'AuthController@changePassword');

    // CRUDs de Catálogos
    require_once __DIR__ . '/backend/controllers/ResponsableController.php'; 
    $router->get('responsables', 'ResponsableController@index');
    $router->post('responsables', 'ResponsableController@create');
    $router->put('responsables', 'ResponsableController@update');
    $router->delete('responsables', 'ResponsableController@delete');

    require_once __DIR__ . '/backend/controllers/ProcesoController.php'; 
    $router->get('procesos', 'ProcesoController@index');
    $router->post('procesos', 'ProcesoController@create');
    $router->put('procesos', 'ProcesoController@update');
    $router->delete('procesos', 'ProcesoController@delete');

    require_once __DIR__ . '/backend/controllers/ProductoController.php'; 
    $router->get('productos', 'ProductoController@index');
    $router->post('productos', 'ProductoController@create');
    $router->put('productos', 'ProductoController@update');
    $router->delete('productos', 'ProductoController@delete');

    // Catálogo de Materiales
    require_once __DIR__ . '/backend/controllers/MaterialController.php';
    $router->get('materiales', 'MaterialController@index');
    $router->post('materiales', 'MaterialController@create');
    $router->put('materiales', 'MaterialController@update');
    $router->delete('materiales', 'MaterialController@delete');

    // Gestión de Usuarios
    require_once __DIR__ . '/backend/controllers/UsuarioController.php';
    $router->get('usuarios', 'UsuarioController@index');
    $router->post('usuarios', 'UsuarioController@create');
    $router->put('usuarios', 'UsuarioController@update');
    $router->delete('usuarios', 'UsuarioController@delete');

    // Paso 9/10/11: Lógica de Trazabilidad (Fase 2: Ordenes + Movimientos)
    require_once __DIR__ . '/backend/controllers/TrazabilidadController.php';

    // --- Nuevas rutas de Ordenes ---
    $router->get('ordenes', 'TrazabilidadController@index');
    $router->post('ordenes', 'TrazabilidadController@createOrden');
    $router->get('ordenes/{consecutivo}/movimientos', 'TrazabilidadController@getMovimientosByConsecutivo');
    $router->get('ordenes/{consecutivo}', 'TrazabilidadController@getByConsecutivo');
    $router->post('movimientos', 'TrazabilidadController@registerMovimiento');

    // --- Rutas legacy (compatibilidad hacia atrás) ---
    // La ruta estática 'trazabilidad' debe ir ANTES de la dinámica 'trazabilidad/{consecutivo}'
    $router->get('trazabilidad', 'TrazabilidadController@index');
    $router->get('trazabilidad/consecutivo', 'TrazabilidadController@getNextConsecutivo');
    // Rutas dinámicas específicas DEBEN ir antes de rutas dinámicas genéricas para evitar capturas erróneas
    $router->get('trazabilidad/edit/{consecutivo}', 'TrazabilidadController@getForEdit');
    $router->get('trazabilidad/materiales/{consecutivo}', 'TrazabilidadController@getMaterialsByConsecutivo');
    // Legacy: búsqueda por consecutivo -> ahora devuelve orden completa
    $router->get('trazabilidad/{consecutivo}', 'TrazabilidadController@getByConsecutivo');
    // Legacy POST: redirigen internamente a los nuevos métodos
    $router->post('trazabilidad/entrega', 'TrazabilidadController@createOrden');
    $router->post('trazabilidad/recibido', 'TrazabilidadController@registerMovimiento');
    $router->post('trazabilidad/update', 'TrazabilidadController@update');
    $router->put('trazabilidad/{id}', 'TrazabilidadController@update');


    try {
        // Despachar la petición
        $router->dispatch();
    } catch (Throwable $t) {
        Logger::error('API Dispatch Error', ['error' => $t->getMessage()]);
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Error interno.']);
    }
    
    exit(); // Detener la ejecución después de manejar la API
}

// --- SERVIR VISTAS HTML DEL FRONTEND ---
// Mapeo simple de URL a archivos HTML en frontend/
$view_map = [
    '' => 'login.html', 
    'home' => 'home.html', 
    'responsables' => 'responsables.html', 
    'procesos' => 'procesos.html', 
    'productos' => 'productos.html', 
    'usuarios' => 'usuarios.html', 
    'trazabilidad' => 'trazabilidad_form.html', 
    'trazabilidad_list' => 'trazabilidad_list.html', // Nueva ruta de vista
    'trazabilidad_edit' => 'trazabilidad_edit.html', // Nueva vista de edición (solo admin)
    'materiales' => 'materiales.html',
];

// Si la URL es la raíz o home, y el usuario no está logueado, forzar a login.
if (($request_uri === '' || $request_uri === 'home') && !SessionManager::get('logged_in')) {
    $view_file = 'login.html';
} else if ($request_uri === '' && SessionManager::get('logged_in')) {
    // Si está logueado y va a '/', redirigir a /home
    header('Location: ' . $base_url . 'home');
    exit();
} else {
    // Si la ruta solicitada no tiene una vista mapeada, por defecto es login.
    $view_file = $view_map[$request_uri] ?? 'login.html'; 
}

$full_path = __DIR__ . '/frontend/' . $view_file;

// Si existe el archivo HTML, lo incluimos. Si no, mostramos un 404 simple.
if (file_exists($full_path)) {
    // Si es una vista, necesitamos $base_url para los assets (ya calculado dinámicamente)
    // Inyección de variables JS mínimas para debug y base URL
        header('Content-Type: text/html; charset=utf-8');
    echo "<script>window.APP_DEBUG=" . (APP_DEBUG ? 'true' : 'false') . ";window.BASE_URL='" . htmlspecialchars($base_url, ENT_QUOTES) . "';</script>";
    include $full_path;
    exit();
} 

// Si la vista no existe y no es API, mostramos 404
http_response_code(404);
echo "<html><head><title>404 - No Encontrado</title><link rel=\"stylesheet\" href=\"{$base_url}frontend/assets/css/styles.css\"></head><body class=\"bg-gray-200 flex items-center justify-center min-h-screen\"><div class=\"text-center p-8 bg-white rounded-lg shadow-xl\"><h1>Error 404</h1><p>Página no encontrada.</p><a href=\"{$base_url}\" class=\"text-teal-600 hover:text-teal-800 mt-4 block\">Volver al inicio</a></div></body></html>";
