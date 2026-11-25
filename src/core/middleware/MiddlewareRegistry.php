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
     *
     * 类名、接口与可实例化性都在注册时校验，避免写错后要等到首次请求才暴露。
     */
    public function register(string $name, string $class, array $params = []): void
    {
        // class_exists() 对接口返回 false，需单独判断，否则报错信息会误导成"类不存在"
        if (!class_exists($class) && !interface_exists($class)) {
            throw new \RuntimeException(
                "Middleware {$name} class not found: {$class}"
            );
        }

        if (!is_subclass_of($class, MiddlewareInterface::class)) {
            throw new \RuntimeException(
                "Middleware {$name} must implement " . MiddlewareInterface::class
            );
        }

        // 抽象类也能通过 is_subclass_of，但 new 不出来
        if (!(new \ReflectionClass($class))->isInstantiable()) {
            throw new \RuntimeException(
                "Middleware {$name} must be instantiable (not abstract): {$class}"
            );
        }

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

        // register() 已校验过接口，这里只是兜底
        if (!$instance instanceof MiddlewareInterface) {
            throw new \RuntimeException(
                "Middleware {$name} must implement " . MiddlewareInterface::class
            );
        }

        return $instance;
    }
}
