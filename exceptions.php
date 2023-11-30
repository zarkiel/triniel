<?php
namespace Zarkiel\Triniel\Exceptions;

class HttpException extends \Exception {
}

class NotFoundException extends HttpException {
    function __construct($message = 'Not Found', $code = 404) {
        parent::__construct($message, $code);
    }
}

class MethodNotAllowedException extends HttpException {
    function __construct($message = 'Method Not Allowed', $code = 405) {
        parent::__construct($message, $code);
    }
}

class UnauthorizedException extends HttpException {
    function __construct($message = 'Access Denied', $code = 401) {
        parent::__construct($message, $code);
    }
}

class InvalidTokenException extends HttpException {
    function __construct($message = 'Invalid Token', $code = 401) {
        parent::__construct($message, $code);
    }
}

class ExceptionHandler {
    function handleError($exception) {
        $code = $exception->getCode() == 0 ? 500 : $exception->getCode();
        
        http_response_code($code);
        echo json_encode([
            //'name' => get_class($exception),
            'code' => $code,
            'message' => $exception->getMessage()
        ]);
    }
}
