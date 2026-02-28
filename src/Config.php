<?php
namespace Zarkiel\Triniel;
use Exception;

class Config
{
    /** @var Config|null La única instancia de la clase (Singleton) */
    private static ?Config $instance = null;

    /** @var array Los ajustes de configuración cargados */
    private array $settings = [];

    /**
     * El constructor es privado para prevenir la creación directa de instancias.
     * Carga el archivo de configuración.
     */
    private function __construct()
    {
        // La constante APP_ROOT se definirá en index.php
        $configFilePath = APP_ROOT.'/config.php';
        
        if (!file_exists($configFilePath)) {
            throw new Exception("El archivo de configuración no se encuentra en: {$configFilePath}");
        }
        
        $this->settings = require $configFilePath;
    }

    /** Previene la clonación de la instancia */
    private function __clone() {}

    /** Previene la deserialización de la instancia */
    public function __wakeup() {}

    /**
     * Obtiene la instancia única de la clase Config.
     */
    public static function getInstance(): Config
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Obtiene un valor de la configuración usando notación de punto.
     *
     * @param string $key La clave de configuración (ej: 'database.host')
     * @param mixed|null $default El valor a devolver si la clave no existe.
     * @return mixed
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $config = self::getInstance()->settings;
        $keys = explode('.', $key);

        foreach ($keys as $segment) {
            if (!is_array($config) || !array_key_exists($segment, $config)) {
                return $default;
            }
            $config = $config[$segment];
        }

        return $config;
    }
}