<?php
namespace Zarkiel\Triniel\Callbacks;
use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
class Before
{
    public string $action;
    public array $only;
    public array $except;

    public function __construct(string $action, string|array $only = [], string|array $except = [])
    {
        $this->action = $action;
        $this->only = (array) $only;
        $this->except = (array) $except;
    }
}