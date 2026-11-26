<?php

namespace phpet\core;

use phpet\attribute\Controller;
use phpet\attribute\Middleware;
use phpet\attribute\request\FromBody;
use phpet\attribute\request\FromCookie;
use phpet\attribute\request\FromEach;
use phpet\attribute\request\FromHeader;
use phpet\attribute\request\FromQuery;
use phpet\attribute\Route;
use phpet\attribute\validate\ValidateGroup;
use phpet\core\Exception\BadRequestException;
use phpet\core\Exception\ValidationException;
use phpet\core\Log\Logger;
use phpet\core\middleware\MiddlewarePipeline;
use phpet\core\middleware\MiddlewareRegistry;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;

class ControllerScanner
{
    /** @var array<int, array{path:string, methods:string[], controller:string, action:string, middlewares:string[]}> */
    private array $definitions = [];

    public function __construct(
        private Router             $router,
        private MiddlewareRegistry $middlewareRegistry,
        private Container          $container,
    )
    {
    }

    /**
     * 递归扫描目录下所有带 #[Controller] 的类并注册路由。
     *
     * @return string[] 实际注册的控制器类名
     */
    public function scanDirectory(string $directory, string $namespace): array
    {
        $directory = rtrim($directory, '/\\');
        if (!is_dir($directory)) {
            throw new \RuntimeException("Controller directory not found: {$directory}");
        }

        $namespace = trim($namespace, '\\');
        $scanned = [];
        $this->definitions = [];

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
                continue;
            }

            $class = $this->classFromFile($file->getPathname(), $directory, $namespace);
            if ($class === null || !class_exists($class)) {
                Logger::debug('Skip non-loadable controller file', ['file' => $file->getPathname()]);
                continue;
            }

            $ref = new ReflectionClass($class);
            if (!$ref->isInstantiable() || $ref->getAttributes(Controller::class) === []) {
                continue;
            }

