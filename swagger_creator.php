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

use ReflectionClass, ReflectionMethod, ReflectionAttribute;
use Zarkiel\Triniel\ApiController;
use Zarkiel\Triniel\Attributes\Route;

/**
 * Helper class to create automatic Swagger specifications
 * 
 * @author Carlos Calatayud <admin@zarkiel.com>
 */
class SwaggerCreator{
    private $controller;

    function __construct(ApiController $controller){
        $this->controller = $controller;
    }

    function getInfo(){
        $tags = $this->getClassDocTags();
        return [
            'title' => $tags['title'][0] ?? '',
            'description' => $tags['description'][0] ?? '',
            'version' => $tags['version'][0] ?? '',
        ];
    }

    function getTags(){
        $tags = $this->getClassDocTags();

        if(!isset($tags['tag']) || count($tags['tag']) == 0){
            return [];
        }

        $tags = array_map(function($tag){
            return [
                'name' => $tag
            ];
        }, $tags['tag']);

        return $tags;
    }

    function getClassDocTags(){
        $reflectionClass = new ReflectionClass($this->controller);
        return $this->getDocTags($reflectionClass->getDocComment());
    }

    function getDocTags($docComment){
        $docComment = preg_replace('/ +/', ' ', $docComment);
        preg_match_all('/@(.+)/i', $docComment, $matches);
        $tags = [];
        foreach($matches[1] As $match){
            $chunks = explode(" ", $match);
            $key = trim(array_shift($chunks));
            if(!isset($tags[$key]))
                $tags[$key] = [];

            $tags[$key][] = trim(implode(" ", $chunks));
        }

        return $tags;
    }

