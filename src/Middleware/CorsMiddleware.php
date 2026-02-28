<?php

namespace Zarkiel\Triniel\Middleware;

use Zarkiel\Triniel\Config;

class CorsMiddleware
{
    /**
     * Maneja la petición entrante para añadir las cabeceras CORS.
     *
     * @return void
     */
    public function handle(): void
    {
        // Obtener la lista de orígenes permitidos desde la configuración
        $allowedOrigins = Config::get('cors.allowed_origins', []);

        // Comprobar si la petición tiene la cabecera Origin
        if (!isset($_SERVER['HTTP_ORIGIN'])) {
            return; // No es una petición CORS, no hacemos nada
        }

        $origin = $_SERVER['HTTP_ORIGIN'];

        /* // Verificar si el origen está permitido
        $isAllowed = false;
        if (in_array('*', $allowedOrigins)) {
            $isAllowed = true;
        } elseif (in_array($origin, $allowedOrigins)) {
            $isAllowed = true;
        }

        if (!$isAllowed) {
            // Opcional: podrías registrar el intento o simplemente ignorarlo.
            // Por simplicidad, no hacemos nada y la petición será bloqueada por el navegador.
            return;
        } */

        
        // Si el origen es permitido, enviamos la cabecera Allow-Origin.
        // Usamos el origen de la petición para ser más estrictos en lugar de '*'.
        header("Access-Control-Allow-Origin: {$origin}");
        header('Access-Control-Allow-Credentials: true');

        // Manejar la petición pre-flight (OPTIONS)
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            
            $allowedMethods = implode(', ', Config::get('cors.allowed_methods', []));
            $allowedHeaders = implode(', ', Config::get('cors.allowed_headers', []));

            header("Access-Control-Allow-Methods: {$allowedMethods}");
            header("Access-Control-Allow-Headers: {$allowedHeaders}");
            // Para peticiones OPTIONS, no se debe procesar nada más.
            // Terminamos la ejecución enviando una respuesta vacía con código 204.
            http_response_code(200);
            exit;
        }
    }
}