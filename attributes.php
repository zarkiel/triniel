<?php
/*
 * This file is part of the Triniel package.
 *
 * (c) Carlos Calatayud <admin@zarkiel.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace Zarkiel\Triniel\Attributes;

/**
 * Class definition to map the Callback After Attribute
 */
class CallbackAfter{
    private $actions;
    private $onlyFor;
}

/**
 * Class definition to map the Callback Before Attribute
 */
class CallbackBefore{
    private $actions;
    private $onlyFor;
}

/**
 * Class definition to map the Route
 */
class Route{
    private $path;
    private $method;
}