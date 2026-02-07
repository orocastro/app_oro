<?php
/**
 * Clase Router
 * Maneja el enrutamiento de peticiones API a los controladores y métodos correspondientes.
 */
class Router {
    protected $routes = [];

    /**
     * Agrega una nueva ruta para un método HTTP específico.
     * @param string $method Método HTTP (GET, POST, PUT, DELETE).
     * @param string $uri URI esperada (e.g., '/responsables').
     * @param string $controller Acción del controlador (e.g., 'ResponsableController@index').
     */
    public function add($method, $uri, $controller) {
        // Normalizar la URI quitando el slash inicial, si existe, para un manejo más simple
        $uri = trim($uri, '/');
        // Soporte para rutas dinámicas con parámetros {param}
        if (strpos($uri, '{') !== false && strpos($uri, '}') !== false) {
            // Extraer nombres de parámetros en el orden en que aparecen
            preg_match_all('/\{([^}]+)\}/', $uri, $matches);
            $paramNames = $matches[1] ?? [];
            // Construir regex: reemplazar cada {param} por ([^/]+) de forma segura
            $regex = preg_replace('/\{[^}]+\}/', '([^/]+)', $uri);
            // Escapar separadores de ruta
            $regex = str_replace('/', '\/', $regex);
            // Asegurar anclaje desde el principio al final
            $regex = '/^' . $regex . '$/';
            $this->routes[$method]['__dynamic'][] = [
                'pattern' => $uri,
                'regex' => $regex,
                'params' => $paramNames,
                'controller' => $controller,
            ];
        } else {
            // Ruta exacta
            $this->routes[$method]['__static'][$uri] = $controller;
        }
    }

    // Métodos abreviados para la creación de rutas
    public function get($uri, $controller) { $this->add('GET', $uri, $controller); }
    public function post($uri, $controller) { $this->add('POST', $uri, $controller); }
    public function put($uri, $controller) { $this->add('PUT', $uri, $controller); }
    public function delete($uri, $controller) { $this->add('DELETE', $uri, $controller); }