    function getPaths(){
        $paths = [];
        $controllerTags = $this->getTags();

        foreach(get_class_methods($this->controller) As $action){
            $reflectionMethod = new ReflectionMethod($this->controller, $action);
            $actionRoutes = $reflectionMethod->getAttributes(Route::class, ReflectionAttribute::IS_INSTANCEOF);
            $parameters = $reflectionMethod->getParameters();

            foreach($actionRoutes As $route){
                $tags = $this->getDocTags($reflectionMethod->getDocComment());
                //$routes[] = [...$route->getArguments(), 'action' => $action];
                $arguments = $route->getArguments();

                preg_match_all('/\((.+)\)/', $arguments['path'], $matches);
                $path = str_replace($matches[0], array_map(fn($parameter) => '{'.$parameter->name.'}', $parameters), $arguments['path']);
                
                $pathSpec = [
                    "summary" => $tags['summary'][0] ?? '',
                    "tags" => isset($tags['tag']) ? $tags['tag'] : (count($controllerTags) > 0 ? [$controllerTags[0]['name']] : []),
                    "parameters" => array_merge($this->parseMethodHeaders($tags['header']), $this->parseMethodParams($tags['param'])),
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
                    ],
                    "security" => [
                        ['token' => []]
                    ]
                ];

                if(in_array(strtolower($arguments['method']), ['post', 'put'])){
                    $pathSpec['requestBody'] = [
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    'type' => 'object',
                                    'properties' => $this->getRequestBodySchema($reflectionMethod)
                                ]
                            ]
                        ]
                    ];
                }

                $paths[$this->controller->getBasePath().$path][strtolower($arguments['method'])] = $pathSpec;
            }
        }
        return $paths;
    }

    function getRequestBodySchema($reflector){
        $filename = $reflector->getFileName();
        $startLine = $reflector->getStartLine() - 1; // it's actually - 1, otherwise you wont get the function() block
        $endLine = $reflector->getEndLine();
        $length = $endLine - $startLine;

        $source = file($filename);
        $lines = array_slice($source, $startLine, $length);

        foreach($lines As $line){
            if(preg_match('/getRequestBody\((.*)\)/', $line, $match)){
                break;
            }
        }

        if(count($match) == 0)
            return [];

        $requestBodyParamsKeys = explode(',', preg_replace('/ +/', "", trim(substr($match[1], 1, strlen($match[1]) - 2))));
        $requestBodyParamsValues = array_map(function($param){
            return [
                'type' => 'string',
                'example' => ""
            ];
        }, $requestBodyParamsKeys);

        return array_combine($requestBodyParamsKeys, $requestBodyParamsValues);
    }

    function parseMethodParams($tagParams){
        return array_map(function($parameter){
            $chunks = explode(" ", $parameter);
            $type = array_shift($chunks);
            $name = str_replace('$', '', array_shift($chunks));
            $description = implode(' ', $chunks);
            return [
                'name' => $name,
                'description' => $description,
                'required' => true,
                'in' => 'path',
                'schema' => [
                    'type' => $type,
                ]
            ];
        }, $tagParams ?? []);
    }

    function parseMethodHeaders($tagParams){
        return array_map(function($parameter){
            $chunks = explode(" ", $parameter);
            $name = array_shift($chunks);
            $description = implode(' ', $chunks);
            return [
                'name' => $name,
                'description' => $description,
                'required' => true,
                'in' => 'header',
                'schema' => [
                    'type' => "string",
                ]
            ];
        }, $tagParams ?? []);
    }

    function getResult(){
        return [
            'openapi' => '3.0.3',
            'info' => $this->getInfo(),
            'tags' => $this->getTags(),
            'paths' => $this->getPaths(),
            'components' => [
                'securitySchemes' => [
                    'token' => [
                        'type' => 'apiKey',
                        'name' => 'Authorization',
                        'in' => 'header'
                    ]
                ]
            ]
        ];
    }

    function getJSON(){
        return json_encode($this->getResult());
    }


    function getViewer(){
        return base64_decode("PCEtLSBIVE1MIGZvciBzdGF0aWMgZGlzdHJpYnV0aW9uIGJ1bmRsZSBidWlsZCAtLT4KPCFET0NUWVBFIGh0bWw+CjxodG1sIGxhbmc9ImVuIj4KICA8aGVhZD4KICAgIDxtZXRhIGNoYXJzZXQ9IlVURi04Ij4KICAgIDx0aXRsZT5aYXJraWVsLVRyaW5pZWwgLyBTd2FnZ2VyIFVJPC90aXRsZT4KICAgIDxsaW5rIHJlbD0ic3R5bGVzaGVldCIgdHlwZT0idGV4dC9jc3MiIGhyZWY9Imh0dHBzOi8vcGV0c3RvcmUuc3dhZ2dlci5pby9zd2FnZ2VyLXVpLmNzcyIgLz4KICAgIDxsaW5rIHJlbD0ic3R5bGVzaGVldCIgdHlwZT0idGV4dC9jc3MiIGhyZWY9Imh0dHBzOi8vcGV0c3RvcmUuc3dhZ2dlci5pby9pbmRleC5jc3MiIC8+CiAgICA8bGluayByZWw9Imljb24iIHR5cGU9ImltYWdlL3BuZyIgaHJlZj0iaHR0cHM6Ly9wZXRzdG9yZS5zd2FnZ2VyLmlvL2Zhdmljb24tMzJ4MzIucG5nIiBzaXplcz0iMzJ4MzIiIC8+CiAgICA8bGluayByZWw9Imljb24iIHR5cGU9ImltYWdlL3BuZyIgaHJlZj0iaHR0cHM6Ly9wZXRzdG9yZS5zd2FnZ2VyLmlvL2Zhdmljb24tMTZ4MTYucG5nIiBzaXplcz0iMTZ4MTYiIC8+CiAgPC9oZWFkPgoKICA8Ym9keT4KICAgIDxkaXYgaWQ9InN3YWdnZXItdWkiPjwvZGl2PgogICAgPHNjcmlwdCBzcmM9Imh0dHBzOi8vcGV0c3RvcmUuc3dhZ2dlci5pby9zd2FnZ2VyLXVpLWJ1bmRsZS5qcyIgY2hhcnNldD0iVVRGLTgiPiA8L3NjcmlwdD4KICAgIDxzY3JpcHQgc3JjPSJodHRwczovL3BldHN0b3JlLnN3YWdnZXIuaW8vc3dhZ2dlci11aS1zdGFuZGFsb25lLXByZXNldC5qcyIgY2hhcnNldD0iVVRGLTgiPiA8L3NjcmlwdD4KICAgIDxzY3JpcHQ+CiAgICAgICAgCiAgICB3aW5kb3cub25sb2FkID0gZnVuY3Rpb24oKSB7CiAgICAgICAgY29uc3QgdXJsUGFyYW1zID0gbmV3IFVSTFNlYXJjaFBhcmFtcyh3aW5kb3cubG9jYXRpb24uc2VhcmNoKTsKICAgICAgICBjb25zdCBzcGVjVXJsID0gdXJsUGFyYW1zLmdldCgnc3BlYycpOwoKICAgICAgICB3aW5kb3cudWkgPSBTd2FnZ2VyVUlCdW5kbGUoewogICAgICAgICAgICB1cmw6IHNwZWNVcmwsCiAgICAgICAgICAgICJkb21faWQiOiAiI3N3YWdnZXItdWkiLAogICAgICAgICAgICBkZWVwTGlua2luZzogdHJ1ZSwKICAgICAgICAgICAgcHJlc2V0czogWwogICAgICAgICAgICAgICAgU3dhZ2dlclVJQnVuZGxlLnByZXNldHMuYXBpcywKICAgICAgICAgICAgICAgIFN3YWdnZXJVSVN0YW5kYWxvbmVQcmVzZXQKICAgICAgICAgICAgXSwKICAgICAgICAgICAgcGx1Z2luczogWwogICAgICAgICAgICAgICAgLy9Td2FnZ2VyVUlCdW5kbGUucGx1Z2lucy5Eb3dubG9hZFVybAogICAgICAgICAgICBdLAogICAgICAgICAgICBsYXlvdXQ6ICJTdGFuZGFsb25lTGF5b3V0IiwKICAgICAgICAgICAgLy9xdWVyeUNvbmZpZ0VuYWJsZWQ6IHRydWUsCiAgICAgICAgICAgIHZhbGlkYXRvclVybDogImh0dHBzOi8vdmFsaWRhdG9yLnN3YWdnZXIuaW8vdmFsaWRhdG9yIiwKICAgICAgICB9KQogICAgfTsKICAgIDwvc2NyaXB0PgogIDwvYm9keT4KPC9odG1sPg==");
    }

}