<?php
namespace phpet\core;

use phpet\core\Exception\ExceptionHandler;
use phpet\core\Exception\NotFoundException;

class Router
{
    private array $routes = [];
    private array $middlewares = [];

    public function __construct(
        private ExceptionHandler $exceptionHandler
    )
    {
    }

    public function add(string $method, string $path, callable $callback)
    {
        $this->routes[$method][$path] = $callback;
        $this->routes['OPTIONS'][$path] = $callback;
    }

    public function dispatch(string $method, string $uri)
    {
        try {
            foreach ($this->routes[$method] ?? [] as $path => $handler) {
                $pattern = preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $path);
                $pattern = "#^{$pattern}$#";
                if (preg_match($pattern, $uri, $matches)) {
                    return $handler(
                        array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY)
                    );
                }
            }
            throw new NotFoundException('Route not found');
        } catch (\Throwable $e) {
            return $this->exceptionHandler->handle($e);
        }
    }

    public function addGlobalMiddleware(string $middleware):void
    {
        $this->middlewares[] = $middleware;
    }

    public function getGlobalMiddlewares():array
    {
        return $this->middlewares;
    }
}