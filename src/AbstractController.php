<?php
namespace Zarkiel\Triniel;
class AbstractController{
    /**
     * Un array que contiene todos los datos de la solicitud entrante.
     * Combina datos de: Query String ($_GET), x-www-form-urlencoded ($_POST),
     * multipart/form-data ($_POST + $_FILES), y JSON body.
     *
     * @var array
     */
    protected array $params = [];

    protected array $global_context = [];

    /**
     * El constructor del controlador base se encarga de analizar la solicitud
     * y poblar la propiedad $params.
     */
    public function __construct()
    {
        $this->parseRequest();
    }

    private function parseRequest(): void
    {
        // 1. Empezar con los parámetros de la Query String (GET)
        $this->params = $_GET;

        // 2. Analizar el cuerpo de la solicitud según el método y Content-Type
        $requestMethod = $_SERVER['REQUEST_METHOD'];
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';

        // Solo los métodos que típicamente tienen un cuerpo son analizados
        if (in_array($requestMethod, ['POST', 'PUT', 'PATCH'])) {
            
            // 2a. Si es un cuerpo JSON
            if (str_contains($contentType, 'application/json')) {
                $jsonData = json_decode(file_get_contents('php://input'), true);
                if (is_array($jsonData)) {
                    $this->params = array_merge($this->params, $jsonData);
                }
            }
            // 2b. Si es un formulario (POST tradicional)
            elseif (str_contains($contentType, 'application/x-www-form-urlencoded')) {
                // Para PUT/PATCH, $_POST está vacío, así que leemos el cuerpo y lo parseamos
                if (in_array($requestMethod, ['PUT', 'PATCH'])) {
                     parse_str(file_get_contents('php://input'), $postData);
                     $this->params = array_merge($this->params, $postData);
                } else {
                     $this->params = array_merge($this->params, $_POST);
                }
            }
            // 2c. Si es un formulario con subida de archivos
            elseif (str_contains($contentType, 'multipart/form-data')) {
                 $this->params = array_merge($this->params, $_POST, $_FILES);
            }
        }
    }

    protected function render(string $template, array $data = []): void
    {
        View::render($template, [...$this->global_context, ...$data]);
    }

    protected function setGlobalContext(string $key, mixed $value){
        $this->global_context[$key] = $value;
    }
}