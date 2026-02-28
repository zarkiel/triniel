<?php
namespace Zarkiel\Triniel\Routing;
use ReflectionClass;
use ReflectionMethod;
use RecursiveIteratorIterator, RecursiveDirectoryIterator, RegexIterator;
use Zarkiel\Triniel\Callbacks\{After, Before};
use Zarkiel\Triniel\Handlers\ErrorHandler;
use Zarkiel\Triniel\Parser\DocBlockParser;
use Zarkiel\Triniel\{AbstractController, Config};
use Zarkiel\Triniel\Core\Container;

class Router
{
    private array $routes = [];
    private string $basePrefix = '';

    /**
     * @param string $basePrefix Un prefijo para todas las rutas relativas, ej: '/api/v1'
     */
    public function __construct(string $basePrefix = '')
    {
        if (!empty($basePrefix)) {
            // Asegura que el prefijo empiece con / y no termine con /
            $this->basePrefix = '/' . trim($basePrefix, '/');
        }
    }

    public function registerControllersFromDirectory(string $directory): void
    {
        // ... (el resto del método no cambia)
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
        $phpFiles = new RegexIterator($files, '/\.php$/');

        foreach ($phpFiles as $phpFile) {
            require_once $phpFile->getPathname();
        }

        foreach (get_declared_classes() as $className) {
            if (is_subclass_of($className, AbstractController::class)) {
                $this->registerController($className);
            }
        }
    }

    private function registerController(string $controllerClass): void
    {
        $reflectionClass = new ReflectionClass($controllerClass);
        $classAttributes = $reflectionClass->getAttributes(Route::class);

        if (empty($classAttributes)) {
            return;
        }

        $controllerPath = $classAttributes[0]->newInstance()->path;
        $finalBasePath = '';

        // *** NUEVA LÓGICA PARA EL PREFIJO ***
        if (str_starts_with($controllerPath, '/')) {
            // Es una RUTA ABSOLUTA, se ignora el prefijo base.
            $finalBasePath = $controllerPath;
        } else {
            // Es una RUTA RELATIVA, se combina con el prefijo base.
            $finalBasePath = $this->basePrefix . '/' . $controllerPath;
        }

        $beforeCallbacks = $this->collectInheritedCallbacks($reflectionClass, Before::class);
        $afterCallbacks = $this->collectInheritedCallbacks($reflectionClass, After::class);

        foreach ($reflectionClass->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $methodAttributes = $method->getAttributes(Route::class);

            $docComment = $method->getDocComment();
            $docTags = DocBlockParser::parse($docComment);

            foreach ($methodAttributes as $attribute) {
                $route = $attribute->newInstance();
                
                // 1. Construir la ruta completa como antes.
                //    Ejemplos de $fullPath:
                //    - '/api/v1/users/' (para el index)
                //    - '/api/v1/users/:id' (para show)
                //    - '/api/v1/users/login' (para login)
                $fullPath = rtrim($finalBasePath, '/') . '/' . ltrim($route->path, '/');

                preg_match_all('/:(\w+)/', $fullPath, $paramMatches);
                $paramNames = $paramMatches[1];

                // *** INICIO DE LA NUEVA LÓGICA UNIVERSAL ***

                // 2. Normalizar la ruta: siempre quitamos la barra final si existe.
                //    - '/api/v1/users/'      -> '/api/v1/users'
                //    - '/api/v1/users/:id'   -> '/api/v1/users/:id' (no cambia)
                //    - '/api/v1/users/login' -> '/api/v1/users/login' (no cambia)
                $normalizedPath = rtrim($fullPath, '/');

                // 3. Determinar el patrón final para la expresión regular.
                $patternPath = '';
                if ($normalizedPath === '') {
                    // Este caso especial maneja la ruta raíz absoluta: '/'.
                    // No queremos que '/?' se le añada.
                    $patternPath = '/';
                } else {
                    // Para TODAS las demás rutas, usamos la ruta normalizada y
                    // añadimos una barra diagonal opcional al final.
                    // - '/api/v1/users'       -> '/api/v1/users/?'
                    // - '/api/v1/users/:id'   -> '/api/v1/users/:id/?'
                    // - '/api/v1/users/login' -> '/api/v1/users/login/?'
                    $patternPath = $normalizedPath . '/?';
                }

                // 4. Construir la expresión regular final.
                $pattern = '#^' . preg_replace('/:[^\/]+/', '([^\/]+)', $patternPath) . '$#';

                foreach ($route->methods as $httpMethod) {
                    // Verificamos si ya existe una ruta para este patrón y método para evitar conflictos.
                    if (isset($this->routes[$pattern][$httpMethod])) {
                        throw new \Exception("Duplicate route definition: Method {$httpMethod} is already defined for pattern {$pattern}");
                    }

                    // Construimos la nueva estructura anidada
                    $this->routes[$pattern][$httpMethod] = [
                        'pattern' => $pattern,
                        'controller' => $controllerClass,
                        'action' => $method->getName(),
                        'param_names' => $paramNames,
                        'original_path' => $route->path, // Guardamos la ruta original para los parámetros
                        'controller_base_path' => $finalBasePath, // <-- **CAMBIO CLAVE**
                        'before_callbacks' => $beforeCallbacks,
                        'after_callbacks' => $afterCallbacks,
                        'doc_tags' => $docTags,
                    ];
                }
            }
        }
    }

