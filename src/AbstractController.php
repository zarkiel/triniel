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
        
        // 1. Always start with the query string parameters ($_GET)
        $this->params = $_GET;

        $requestMethod = $_SERVER['REQUEST_METHOD'];
        
        
        // No body to parse for GET requests
        if ($requestMethod === 'GET') {
            return;
        }

        // For POST requests, PHP does the heavy lifting for us.
        // We can trust the superglobals.
        if ($requestMethod === 'POST') {
            // This correctly handles application/x-www-form-urlencoded and multipart/form-data
            // by merging both $_POST and $_FILES.
            $this->params = array_merge($this->params, $_POST, $_FILES);
            //return;
        }

        // For PUT, PATCH, DELETE, etc., we must read the raw input stream.
        // PHP does NOT populate $_POST for these methods.
        $body = file_get_contents('php://input');
        if (empty($body)) {
            return;
        }

        $contentType = trim($_SERVER['CONTENT_TYPE'] ?? '');

        if (str_starts_with($contentType, 'application/json')) {
            $jsonData = json_decode($body, true);
            if (is_array($jsonData)) {
                $this->params = array_merge($this->params, $jsonData);
            }
        } elseif (str_starts_with($contentType, 'application/x-www-form-urlencoded')) {
            parse_str($body, $bodyData);
            $this->params = array_merge($this->params, $bodyData);
        }
        
        // Note: As discussed, multipart/form-data is not parsed for PUT/PATCH.
    }

    protected function render(string $template, array $data = []): void
    {
        View::render($template, [...$this->global_context, ...$data]);
    }

    protected function setGlobalContext(string $key, mixed $value){
        $this->global_context[$key] = $value;
    }
}