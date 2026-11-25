<?php

namespace phpet\core;

use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;

class Container
{
    /** @var array<string, string> */
    private array $bindings = [];

    /** @var array<string, object> 已解析实例（get 默认缓存，等价于容器内单例） */
    private array $instances = [];

    /** @var array<string, bool> 用于检测循环依赖 */
    private array $resolving = [];

    public function bind(string $abstract, string $concrete): void
    {
        $this->bindings[$abstract] = $concrete;
        // 绑定变更后旧实例不再有效
        unset($this->instances[$abstract]);
    }

    public function singleton(string $abstract, object $instance): void
    {
        $this->instances[$abstract] = $instance;
    }

    /**
     * 解析并缓存实例：同一请求内多次 get 拿到同一个对象。
     */
    public function get(string $abstract)
    {
        if (isset($this->instances[$abstract])) {
            return $this->instances[$abstract];
        }

        $instance = $this->build($abstract);
        $this->instances[$abstract] = $instance;

        return $instance;
    }

    /**
     * 每次都构造新实例（不写缓存），适合有状态的短生命周期对象。
     */
    public function make(string $abstract)
    {
        return $this->build($abstract);
    }

    public function forget(string $abstract): void
    {
        unset($this->instances[$abstract]);
    }

    public function has(string $abstract): bool
    {
        return isset($this->instances[$abstract])
            || isset($this->bindings[$abstract])
            || class_exists($abstract);
    }

    private function build(string $abstract): object
    {
        if (isset($this->resolving[$abstract])) {
            throw new \RuntimeException("Circular dependency detected while resolving {$abstract}");
        }

        $class = $this->bindings[$abstract] ?? $abstract;

        if (!class_exists($class)) {
            throw new \RuntimeException("Class {$class} not found");
        }

        $ref = new ReflectionClass($class);
        if (!$ref->isInstantiable()) {
            throw new \RuntimeException("Class {$class} is not instantiable");
        }

        $constructor = $ref->getConstructor();
        if ($constructor === null || $constructor->getNumberOfParameters() === 0) {
            return $ref->newInstance();
        }

        $this->resolving[$abstract] = true;
        try {
            $deps = [];
            foreach ($constructor->getParameters() as $param) {
                $deps[] = $this->resolveParameter($param, $class);
            }

            return $ref->newInstanceArgs($deps);
        } finally {
            unset($this->resolving[$abstract]);
        }
    }

    private function resolveParameter(ReflectionParameter $param, string $class)
    {
        $type = $param->getType();
        $name = $param->getName();

        if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
            $typeName = $type->getName();

            // 可空且类不存在的依赖，退回默认值
            if ($type->allowsNull() && !class_exists($typeName)) {
                return $param->isDefaultValueAvailable()
                    ? $param->getDefaultValue()
                    : null;
            }

            return $this->get($typeName);
        }

        if ($param->isDefaultValueAvailable()) {
            return $param->getDefaultValue();
        }

        if ($type instanceof ReflectionNamedType && $type->allowsNull()) {
            return null;
        }

        throw new \RuntimeException("Cannot resolve parameter \${$name} of {$class}");
    }
}