    /**
     * Comprueba si un callback debe ejecutarse para una acción específica.
     */
    private function shouldRunCallback(array $callback, string $currentActionName): bool
    {
        // La regla 'only' tiene prioridad
        if (!empty($callback['only'])) {
            return in_array($currentActionName, $callback['only']);
        }

        // Si no hay 'only', se comprueba 'except'
        if (!empty($callback['except'])) {
            return !in_array($currentActionName, $callback['except']);
        }
        
        // Si no hay reglas, se aplica a todos
        return true;
    }
    
    // El método dispatch() no necesita cambios.
    public function dispatch(string $requestUri, string $requestMethod): void
    {
        if (str_ends_with($requestUri, '/openapi')) {
            $this->handleOpenApiRequest($requestUri);
            return; // Detenemos la ejecución
        }
        
        foreach ($this->routes as $pattern => $methods) {
            // 1. Comprobamos si la URL de la petición coincide con el patrón
            if (preg_match($pattern, $requestUri, $matches)) {
                
                // 2. Si la URL coincide, comprobamos si el MÉTODO HTTP está definido para este patrón
                if (isset($methods[$requestMethod])) {
                    // ¡Coincidencia perfecta! Tenemos la ruta correcta.
                    $route = $methods[$requestMethod];

                    array_shift($matches);

                    $resolvedArgs = $this->resolveMethodDependencies($route, $matches);

                    $controllerInstance = new $route['controller']();

                    // Ejecutar callbacks "before"
                    foreach ($route['before_callbacks'] as $callback) {
                        if ($this->shouldRunCallback($callback, $route['action'])) {
                            $controllerInstance->{$callback['action']}();
                        }
                    }

                    // Ejecutar la acción principal
                    call_user_func_array([$controllerInstance, $route['action']], $resolvedArgs);

                    // Ejecutar callbacks "after"
                    foreach ($route['after_callbacks'] as $callback) {
                        if ($this->shouldRunCallback($callback, $route['action'])) {
                            $controllerInstance->{$callback['action']}();
                        }
                    }
                    
                    return; // Ruta encontrada y procesada, terminamos.
                } else {
                    // La URL coincide, pero el método no está permitido. Este es un 405.
                    $allowedMethods = implode(', ', array_keys($methods));
                    throw new \Exception("Method {$requestMethod} not allowed. Allowed methods for this route: {$allowedMethods}", 405);
                }
            }
        }

        ErrorHandler::handleNotFound();
    }


