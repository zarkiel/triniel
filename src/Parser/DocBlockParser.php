<?php
namespace Zarkiel\Triniel\Parser;

class DocBlockParser
{
    /**
     * Analiza una cadena de DocBlock y extrae las etiquetas en un array asociativo.
     * Ejemplo: '@summary: Mi Resumen' se convierte en ['summary' => 'Mi Resumen']
     *
     * @param string|false $docComment El comentario obtenido de ReflectionMethod::getDocComment()
     * @return array
     */
    public static function parse(string|false $docComment): array
    {
        if (empty($docComment)) {
            return [];
        }

        $tags = [];
        // Expresión regular para encontrar etiquetas como @nombre: valor o @nombre valor
        $pattern = '/@(\w+)(?::)?\s+(.*)/';
        
        if (preg_match_all($pattern, $docComment, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                // $match[1] es el nombre de la etiqueta (ej: 'summary')
                // $match[2] es el valor, eliminamos los caracteres de fin de línea y asteriscos
                $value = rtrim(trim($match[2]), "*/ \t\n\r\0\x0B");
                $tags[$match[1]] = $value;
            }
        }
        
        return $tags;
    }
}