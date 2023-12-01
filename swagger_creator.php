<?php
namespace Zarkiel\Triniel;

use ReflectionClass, ReflectionMethod, ReflectionAttribute;
use Zarkiel\Triniel\ApiController;
use Zarkiel\Triniel\Attributes\Route;

class SwaggerCreator{
    private $controller;

    function __construct(ApiController $controller){
        $this->controller = $controller;
    }

    function getInfo(){
        $reflectionClass = new ReflectionClass($this->controller);
        
        return $this->getDocTags($reflectionClass->getDocComment());
    }

    function getDocTags($docComment){
        $docComment = preg_replace('/ +/', ' ', $docComment);
        preg_match_all('/@(.+)/i', $docComment, $matches);
        $keys = array_map(function($match){
            $chunks = explode(" ", $match);
            return trim(array_shift($chunks));
        }, $matches[1]);

        $values = array_map(function($match){
            $chunks = explode(" ", $match);
            array_shift($chunks);
            return trim(implode(" ", $chunks));
        }, $matches[1]);

        return array_combine($keys, $values);
    }

    function getPaths(){
        
        $paths = [];
        foreach(get_class_methods($this->controller) As $action){
            $reflectionMethod = new ReflectionMethod($this->controller, $action);
            $actionRoutes = $reflectionMethod->getAttributes(Route::class, ReflectionAttribute::IS_INSTANCEOF);
            $parameters = $reflectionMethod->getParameters();

            foreach($actionRoutes As $route){
                $tags = $this->getDocTags($reflectionMethod->getDocComment());
                //$routes[] = [...$route->getArguments(), 'action' => $action];
                $arguments = $route->getArguments();

                preg_match_all('/\((.+)\)/', $arguments['path'], $matches);
                $path = str_replace($matches[0], array_map(fn($parameter) => '$'.$parameter->name, $parameters), $arguments['path']);
                //$path = $arguments['path'];
                $paths[$path][strtolower($arguments['method'])] = [
                    "summary" => $tags['summary'] ?? '',
                    //"paremeters" => $parameters,
                    //'matches' => $matches,
                    "responses" => [
                        200 => [
                            "description" => 'OK'
                        ],
                        404 => [
                            "description" => 'Not Found'
                        ],
                        401 => [
                            "description" => 'Unauthorized'
                        ],
                    ]
                ];
            }
        }
        return $paths;
        
    }

    function getResult(){
        return [
            'openapi' => '3.0.3',
            'info' => $this->getInfo(),
            'paths' => $this->getPaths()
        ];
    }

    function getJSON(){
        return json_encode($this->getResult());
    }
}