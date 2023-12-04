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

use ReflectionClass, ReflectionMethod;
use Zarkiel\Triniel\ApiController;
use Zarkiel\Triniel\Exceptions\{BadRequestException, NotFoundException, UnauthorizedException};

/**
 * Helper class to create automatic Open Api Specifications
 * 
 * @author Carlos Calatayud <admin@zarkiel.com>
 */
class OASCreator{
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
            $chunks = explode(':', $tag);
            return [
                'name' => trim(array_shift($chunks)),
                'description' => trim(implode('', $chunks))
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

    function parseMethodParams($tagParams){
        $params = [];
        foreach($tagParams As $parameter){
            $chunks = explode(" ", $parameter);
            $type = array_shift($chunks);
            $name = str_replace('$', '', array_shift($chunks));
            $description = implode(' ', $chunks);

            $params[str_replace('$', '', $name)] = [
                'type' => $type,
                'description' => $description
            ];
        }

        return $params;
    }

    function getMethodParams($parameters, $tagParams){
        $tagParams = $this->parseMethodParams($tagParams);
        return array_map(fn($parameter) => [
            'name' => $parameter->name,
            'description' => $tagParams[$parameter->name]['description'] ?? "",
            'required' => true,
                'in' => 'path',
                'schema' => [
                    'type' => $tagParams[$parameter->name]['type'] ?? "string",
                ]
            ]
        , $parameters);
    }

    function getRequestBodySchema($reflector){
        $filename = $reflector->getFileName();
        $startLine = $reflector->getStartLine() - 1; // it's actually - 1, otherwise it wont get the function() block
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

    function parseMethodHeaders($route, $tagParams){
        
        $tagParams = $tagParams ?? [];
        if(in_array('validateToken', $route['callbacksBefore'])){
            $tagParams[] = 'Authorization Authorization: Bearer $token';
        }            

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
        }, $tagParams);
    }

    function getPaths(){
        $paths = [];
        $controllerTags = $this->getTags();
        $router = new Router($this->controller);
        $exceptions = [new NotFoundException(), new UnauthorizedException(), new BadRequestException()];
        foreach($router->getRoutes() As $route){
            $action = $route['action'];
            $reflectionMethod = new ReflectionMethod($this->controller, $action);
            $tags = $this->getDocTags($reflectionMethod->getDocComment());
            $parameters = $reflectionMethod->getParameters();
            preg_match_all('/\((.+)\)/U', $route['path'], $matches);
            $path = str_replace($matches[0], array_map(fn($parameter) => '{'.$parameter->name.'}', $parameters), $route['path']);
            $pathSpec = [
                "summary" => $tags['summary'][0] ?? '',
                "tags" => isset($tags['tag']) ? $tags['tag'] : (count($controllerTags) > 0 ? [$controllerTags[0]['name']] : []),
                "parameters" => array_merge($this->parseMethodHeaders($route, $tags['header'] ?? []), $this->getMethodParams($parameters, $tags['param'] ?? [])),
                "params" => $tags['param'] ?? [],
                "responses" => [
                    200 => [
                        "description" => 'OK',
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'delay' => [
                                            'type' => 'integer',
                                            'example' => 0.0001
                                        ],
                                        'data' => [
                                            'type' => 'object',
                                            'example' => []
                                        ]
                                    ]
                                ]
                            ]
                        ]
                    ],
                ],
                "security" => [
                    ['token' => []]
                ]
            ];

            foreach($exceptions As $exception){
                $pathSpec['responses'][$exception->getCode()] = [
                    "description" => $exception->getMessage(),
                    'content' => [
                        'application/json' => [
                            'schema' => [
                                'type' => 'object',
                                'properties' => [
                                    'code' => [
                                        'type' => 'integer',
                                        'example' => $exception->getCode()
                                    ],
                                    'message' => [
                                        'type' => 'string',
                                        'example' => $exception->getMessage()
                                    ]
                                ]
                            ]
                        ]
                    ]
                ];
            }

            if(in_array(strtolower($route['method']), ['post', 'put'])){
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

            $paths[$this->controller->getBasePath().$path][strtolower($route['method'])] = $pathSpec;
        }
        return $paths;
    }

    

    function getResult(){
        return [
            'openapi' => '3.1.0',
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