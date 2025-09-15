<?php
namespace Zarkiel\Triniel\Routing;
use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class Route
{
    public string $path;
    public array $methods;

    public function __construct(string $path, string|array $method = 'GET')
    {
        $this->path = $path;
        $this->methods = (array) $method;
    }
}