            $this->scan($class);
            $scanned[] = $class;
        }

        sort($scanned);

        // 扫描只负责产出定义，注册统一交给 registerDefinitions，
        // 与"从缓存加载"共用同一套逻辑，避免两条路径行为分叉
        $this->registerDefinitions($this->definitions);

        return $scanned;
    }

    /**
     * 按 PSR-4 约定由文件路径推导类名。
     */
    private function classFromFile(string $file, string $baseDir, string $namespace): ?string
    {
        $relative = substr($file, strlen($baseDir) + 1);
        if ($relative === '' || $relative === false) {
            return null;
        }

        $className = str_replace(['/', '\\'], '\\', preg_replace('#\.php$#i', '', $relative));

        return $namespace === '' ? $className : $namespace . '\\' . $className;
    }

    public function scan(string $controllerClass): void
    {
        $refClass = new ReflectionClass($controllerClass);

        // 类级注解只需解析一次，不必放进方法循环
        $prefix = '';
        $controllerAttrs = $refClass->getAttributes(Controller::class);
        if (!empty($controllerAttrs)) {
            /** @var Controller $controllerAttr */
            $prefix = rtrim($controllerAttrs[0]->newInstance()->prefix, '/');
        }
        $classMiddlewares = $this->collectMiddlewareNames(
            $refClass->getAttributes(Middleware::class)
        );

        foreach ($refClass->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            foreach ($method->getAttributes(Route::class) as $attr) {
                /** @var Route $route */
                $route = $attr->newInstance();

                $methods = array_values(array_unique(
                    array_map('strtoupper', $route->methods)
                ));

                $this->definitions[] = [
                    'path'        => $this->normalizePath($prefix, $route->path),
                    'methods'     => $methods,
                    'controller'  => $controllerClass,
                    'action'      => $method->getName(),
                    'middlewares' => array_merge(
                        $classMiddlewares,
                        $this->collectMiddlewareNames($method->getAttributes(Middleware::class))
                    ),
                ];
            }
        }
    }

    /**
     * 收集到的路由定义，可直接写入缓存。
     *
     * @return array<int, array{path:string, methods:string[], controller:string, action:string, middlewares:string[]}>
     */
    public function definitions(): array
    {
        return $this->definitions;
    }

    /**
     * 由定义注册路由。扫描与缓存加载共用此方法，保证两条路径行为一致。
     *
     * 缓存路径下不再实例化控制器、也不再反射所有方法，
     * 只有命中的那个方法才会在 invoke() 里反射一次。
     */
    public function registerDefinitions(array $definitions): void
    {
        foreach ($definitions as $def) {
            $controller = $def['controller'];
            $action = $def['action'];
            $path = $def['path'];
            $middlewares = $def['middlewares'] ?? [];

            foreach ($def['methods'] as $httpMethod) {
                $this->router->add(
                    $httpMethod,
                    $path,
                    fn(array $pathParams) => $this->invoke(
                        $controller,
                        $action,
                        $middlewares,
                        $pathParams
                    )
                );
            }

            // 预检占位路由：让 cors 中间件有机会短路返回 204；
            // 未启用 cors 时也返回 204 而不是 405
            $this->router->add(
                'OPTIONS',
                $path,
                fn() => $this->executeWithMiddleware(
                    $middlewares,
                    fn() => (new Response())->status(204)
                )
            );
        }
    }

    /**
     * 调用控制器方法。控制器实例按需从容器取（请求内单例）。
     */
    public function invoke(
        string $controllerClass,
        string $action,
        array  $middlewares,
        array  $pathParams
    ): Response
    {
        $method = new ReflectionMethod($controllerClass, $action);

        return $this->executeWithMiddleware(
            $middlewares,
            fn() => $method->invokeArgs(
                $this->container->get($controllerClass),
                $this->resolveArguments($method, $pathParams)
            )
        );
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

    private function executeWithMiddleware(array $middlewares, callable $handler): Response
    {
        $pipeline = new MiddlewarePipeline(
            $this->middlewareRegistry,
            $this->container->get(Request::class)
        );

        return $pipeline->handle($middlewares, $handler);
    }

    private function resolveArguments(
        ReflectionMethod $method,
        array            $pathParams
    ): array
    {
        $args = [];
        $request = $this->container->get(Request::class);
        $bodyData = $request->all();
        $headers = $request->headers();
        $cookies = $_COOKIE;

        foreach ($method->getParameters() as $param) {
            $name = $param->getName();
            $typeName = $this->typeName($param);

            // 1. 路径参数
            if (array_key_exists($name, $pathParams)) {
                $args[] = $this->castType($pathParams[$name], $param);
                continue;
            }

            // 2. Request 注入
            if ($typeName === Request::class) {
                $args[] = $request;
                continue;
            }

            // 3. 显式取参注解
            if ($this->resolveAnnotatedParameter($param, $args, $bodyData, $headers, $cookies)) {
                continue;
            }

            // 4. DTO 自动注入
            if ($typeName !== null
                && class_exists($typeName)
                && is_subclass_of($typeName, BaseDto::class)
            ) {
                $dto = $this->container
                    ->get(DtoResolver::class)
                    ->resolve($typeName);
                $this->container
                    ->get(Validator::class)
                    ->validate($dto, $this->resolveGroup($param));
                $args[] = $dto;
                continue;
            }

            // 5. 默认值
            if ($param->isDefaultValueAvailable()) {
                $args[] = $param->getDefaultValue();
                continue;
            }

            throw new \RuntimeException("Cannot resolve parameter \${$name}");
        }

        return $args;
    }

    /**
     * 处理 FromQuery / FromBody / FromHeader / FromCookie / FromEach。
     * 已消费该参数时返回 true。
     */
    private function resolveAnnotatedParameter(
        ReflectionParameter $param,
        array               &$args,
        array               $bodyData,
        array               $headers,
        array               $cookies
    ): bool
    {
        $name = $param->getName();

        $sources = [
            FromQuery::class  => fn(string $key) => $_GET[$key] ?? null,
            FromBody::class   => fn(string $key) => $bodyData[$key] ?? null,
            FromHeader::class => fn(string $key) => $headers[strtolower($key)] ?? null,
            FromCookie::class => fn(string $key) => $cookies[$key] ?? null,
        ];

        foreach ($sources as $attributeClass => $read) {
            $attr = $param->getAttributes($attributeClass)[0] ?? null;
            if ($attr === null) {
                continue;
            }

            $key = $attr->newInstance()->name ?? $name;
            $value = $read($key);

            if ($value !== null) {
                $args[] = $this->castType($value, $param);
                return true;
            }
            // 该参数声明了取值来源但没取到，交给后续默认值分支，不再尝试其它来源
            return false;
        }

        // FromEach：请求体中的数组 -> DTO 数组（逐项校验）
        $eachAttr = $param->getAttributes(FromEach::class)[0] ?? null;
        if ($eachAttr !== null) {
            $args[] = $this->resolveDtoList($param, $eachAttr->newInstance(), $bodyData);
            return true;
        }

        return false;
    }

    private function resolveDtoList(
        ReflectionParameter $param,
        object              $each,
        array               $bodyData
    ): array
    {
        $name = $param->getName();
        $key = $each->name ?? $name;
        $dtoClass = $each->dto;

        if ($dtoClass === null) {
            throw new \RuntimeException("FromEach on \${$name} requires a dto class");
        }
        if (!class_exists($dtoClass) || !is_subclass_of($dtoClass, BaseDto::class)) {
            throw new \RuntimeException(
                "FromEach dto {$dtoClass} must extend " . BaseDto::class
            );
        }

        $raw = $bodyData[$key] ?? null;
        if (!is_array($raw)) {
            throw new BadRequestException("\${$name} must be an array");
        }

        $group = $this->resolveGroup($param);
        $resolver = $this->container->get(DtoResolver::class);
        $validator = $this->container->get(Validator::class);

        $items = [];
        $errors = [];

        foreach ($raw as $index => $item) {
            if (!is_array($item)) {
                throw new BadRequestException("\${$name}[{$index}] must be an object");
            }
            // 显式传入 item，避免嵌套字段回落到全局 body
            $dto = $resolver->resolve($dtoClass, $item);
            $items[] = $dto;

            try {
                // 带索引前缀，出错时能定位到具体第几条
                $validator->validate($dto, $group, "{$key}.{$index}");
            } catch (ValidationException $e) {
                // 收集所有条目的错误一次性抛出，而不是遇到第一条就中断
                $errors += $e->errors;
            }
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return $items;
    }

    private function resolveGroup(ReflectionParameter $param): string
    {
        $attrs = $param->getAttributes(ValidateGroup::class);
        return $attrs ? $attrs[0]->newInstance()->group : 'default';
    }

    private function typeName(ReflectionParameter $param): ?string
    {
        $type = $param->getType();
        return $type instanceof ReflectionNamedType ? $type->getName() : null;
    }

    private function castType($value, ReflectionParameter $param)
    {
        $type = $param->getType();

        if (!$type instanceof ReflectionNamedType) {
            return $value;
        }

        return match ($type->getName()) {
            'int' => (int)$value,
            'float' => (float)$value,
            'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'string' => is_scalar($value) ? (string)$value : $value,
            default => $value,
        };
    }
}
