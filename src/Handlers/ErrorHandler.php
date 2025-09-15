<?php
namespace Zarkiel\Triniel\Handlers;
use Zarkiel\Triniel\Config;
use Throwable, ErrorException;

class ErrorHandler
{
    /**
     * Registra los manejadores de errores y excepciones.
     * Debe llamarse al inicio del script (en index.php).
     */
    public static function register(): void
    {
        // Forzar que los errores se muestren para que nuestro manejador los capture
        ini_set('display_errors', 1);
        error_reporting(E_ALL);

        // Captura excepciones no controladas
        set_exception_handler([self::class, 'handleException']);
        
        // Convierte errores de PHP (warnings, notices) en ErrorException
        set_error_handler([self::class, 'handleError']);
        
        // Captura errores fatales (que no son capturados por los anteriores)
        register_shutdown_function([self::class, 'handleShutdown']);
    }

    /**
     * El manejador principal para todas las excepciones.
     *
     * @param Throwable $e Puede ser una Exception o un Error
     */
    public static function handleException(Throwable $e): void
    {
        // Limpia cualquier salida que ya se haya generado
        if (ob_get_level() > 0) {
            ob_clean();
        }

        $statusCode = self::getStatusCode($e);
        
        $payload = [
            'status' => 'ERROR',
            'name' => get_class($e),
            'message' => $e->getMessage(),
            'code' => $e->getCode(),
            'trace' => Config::get('app.debug', false) ? $e->getTrace() : ''
        ];


        self::response($payload, $statusCode);
    }

    /**
     * Convierte los errores de PHP en instancias de ErrorException.
     * Esto permite que sean capturados por nuestro manejador de excepciones.
     */
    public static function handleError(int $errno, string $errstr, string $errfile, int $errline): bool
    {
        // Respeta el operador @ (error suppression)
        if (!(error_reporting() & $errno)) {
            return false;
        }
        throw new ErrorException($errstr, $errno, 0, $errfile, $errline);
    }
    
    /**
     * Maneja los errores fatales que no pueden ser capturados por set_error_handler.
     */
    public static function handleShutdown(): void
    {
        $error = error_get_last();
        if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
            self::handleException(
                new ErrorException($error['message'], $error['type'], 0, $error['file'], $error['line'])
            );
        }
    }

    /**
     * Genera una respuesta JSON para errores 404 Not Found.
     */
    public static function handleNotFound(): void
    {
        self::response([
            'status' => 'ERROR',
            'name' => 'HttpNotFoundException',
            'message' => 'The requested resource was not found.',
            'code' => 404,
            'trace' => ''
        ], 404);
    }
    
    /**
     * Obtiene un código de estado HTTP adecuado de una excepción.
     */
    private static function getStatusCode(Throwable $e): int
    {
        $code = $e->getCode();
        // Si el código de la excepción es un código HTTP válido, úsalo.
        if (is_int($code) && $code >= 400 && $code < 600) {
            return $code;
        }
        // Si no, es un error interno del servidor.
        return 500;
    }

    private static function response($payload, $statusCode){
        header('Content-Type: application/json');
        http_response_code($statusCode);
        echo json_encode($payload);
        exit;
    }
}