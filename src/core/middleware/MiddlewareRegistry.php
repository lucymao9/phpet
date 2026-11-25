<?php

namespace phpet\core\middleware;

class MiddlewareRegistry
{
    /** @var array<string, array{class: string, params: array}> */
    private array $map = [];

    /** @var array<string, MiddlewareInterface> */
    private array $instances = [];

    /**
     * 注册中间件，params 会在实例化时作为构造参数传入。
     */
    public function register(string $name, string $class, array $params = []): void
    {
        $this->map[$name] = ['class' => $class, 'params' => $params];
    }

    /**
     * 注册已有实例（适合带复杂依赖、由容器构建的中间件）。
     */
    public function registerInstance(string $name, MiddlewareInterface $instance): void
    {
        $this->instances[$name] = $instance;
    }

    public function has(string $name): bool
    {
        return isset($this->map[$name]) || isset($this->instances[$name]);
    }

    public function resolve(string $name): MiddlewareInterface
    {
        if (isset($this->instances[$name])) {
            return $this->instances[$name];
        }

        if (!isset($this->map[$name])) {
            throw new \RuntimeException("Middleware {$name} not registered");
        }

        $config = $this->map[$name];
        $instance = new $config['class'](...$config['params']);

        if (!$instance instanceof MiddlewareInterface) {
            throw new \RuntimeException(
                "Middleware {$name} must implement " . MiddlewareInterface::class
            );
        }

        return $instance;
    }
}
