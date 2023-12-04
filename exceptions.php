<?php
/*
 * This file is part of the Triniel package.
 *
 * (c) Carlos Calatayud <admin@zarkiel.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace Zarkiel\Triniel\Exceptions;
use Exception;

class HttpException extends Exception {
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
    function __construct($message = 'Unauthorized', $code = 401) {
        parent::__construct($message, $code);
    }
}

class InvalidTokenException extends HttpException {
    function __construct($message = 'Invalid Token', $code = 401) {
        parent::__construct($message, $code);
    }
}

class UnprocessableContentException extends HttpException {
    function __construct($message = 'Invalid Request', $code = 422) {
        parent::__construct($message, $code);
    }
}

class BadRequestException extends HttpException {
    function __construct($message = 'Bad Request', $code = 400) {
        parent::__construct($message, $code);
    }
}

class ExceptionHandler {
    function handleException($exception) {
        $code = $exception->getCode() == 0 ? 500 : $exception->getCode();
        
        http_response_code($code);
        echo json_encode([
            //'name' => get_class($exception),
            //'stackTrace' => $exception instanceof Exception ? $exception->getTrace() : [],
            'code' => $code,
            'message' => $exception->getMessage()
        ]);
    }

    function handleError($errno, $errstr, $errfile, $errline) {
        if(!in_array($errno, [E_ERROR, E_USER_ERROR, E_CORE_ERROR, E_RECOVERABLE_ERROR, E_PARSE]))
            return false;

        http_response_code(500);
        die(json_encode([
            'code' => 500,
            'message' => $errstr
        ]));
        
    }
}
