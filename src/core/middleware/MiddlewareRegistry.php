<?php

namespace phpet\core\middleware;

class MiddlewareRegistry
{
    private array $map = [];

    public function register(string $name, string $class): void
    {
        $this->map[$name] = $class;
    }

    public function resolve(string $name): MiddlewareInterface
    {
        if (!isset($this->map[$name])) {
            throw new \RuntimeException("Middleware {$name} not registered");
        }

        return new $this->map[$name]();
    }
}