<?php
/*
 * This file is part of the Triniel package.
 *
 * (c) Carlos Calatayud <admin@zarkiel.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Zarkiel\Triniel;

use ReflectionClass, ReflectionMethod, ReflectionAttribute, PDOException;
use Zarkiel\Triniel\Exceptions\NotFoundException;
use Zarkiel\Triniel\Attributes\{Route, CallbackAfter, CallbackBefore};
use Zarkiel\Triniel\Exceptions\HttpException;

/**
 * Class used to handle routes of a controller
 * 
 * @author Carlos Calatayud <admin@zarkiel.com>
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
    function getRoutes(): array{
        $routes = [];
        foreach(get_class_methods($this->controller) As $action){
            $reflectionMethod = new ReflectionMethod($this->controller, $action);
            $actionRoutes = $reflectionMethod->getAttributes("Route");

            foreach($actionRoutes As $route){
                $routes[] = [
                    ...$route->getArguments(), 
                    'action' => $action, 
                    'callbacksBefore' => $this->getMethodCallbacks($action, "CallbackBefore"),
                    'callbacksAfter' => $this->getMethodCallbacks($action, "CallbackAfter"),
                ];
            }
        }
        return $routes;
    }

    private function getMethodCallbacks($action, $type){
        $callbacks = $this->getCallbacks($type);
        $methodCallbacks = [];
        foreach($callbacks As $callback){
            if(in_array($action, ['__oas__', '__swagger__', '__version__']))
                continue;

            if(empty($callback["actions"]))
                continue;

            if(!empty($callback['only'])){
                $callbackActionsFor = explode(',', preg_replace("/ +/", "", $callback["only"]));
                if(!in_array($action, $callbackActionsFor))
                    continue;
            }

            if(!empty($callback['exclude'])){
                $excludeActions = explode(',', preg_replace("/ +/", "", $callback["exclude"]));
                if(in_array($action, $excludeActions))
                    continue;
            }

            $methodCallbacks = array_merge($methodCallbacks, explode(',', preg_replace("/ +/", "", $callback["actions"])));
        }

        return $methodCallbacks;
    }

    /**
     * Run the callbacks before or after every request
     * @param    string     $type       The type of callback (after or before)
     * @param    array      $route      The route that will run the callbacks
     * @param    array      $matches    Url parameters matched
     */
    private function runCallbacks($type, $route, $matches){
        foreach($route[$type] As $callback)
            call_user_func_array([$this->controller, $callback], $matches);
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
                        $this->runCallbacks('callbacksBefore', $route, $matches);
                        $this->controller->startExecution();
                        call_user_func_array([$this->controller, $route['action']], $matches);
                        $this->runCallbacks('callbacksAfter', $route, $matches);
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