    /**
     * Genera y devuelve una especificación OpenAPI JSON para un controlador específico.
     */
    private function handleOpenApiRequest(string $requestUri): void
    {
        $controllerBasePath = rtrim(str_replace('/openapi', '', $requestUri), '/');

        $controllerRoutes = array_filter($this->routes, function ($route) use ($controllerBasePath) {
            return $route['controller_base_path'] === $controllerBasePath;
        });


        if (empty($controllerRoutes)) {
            ErrorHandler::handleNotFound();
            return;
        }

        // Usamos la primera ruta para obtener el nombre de la clase del controlador
        $controllerClassName = (new ReflectionClass(reset($controllerRoutes)['controller']))->getShortName();

        $spec = $this->generateOpenApiSpec($controllerClassName, $controllerRoutes);
        
        header('Content-Type: application/json');
        http_response_code(200);
        echo json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Recolecta recursivamente los atributos de callback desde una clase
     * hasta su padre, asegurando que los callbacks del padre vengan primero.
     *
     * @param \ReflectionClass $class La clase desde la que empezar a buscar.
     * @param string $attributeName El nombre de la clase del atributo (Before::class o After::class).
     * @return array La lista ordenada de callbacks.
     */
    private function collectInheritedCallbacks(\ReflectionClass $class, string $attributeName): array
    {
        $allCallbacks = [];
        $currentClass = $class;

        // Bucle que sube por la jerarquía de clases
        while ($currentClass && ($currentClass->isSubclassOf(AbstractController::class) || $currentClass->getName() === AbstractController::class)) {
            
            $attributes = $currentClass->getAttributes($attributeName);
            $levelCallbacks = [];

            foreach ($attributes as $attribute) {
                $instance = $attribute->newInstance();
                $levelCallbacks[] = [
                    'action' => $instance->action,
                    'only' => $instance->only,
                    'except' => $instance->except,
                ];
            }

            // **LA MAGIA DE LA PRIORIDAD**: Preponemos los callbacks de este nivel (el padre)
            // a los que ya hemos encontrado (los del hijo).
            $allCallbacks = array_merge($levelCallbacks, $allCallbacks);
            
            // Subimos al siguiente nivel (la clase padre)
            $currentClass = $currentClass->getParentClass();
        }

        return $allCallbacks;
    }
    
    /**
     * Construye la estructura del array de la especificación OpenAPI.
     */
    private function generateOpenApiSpec(string $controllerName, array $routes): array
    {
        $paths = [];
        foreach ($routes as $route) {
            // Convertir de :param a {param} para el formato OpenAPI
            $pathUrl = preg_replace('/:(\w+)/', '{$1}', $route['original_path']);
            if (empty($pathUrl)) $pathUrl = '/'; // Para rutas en la raíz del controlador

            if (!isset($paths[$pathUrl])) {
                $paths[$pathUrl] = [];
            }
            
            // Extraer parámetros de la ruta (ej: :id)
            preg_match_all('/:(\w+)/', $route['original_path'], $matches);
            $pathParameters = [];
            if (!empty($matches[1])) {
                foreach ($matches[1] as $paramName) {
                    $pathParameters[] = [
                        'name' => $paramName,
                        'in' => 'path',
                        'required' => true,
                        'schema' => ['type' => 'string']
                    ];
                }
            }

            foreach ($route['methods'] as $httpMethod) {

                $pathItem = [
                    'summary' => "Action: {$route['action']}", // Default summary
                    'operationId' => $controllerName . '_' . $route['action'],
                    'tags' => [$controllerName],
                    'parameters' => $pathParameters,
                    'responses' => [
                        '200' => ['description' => 'Successful response'],
                        '404' => ['description' => 'Resource not found'],
                        '500' => ['description' => 'Internal server error'],
                    ]
                ];

                $docTags = $route['doc_tags'];
                if (isset($docTags['summary'])) {
                    $pathItem['summary'] = $docTags['summary'];
                }
                if (isset($docTags['description'])) {
                    $pathItem['description'] = $docTags['description'];
                }
                if (isset($docTags['deprecated']) && strtolower($docTags['deprecated']) === 'true') {
                    $pathItem['deprecated'] = true;
                }
                if (isset($docTags['tag'])) {
                    // Permite agrupar rutas bajo una etiqueta personalizada en Swagger UI
                    $pathItem['tags'] = array_map('trim', explode(',', $docTags['tag']));
                }

                $paths[$pathUrl][strtolower($httpMethod)] = $pathItem;
            }
        }

        return [
            'openapi' => '3.0.1',
            'info' => [
                'title' => "$controllerName API",
                'description' => "Autogenerated OpenAPI specification for $controllerName.",
                'version' => Config::get('api.version', '1.0.0'),
            ],
            'paths' => $paths
        ];
    }


    /**
     * Resuelve las dependencias de un método de controlador, realizando Route Model Binding.
     *
     * @param array $route Los metadatos de la ruta actual.
     * @param array $urlMatches Los valores capturados de la URL.
     * @return array Los argumentos finales para pasar a la acción del controlador.
     * @throws \Exception Si un modelo no se encuentra (404).
     */
    private function resolveMethodDependencies(array $route, array $urlMatches): array
    {
        $args = [];
        $urlMatchIndex = 0;

        $reflectionMethod = new \ReflectionMethod($route['controller'], $route['action']);
        $methodParams = $reflectionMethod->getParameters();

        foreach ($methodParams as $param) {
            $paramType = $param->getType();
            
            // Comprueba si el parámetro tiene un tipo, no es un tipo primitivo y es una clase que existe
            if ($paramType && !$paramType->isBuiltin() && class_exists($paramType->getName())) {
                $className = $paramType->getName();
                
                // Asumimos que un "Modelo" es cualquier clase que extienda ActiveRecord\Model
                // Puedes cambiar esta lógica si usas un BaseModel diferente.
                if (is_subclass_of($className, 'ActiveRecord\Model')) {
                    // *** INICIO DE LA LÓGICA MEJORADA PARA MODELOS ***

                    // ¿Hay un valor en la URL que podamos usar para la búsqueda?
                    if (isset($urlMatches[$urlMatchIndex])) {
                        // CASO A: Enlace de modelo de ruta estándar (Update, Show, etc.)
                        $id = $urlMatches[$urlMatchIndex];
                        $modelInstance = $className::find_by_id($id);
                        
                        if (!$modelInstance) {
                            throw new \Exception("Resource not found: {$className} with ID {$id}", 404);
                        }
                        
                        $args[] = $modelInstance;
                        $urlMatchIndex++;
                    } else {
                        // CASO B: No hay valor en la URL. ¿Es el parámetro opcional?
                        if ($param->isOptional() || $param->allowsNull()) {
                            // Si es opcional (ej: ?User $user), creamos una nueva instancia.
                            // Perfecto para formularios de "creación".
                            $args[] = new $className();
                        } else {
                            // Si no es opcional, es un error de programación. No se puede resolver.
                            throw new \Exception("Unresolvable dependency: Model parameter '{$param->getName()}' of type '{$className}' is required, but no corresponding route parameter was found.", 500);
                        }
                    }
                    // *** FIN DE LA LÓGICA MEJORADA ***
                    continue;

                } else {
                    // CASO 2: Inyección de Dependencias de Servicios
                    // Si no es un modelo, le pedimos al Contenedor que nos dé una instancia.
                    $serviceInstance = Container::get($className);
                    $args[] = $serviceInstance;
                    // <- ¡Importante! Este tipo de inyección NO consume un parámetro de la URL.
                    continue;
                }
            }
            
            // Si no es un modelo, es un parámetro normal de la URL
            if (isset($urlMatches[$urlMatchIndex])) {
                $args[] = $urlMatches[$urlMatchIndex];
                $urlMatchIndex++;
            } elseif ($param->isDefaultValueAvailable()) {
                // Para parámetros opcionales como upsertUser($id = null)
                $args[] = $param->getDefaultValue();
            }
        }

        return $args;
    }
}