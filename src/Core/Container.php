<?php
namespace Zarkiel\Triniel\Core;
class Container
{
    /**
     * Almacena las instancias Singleton ya creadas.
     * @var array
     */
    private static array $instances = [];

    /**
     * Obtiene una instancia Singleton de una clase.
     * Si no existe, la crea, la almacena y la devuelve.
     *
     * @param string $className El nombre completo de la clase a resolver.
     * @return object
     * @throws \Exception si la clase no existe.
     */
    public static function get(string $className): object
    {
        if (!class_exists($className)) {
            throw new \Exception("Class not found for dependency injection: {$className}");
        }

        // Si ya tenemos una instancia, la devolvemos.
        if (!isset(self::$instances[$className])) {
            // Si no, creamos una nueva, la guardamos y la devolvemos.
            self::$instances[$className] = new $className();
        }

        return self::$instances[$className];
    }
}