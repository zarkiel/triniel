<?php
namespace Zarkiel\Triniel;

use ReflectionClass;
use ReflectionMethod;
use ReflectionAttribute;
use PDOException;
use Zarkiel\Triniel\Exceptions\NotFoundException;
use Zarkiel\Triniel\Attributes\{Route, CallbackAfter, CallbackBefore};
use Zarkiel\Triniel\Exceptions\HttpException;

/**
 * @author    Zarkiel
 * @email     zarkiel@gmail.com
 */
class Router{
    protected ApiController $controller;
    protected string $basePath;
    protected bool $caseSensitive = true;

    function __construct(ApiController $controller){
        $this->controller = $controller;
        $this->basePath = $controller->getBasePath();
    }

    /**
     * Get the callbacks defined on every method of the controller
     * @param       string    $type            The type of callback (after or before)
     * @return      array     $callbacks       Callbacks found on the controller
     */
    private function getCallbacks($type): array{
        $callbacks = [];
        $reflectionClass = new ReflectionClass($this->controller);
        $callbacksDefined = $reflectionClass->getAttributes($type);

        foreach($callbacksDefined As $callback){
            $callbacks[] = $callback->getArguments();
        }

        return $callbacks;
    }

    /**
     * Get the routes defined on every method of the controller
     * @return    array    $routes    Routes found on the controller
     */
    private function getRoutes(): array{
        $routes = [];
        foreach(get_class_methods($this->controller) As $action){
            $reflectionMethod = new ReflectionMethod($this->controller, $action);
            $actionRoutes = $reflectionMethod->getAttributes(Route::class, ReflectionAttribute::IS_INSTANCEOF);

            foreach($actionRoutes As $route){
                $routes[] = [...$route->getArguments(), 'action' => $action];
            }
        }
        return $routes;
    }

    /**
     * Run the callbacks before or after every request
     * @param    string     $type       The type of callback (after or before)
     * @param    array      $route      The route that will run the callbacks
     * @param    array      $matches    Url parameters matched
     */
    private function runCallbacks($type, $route, $matches){
        if(str_starts_with($route['action'], '__'))
            return;

        $callbacks = $this->getCallbacks($type);
        foreach($callbacks As $callback){
            if(empty($callback["actions"]))
                continue;

            if(!empty($callback['onlyFor'])){
                $callbackActionsFor = explode(',', preg_replace("/ +/", "", $callback["onlyFor"]));
                if(!in_array($route['action'], $callbackActionsFor))
                    continue;
            }

            if(!empty($callback['exclude'])){
                $excludeActions = explode(',', preg_replace("/ +/", "", $callback["exclude"]));
                if(in_array($route['action'], $excludeActions))
                    continue;
            }
            
            $callbackActions = explode(',', preg_replace("/ +/", "", $callback["actions"]));
            foreach($callbackActions As $callbackAction){
                call_user_func_array([$this->controller, $callbackAction], $matches);
            }
                
        }
    }

    /**
     * Run the route handler and keep listening for requests
     */
    function run(): void{
        $method = $_SERVER['REQUEST_METHOD'];
        if(strtolower($method) == "options")
            return;

        $url = parse_url($_SERVER['REQUEST_URI']);
        if (isset($url['path']) && $url['path'] != '/') {
            $path = $url['path'];
        } else { 
            $path = '/'; 
        }

        
        $pathMatch = false;
        
        foreach($this->getRoutes() As $route){
            if($this->basePath != '' && $this->basePath != '/') {
                $route['path'] = '('.$this->basePath.')'.$route['path'];
            }

            $route['path'] = '^'.$route['path'].'$';

            if(preg_match('#'.$route['path'].'#'.($this->caseSensitive ? '':'i'), $path, $matches)){
                
                if(strtolower($route['method']) == strtolower($method)){
                    $pathMatch = true;
                    array_shift($matches);
                    if($this->basePath != '' && $this->basePath != '/'){
                        array_shift($matches); 
                    }

                    try{
                        $this->runCallbacks(CallbackBefore::class, $route, $matches);
                        $this->controller->startExecution();
                        call_user_func_array([$this->controller, $route['action']], $matches);
                        $this->runCallbacks(CallbackAfter::class, $route, $matches);
                    }catch(PDOException $e){
                        throw new HttpException($e->getMessage(), 500);
                    }
                    catch(HttpException $e){
                        throw $e;
                    }

                    break;
                }
            }
        }

        if(!$pathMatch){
            throw new NotFoundException();
        }

    }

}



