<?php
namespace Zarkiel\Triniel;

use Zarkiel\Triniel\Exceptions\NotFoundException;
use Zarkiel\Triniel\Exceptions\MethodNotAllowedException;
use Zarkiel\Triniel\Attributes\Route;
use Zarkiel\Triniel\Attributes\CallbackAfter;
use Zarkiel\Triniel\Attributes\CallbackBefore;

/**
 * @author 		Zarkielx
 * @email		zarkiel@gmail.com
 */
class Router{
    protected ApiController $controller;
    protected string $basePath;
    protected bool $caseSensitive = true;

    function __construct(ApiController $controller, string $basePath = "/"){
        $this->controller = $controller;
        $this->basePath = $basePath;
    }

    function getCallbacks(string $type): Array{
        $callbacks = [];
        $reflectionClass = new \ReflectionClass($this->controller);
        $callbacksDefined = $reflectionClass->getAttributes($type);

        foreach($callbacksDefined As $callback){
            $callbacks[] = $callback->getArguments();
        }

        return $callbacks;
    }

    function getRoutes(): Array{
        $routes = [];
        foreach(get_class_methods($this->controller) As $action){
            $reflectionMethod = new \ReflectionMethod($this->controller, $action);
            $actionRoutes = $reflectionMethod->getAttributes(Route::class, \ReflectionAttribute::IS_INSTANCEOF);

            foreach($actionRoutes As $route){
                $routes[] = [...$route->getArguments(), 'action' => $action];
            }
        }
        //print_r($routes);
        return $routes;
    }

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
                    }catch(\Exception $e){
                        throw new \Exception($e->getMessage(), 500);
                    }
                    break;
                }

                
            }
        }

        if(!$pathMatch){
            throw new NotFoundException();
        }

        // NOT REALLY NEEDED
        // CAUSES ERROR WHEN BROWSER SEND OPTIONS REQUEST
        /*
        if(!$routeMatch){
            throw new MethodNotAllowedException();
        }
        */
    }

    function runCallbacks($type, $route, $matches){
        $callbacks = $this->getCallbacks($type);
        foreach($callbacks As $callback){
            if(empty($callback["actions"]))
                continue;

            if(!empty($callback['onlyFor'])){
                $callbackActionsFor = explode(',', preg_replace("/ +/", "", $callback["onlyFor"]));
                if(!in_array($route['action'], $callbackActionsFor))
                    continue;
            }
            
            $callbackActions = explode(',', preg_replace("/ +/", "", $callback["actions"]));
            foreach($callbackActions As $callbackAction){
                call_user_func_array([$this->controller, $callbackAction], $matches);
            }
                
        }
    }
}



