<?php
namespace Zarkiel\Triniel;

use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Zarkiel\Triniel\Handlers\ErrorHandler;
use Twig\TwigFunction;

class View
{
    /** @var Environment|null La instancia Singleton de Twig */
    private static ?Environment $twig = null;

    /**
     * Inicializa Twig si aún no se ha hecho.
     */
    private static function getInstance(): Environment
    {
        if (self::$twig === null) {
            $loader = new FilesystemLoader(Config::get('views.path'));
            
            $twigOptions = [];
            if (Config::get('app.env') === 'production') {
                $twigOptions['cache'] = Config::get('views.cache');
            }

            self::$twig = new Environment($loader, $twigOptions);


            // Función 'config' ya existente
            $configFunction = new TwigFunction('config', ['Zarkiel\Triniel\Config', 'get']);
            self::$twig->addFunction($configFunction);

            // *** INICIO DE LA NUEVA LÓGICA ***

            // 1. Crear la nueva función 'asset'.
            //    Devolverá la ruta completa al archivo de asset.
            $assetFunction = new TwigFunction('asset', function (string $path) {
                // Obtiene la URL base de la configuración (ej: http://localhost:8000)
                $baseUrl = rtrim(Config::get('app.url', ''), '/');
                
                // Concatena la ruta del asset, asegurando que no haya dobles barras
                return $baseUrl . '/' . ltrim($path, '/');
            });
            
            // 2. Registrar la nueva función en Twig.
            self::$twig->addFunction($assetFunction);
        }
        return self::$twig;
    }

    /**
     * Renderiza una plantilla de Twig.
     *
     * @param string $template El nombre del archivo de la plantilla (ej: 'home.twig')
     * @param array $data Los datos para pasar a la plantilla
     */
    public static function render(string $template, array $data = []): void
    {
        try {
            echo self::getInstance()->render($template, $data);
        } catch (\Exception $e) {
            // En un caso real, podrías registrar el error o mostrar una página de error más amigable
            ErrorHandler::handleException($e);
        }
    }
}