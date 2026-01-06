<?php

namespace phpet\core;

use phpet\attribute\Controller;
use phpet\attribute\Middleware;
use phpet\attribute\request\FromBody;
use phpet\attribute\request\FromCookie;
use phpet\attribute\request\FromHeader;
use phpet\attribute\request\FromQuery;
use phpet\attribute\Route;
use phpet\attribute\request\FromEach;
use phpet\attribute\validate\ValidateGroup;
use phpet\core\middleware\MiddlewareRegistry;
use ReflectionClass;
use ReflectionMethod;

class ControllerScanner
{
    public function __construct(
        private Router             $router,
        private MiddlewareRegistry $middlewareRegistry,
        private Container          $container,
    )
    {
    }

    public function scan(string $controllerClass)
    {
        $refClass = new ReflectionClass($controllerClass);
        $controller = $this->container->get($controllerClass);
        foreach ($refClass->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            foreach ($method->getAttributes(Route::class) as $attr) {
                /** @var Route $route */
                $route = $attr->newInstance();
                // 控制器前缀
                $prefix = '';
                $controllerAttrs = $refClass->getAttributes(Controller::class);
                if (!empty($controllerAttrs)) {
                    /** @var Controller $controllerAttrs */
                    $prefix = rtrim($controllerAttrs[0]->newInstance()->prefix, '/');
                }
                $path = $this->normalizePath(
                    $prefix,
                    $route->path
                );
                $globalMiddlewares = $this->router->getGlobalMiddlewares();
                // 类级 middleware
                $classMiddlewares = $this->collectMiddlewareNames(
                    $refClass->getAttributes(Middleware::class)
                );
                foreach ($route->methods as $httpMethod) {
                    $methodMiddlewares = $this->collectMiddlewareNames(
                        $method->getAttributes(Middleware::class)
                    );
                    $middlewares = array_merge($globalMiddlewares, $classMiddlewares, $methodMiddlewares,);

                    $this->router->add(
                        $httpMethod,
                        $path,
                        function (array $pathParams) use ($controller, $method, $middlewares) {
                            return $this->executeWithMiddleware(
                                $middlewares,
                                fn() => $method->invokeArgs($controller, $this->resolveArguments($method, $pathParams))
                            );
                        }
                    );
                }
            }
        }
    }

    private function normalizePath(string $prefix, string $path): string
    {
        return '/' . trim($prefix . '/' . trim($path, '/'), '/');
    }

    private function collectMiddlewareNames(array $attributes): array
    {
        return array_map(
            fn($attr) => $attr->newInstance()->name,
            $attributes
        );
    }

    private function executeWithMiddleware(array $middlewares, callable $handler)
    {
        $result = $exception = null;
        $instances = array_map(
            fn($name) => $this->middlewareRegistry->resolve($name),
            $middlewares
        );
        $request = $this->container->get(Request::class);
        try {
            foreach ($instances as $m) {
                $m->before($request);
            }

            $result = $handler();

            if (!$result instanceof Response) {
                $result = Response::text((string)$result);
            }
        }catch (\Throwable $e){
            $exception = $e;
            throw $e;
        } finally {
            foreach (array_reverse($instances) as $m) {
                $result = $m->after($result,$exception);
            }
        }


        return $result;
    }

    private function resolveArguments(
        \ReflectionMethod $method,
        array             $pathParams
    ): array
    {
        $args = [];

        // 解析 body（只解析一次）
        $rawBody = file_get_contents('php://input');
        $bodyData = json_decode($rawBody, true) ?? [];

        $headers = getallheaders();
        $cookies = $_COOKIE;

        foreach ($method->getParameters() as $param) {
            $name = $param->getName();

            // 路径参数
            if (isset($pathParams[$name])) {
                $args[] = $this->castType($pathParams[$name], $param);
                continue;
            }

            if ($param->getType()
                && (string)$param->getType() === Request::class
            ) {
                $args[] = $this->container->get(Request::class);
                continue;
            }

            // FromQuery
            $queryAttr = $param->getAttributes(FromQuery::class)[0] ?? null;
            if ($queryAttr) {
                $key = $queryAttr->newInstance()->name ?? $name;
                $value = $_GET[$key] ?? null;

                if ($value !== null) {
                    $args[] = $this->castType($value, $param);
                    continue;
                }
            }

            // FromBody
            $bodyAttr = $param->getAttributes(FromBody::class)[0] ?? null;
            if ($bodyAttr) {
                $key = $bodyAttr->newInstance()->name ?? $name;
                $value = $bodyData[$key] ?? null;

                if ($value !== null) {
                    $args[] = $this->castType($value, $param);
                    continue;
                }
            }

            // FromHeader
            $headerAttr = $param->getAttributes(FromHeader::class)[0] ?? null;
            if ($headerAttr) {
                $key = $headerAttr->newInstance()->name ?? $name;
                $value = $headers[$key] ?? null;

                if ($value !== null) {
                    $args[] = $this->castType($value, $param);
                    continue;
                }
            }

            $cookieAttr = $param->getAttributes(FromCookie::class)[0] ?? null;
            if ($cookieAttr) {
                $key = $cookieAttr->newInstance()->name ?? $name;
                $value = $cookies[$key] ?? null;

                if ($value !== null) {
                    $args[] = $this->castType($value, $param);
                    continue;
                }
            }

            // Each
            $eachAttr = $param->getAttributes(FromEach::class)[0] ?? null;
            if ($eachAttr) {
                $key = $eachAttr->newInstance()->name ?? $name;
                $dto = $eachAttr->newInstance()->dto;
                $value = $bodyData[$key] ?? null;
                if (class_exists($dto)
                    && is_subclass_of($dto, BaseDto::class)
                ) {
                if ($value !== null) {
                    $args[] = $this->castType($value, $param);
                    continue;
                }}
            }

            // DTO 自动注入
            if ($param->getType()
                && class_exists($type = (string)$param->getType())
                && is_subclass_of($type, BaseDto::class)
            ) {
                $group = 'default';
                $attrs = $param->getAttributes(ValidateGroup::class);
                if ($attrs) {
                    $group = $attrs[0]->newInstance()->group;
                }
                $args[] = $this->container
                    ->get(DtoResolver::class)
                    ->resolve($type);
                $this->container->get(Validator::class)->validate($dto, $group);
                continue;
            }

            // 默认值
            if ($param->isDefaultValueAvailable()) {
                $args[] = $param->getDefaultValue();
                continue;
            }

            throw new \RuntimeException("Cannot resolve parameter \${$name}");
        }

        return $args;
    }

    private function castType($value, \ReflectionParameter $param)
    {
        $type = $param->getType();

        if (!$type) {
            return $value;
        }

        return match ((string)$type) {
            'int' => (int)$value,
            'float' => (float)$value,
            'bool' => (bool)$value,
            'string' => (string)$value,
            default => $value,
        };
    }
}