    /**
     * Despacha la petición actual al controlador y método correctos.
     */
    public function dispatch() {
        // 1. Obtener la URI y el Método
        // La URI debe ser limpiada para no incluir el subdirectorio de la app ni el prefijo '/api/'
        $raw = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $uri = ltrim($raw, '/');
        // Quitar prefijo del subdirectorio (ej: 'app_oro/') si aplica
        $scriptDir = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/\\');
        $appPrefix = trim($scriptDir, '/');
        if ($appPrefix !== '' && strpos($uri, $appPrefix . '/') === 0) {
            $uri = substr($uri, strlen($appPrefix) + 1);
        } elseif ($appPrefix !== '' && $uri === $appPrefix) {
            $uri = '';
        }
        $uri = trim($uri, '/');
        // Quitar el prefijo 'api/' si existe
        if (strpos($uri, 'api/') === 0) {
            $uri = substr($uri, 4);
        }
        $method = $_SERVER['REQUEST_METHOD'];

        // 2. Buscar la ruta
        if (!array_key_exists($method, $this->routes)) {
            http_response_code(404);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Ruta no encontrada']);
            return;
        }

        $handler = null;
        $routeParams = [];

        // 2.a Intentar coincidencia exacta (estáticas)
        if (isset($this->routes[$method]['__static']) && array_key_exists($uri, $this->routes[$method]['__static'])) {
            $handler = $this->routes[$method]['__static'][$uri];
        } else {
            // 2.b Intentar coincidencia dinámica
            if (isset($this->routes[$method]['__dynamic'])) {
                foreach ($this->routes[$method]['__dynamic'] as $dyn) {
                    if (preg_match($dyn['regex'], $uri, $m)) {
                        array_shift($m); // quitar coincidencia completa
                        $routeParams = [];
                        foreach ($dyn['params'] as $idx => $name) {
                            $routeParams[$name] = $m[$idx] ?? null;
                        }
                        $handler = $dyn['controller'];
                        break;
                    }
                }
            }
        }

        if ($handler === null) {
            // Manejo de error 404
            if (class_exists('Logger')) {
                Logger::error('Ruta no encontrada', ['method' => $method, 'uri' => $uri, 'raw' => $_SERVER['REQUEST_URI'] ?? '']);
            }
            http_response_code(404);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Ruta no encontrada']);
            return;
        }

        // 3. Ejecutar el controlador
        list($controllerName, $methodName) = explode('@', $handler);
        // Normalizar posibles espacios/caracteres invisibles en los nombres
        $controllerName = trim($controllerName);
        $methodName = trim($methodName);
        // Eliminar caracteres de ancho cero y BOM (a veces se cuelan al copiar/pegar)
        $controllerName = preg_replace('/[\x{200B}\x{200C}\x{200D}\x{FEFF}]/u', '', $controllerName);
        $methodName = preg_replace('/[\x{200B}\x{200C}\x{200D}\x{FEFF}]/u', '', $methodName);
        
        // Incluir el archivo del controlador
        $controllerFile = __DIR__ . '/../controllers/' . $controllerName . '.php';
        if (!file_exists($controllerFile)) {
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Archivo de controlador no encontrado: ' . $controllerName]);
            return;
        }

        require_once $controllerFile;

        // Instanciar el controlador y pasar la conexión DB
        $db = Database::getInstance()->getConnection();
        $controller = new $controllerName($db);

        // Ejecutar el método
        if (method_exists($controller, $methodName)) {
            // Preparar datos según el método HTTP
            if ($method === 'GET') {
                // Para GET: merge de query params con route params dinámicos
                $data = array_merge($_GET ?? [], $routeParams);
            } elseif (in_array($method, ['POST', 'PUT', 'DELETE'])) {
                // Para POST/PUT/DELETE: merge del cuerpo JSON con route params dinámicos
                $bodyData = json_decode(file_get_contents('php://input'), true) ?? [];
                $data = array_merge($bodyData, $routeParams);
            } else {
                $data = [];
            }

            // Decidir si pasar $data según la firma del método
            try {
                $refMethod = new ReflectionMethod($controller, $methodName);
                $expectsParams = $refMethod->getNumberOfParameters() > 0;
            } catch (ReflectionException $e) {
                $expectsParams = false;
            }

            if ($expectsParams) {
                call_user_func([$controller, $methodName], $data);
            } else {
                call_user_func([$controller, $methodName]);
            }
        } else {
            // Intento de recuperación: comparar contra métodos disponibles limpiando invisibles
            $available = get_class_methods($controller);
            $found = null;
            foreach ($available as $m) {
                $mNorm = preg_replace('/[\x{200B}\x{200C}\x{200D}\x{FEFF}]/u', '', trim($m));
                if (strcasecmp($mNorm, $methodName) === 0) { // case-insensitive
                    $found = $m;
                    break;
                }
            }
            if ($found && method_exists($controller, $found)) {
                // Reintentar con el nombre encontrado
                $methodName = $found;
                if ($method === 'GET') {
                    $data = array_merge($_GET ?? [], $routeParams);
                } elseif (in_array($method, ['POST', 'PUT', 'DELETE'])) {
                    $bodyData = json_decode(file_get_contents('php://input'), true) ?? [];
                    $data = array_merge($bodyData, $routeParams);
                } else {
                    $data = [];
                }

                try {
                    $refMethod = new ReflectionMethod($controller, $methodName);
                    $expectsParams = $refMethod->getNumberOfParameters() > 0;
                } catch (ReflectionException $e) {
                    $expectsParams = false;
                }
                if ($expectsParams) {
                    call_user_func([$controller, $methodName], $data);
                } else {
                    call_user_func([$controller, $methodName]);
                }
                return;
            }
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Método de controlador no encontrado: ' . $methodName]);
        }
    }
}
