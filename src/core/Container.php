<?php

namespace phpet\core;

use ReflectionClass;

class Container
{
    private array $bindings = [];
    private array $instances = [];

    public function bind(string $abstract, string $concrete):void
    {
        $this->bindings[$abstract] = $concrete;
    }

    public function singleton(string $abstract, object $instance):void
    {
        $this->instances[$abstract] = $instance;
    }

    public function get(string $abstract)
    {
        if (isset($this->instances[$abstract])) {
            return $this->instances[$abstract];
        }

        $class = $this->bindings[$abstract] ?? $abstract;

        $ref = new ReflectionClass($class);

        if (!$ref->isInstantiable()) {
            throw new \RuntimeException("Class {$class} is not instantiable");
        }

        $constructor = $ref->getConstructor();
        if (!$constructor) {
            return new $class;
        }

        $deps = [];
        foreach ($constructor->getParameters() as $param) {
            $type = $param->getType();
            if (!$type) {
                throw new \RuntimeException(
                    "Cannot resolve {$class} constructor param"
                );
            }
            $deps[] = $this->get((string)$type);
        }

        return $ref->newInstanceArgs($deps);
    }
}