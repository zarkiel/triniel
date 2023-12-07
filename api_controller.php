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

use PDO;
use Zarkiel\Triniel\Attributes\Route;
use Zarkiel\Triniel\Exceptions\{BadRequestException, InvalidTokenException, UnauthorizedException};

/**
 * Class used to be used a Controller specification
 * 
 * @author Carlos Calatayud <admin@zarkiel.com>
 */
class ApiController {

    private $startExecutionTime = 0;
    private $endExecutionTime = 0;
    private $basePath = "";
    private $connectionsData = [];

    function __construct($basePath, $connectionsData){
        $this->basePath = $basePath;
        $this->connectionsData = $connectionsData;
    }

    function getBasePath(){
        return $this->basePath;
    }

    #[Route(path: "/__version__/", method: "GET")]
    function __version__() {
        $this->renderRaw([
            'message' => 'Common API'
        ]);
    }

    /**
     * @summary Returns the Open Api Specification
     * @tag     Triniel Core
     */
    #[Route(path: "/__oas__/", method: "GET")]
    function __oas__(){
        echo (new OASCreator($this))->getJSON();
    }

    /**
     * @summary Display the Swagger UI
     * @tag     Triniel Core
     */
    #[Route(path: "/__swagger__/", method: "GET")]
    function __swagger__(){
        header('Content-Type: text/html');
        if(!isset($_GET['spec'])){
            header('Location: '.$_SERVER['REQUEST_URI'].'?spec='.$this->getBasePath().'/__oas__/');
        }
        echo (new OASCreator($this))->getViewer();
    }

    function startExecution() {
        $this->startExecutionTime = microtime(true);
    }

    function endExecution() {
        $this->endExecutionTime = microtime(true);
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

    function getConnection(string $name) {
        if (!isset($this->connectionsData[$name]))
            throw new \Exception('Connection Not Found');

        $data = $this->connectionsData[$name];

        $connection = new PDO('mysql:host=' . $data['host'] . ':' . @strval($data['port']) . ';dbname=' . $data['database'] . ';charset=utf8', $data['username'], $data['password']);
        $connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $connection;
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
        $conn = $this->getConnection('Core');
        $payload = $this->getTokenPayload();
        
        $canPerform = $conn->query("SELECT `hasPermission`('{$payload->iss}', '{$moduleId}', '{$operation}') AS permission")->fetch(PDO::FETCH_ASSOC);
        if (@intval($canPerform['permission']) === 0) {
            throw new UnauthorizedException();
        }
    }

    function getRequestBody($requiredParams = ""){
        $requestBody = json_decode(file_get_contents('php://input'), true);
        if(is_null($requestBody))
            throw new BadRequestException();
        
        if(!empty($requiredParams)){
            $params = explode(",", preg_replace("/ +/", "", $requiredParams));

            if(count($requestBody) != count($params) || in_array(false, array_map(fn($param) => isset($requestBody[$param]), $params)))
                throw new BadRequestException();
        }
            

        return $requestBody;
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

    function uuid(){
        // version 4 UUID
        return sprintf(
            '%08x-%04x-%04x-%02x%02x-%012x',
            mt_rand(),
            mt_rand(0, 65535),
            bindec(
                substr_replace(
                    sprintf('%016b', mt_rand(0, 65535)),
                    '0100',
                    11,
                    4
                )
            ),
            bindec(substr_replace(sprintf('%08b', mt_rand(0, 255)), '01', 5, 2)),
            mt_rand(0, 255),
            mt_rand()
        );
    }
}
