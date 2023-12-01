<?php
namespace Zarkiel\Triniel;

use PDO;
use Zarkiel\Triniel\Attributes\Route;
use Zarkiel\Triniel\Exceptions\{InvalidTokenException, UnauthorizedException};


/**
 * @author 		Zarkiel
 * @email		zarkiel@gmail.com
 */
class ApiController {

    private $startExecutionTime = 0;
    private $endExecutionTime = 0;
    protected $connections = [];

    #[Route(path: "/__version__/", method: "GET")]
    function apiVersion() {
        $this->renderRaw([
            'message' => 'Common API'
        ]);
    }

    function startExecution() {
        $this->startExecutionTime = $this->getMicrotime();
    }

    function endExecution() {
        $this->endExecutionTime = $this->getMicrotime();
    }

    function getExecutionTime() {
        return $this->endExecutionTime - $this->startExecutionTime;
    }

    function renderRaw($data){
        $this->endExecution();
        echo json_encode($data);
    }

    function render($data) {
        $this->endExecution();

        echo json_encode([
            'delay' => $this->getExecutionTime(),
            'data' => $data
        ]);
    }

    function isProduction() {
        return ENVIRONMENT != null && ENVIRONMENT === strtolower('PROD');
    }

    function isDevelopment() {
        return !$this->isProduction();
    }

    function &getConnection(string $name) {
        if(isset($this->connections[$name]))
            return $this->connections[$name];

        if (!defined('DATABASE_CONNECTIONS'))
            throw new \Exception('No Database Connections');

        if (!isset(DATABASE_CONNECTIONS[$name]))
            throw new \Exception('Connection Not Found');

        $data = DATABASE_CONNECTIONS[$name];

        $this->connections[$name] = new PDO('mysql:host=' . $data['host'] . ':' . @strval($data['port']) . ';dbname=' . $data['database'] . ';charset=utf8', $data['username'], $data['password']);
        $this->connections[$name]->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $this->connections[$name];
    }

    function closeConnections(){
        if(count($this->connections) > 0)
            foreach($this->connections As $key => $connection){
                $this->connections[$key] = null;
                unset($this->connections[$key]);
            }
    }

    function getMicrotime() {
        list($usec, $sec) = explode(" ", microtime());
        return ((float) $usec + (float) $sec);
    }

    function getHeaderValue(string $name) {
        $headers = getallheaders();
        $headers = array_change_key_case($headers, CASE_LOWER);

        if (!isset($headers[strtolower($name)]))
            throw new \Exception('Header ' . $name . ' Not Found');

        return $headers[strtolower($name)];
    }

    function getAuthorizationToken() {
        $authorization = $this->getHeaderValue('Authorization');

        return substr($authorization, 7);
    }

    function getTokenPayload(){
        $token = $this->getAuthorizationToken();
        $sections_ = explode('.', $token);
        $payload = base64_decode($sections_[1]);
        return json_decode($payload);
    }

    function canPerform(string $moduleId, int $operation){
        $conn = &$this->getConnection('Core');
        $payload = $this->getTokenPayload();
        
        $canPerform = $conn->query("SELECT `hasPermission`('{$payload->iss}', '{$moduleId}', '{$operation}') AS permission")->fetch(PDO::FETCH_ASSOC);
        if (@intval($canPerform['permission']) === 0) {
            throw new UnauthorizedException();
        }
    }

    function getRequestBody(){
        return json_decode(file_get_contents('php://input'), true);
    }

    /**
     * Función para validar token
     * @author Luis Carrillo Gutiérrez, Ing.
     * @refactor Carlos Alberto Calatayud Condori, Ing.
     */
    function validateToken() {
        $token = $this->getAuthorizationToken();
        $sections_ = explode('.', $token);
        if (count($sections_) != 3)
            throw new InvalidTokenException();

        $encabezadoProvisto = base64_decode($sections_[0]);
        $cargaProvista = base64_decode($sections_[1]);
        $firmaProvista = $sections_[2];
        $b64_url_encabezado = $this->base64UrlEncode($encabezadoProvisto);
        $b64_url_carga = $this->base64UrlEncode($cargaProvista);
        $firmaTemporal = hash_hmac('SHA512', $b64_url_encabezado . "." . $b64_url_carga, KEY_CRYPT, true);
        $b64_url_firma = $this->base64UrlEncode($firmaTemporal);

        if ($b64_url_firma != $firmaProvista)
            throw new InvalidTokenException();

        $dataDecodificada = json_decode($cargaProvista);

        if (!isset($dataDecodificada->exp))
            throw new InvalidTokenException();

        $tiempoParaQueExpire = $dataDecodificada->exp;

        if (($tiempoParaQueExpire - time()) < 0) {
            throw new InvalidTokenException();
        }

        return true;
    }


    function base64UrlEncode(string $text): string {
        return rtrim(strtr(base64_encode($text), '+/', '-_'), '=');
    }
}
