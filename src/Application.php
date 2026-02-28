<?php
namespace Zarkiel\Triniel;
use Zarkiel\Triniel\Routing\Router;
use Zarkiel\Triniel\Config;
use Zarkiel\Triniel\Handlers\ErrorHandler;
use Zarkiel\Triniel\Middleware\CorsMiddleware;
use ActiveRecord;

class Application{
    public static function boot(){
        $environment = Config::get('app.environment');
        ErrorHandler::register();
        // DATABASE
        $cfg = ActiveRecord\Config::instance();
        $cfg->set_connections(Config::get('database.connections'));
        $cfg->set_default_connection($environment);
        
        $corsMiddleware = new CorsMiddleware();
        $corsMiddleware->handle();
        
        // ROUTER
        $router = new Router(Config::get('app.basepath'));
        $router->registerControllersFromDirectory(Config::get('controllers.path'));


        // Obtener la URI y el método de la solicitud actual
        $requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $requestMethod = $_SERVER['REQUEST_METHOD'];
        //$requestMethod = $_SERVER['REQUEST_METHOD'];

        if ($requestMethod === 'POST' && isset($_POST['_method'])) {
            $spoofedMethod = strtoupper($_POST['_method']);
            if (in_array($spoofedMethod, ['PUT', 'PATCH', 'DELETE'])) {
                $requestMethod = $spoofedMethod;
            }
        }

        // Despachar la solicitud
        $router->dispatch($requestUri, $requestMethod);
    }
}