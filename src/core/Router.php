<?php

namespace phpet\core;

use phpet\core\Exception\ExceptionHandler;
use phpet\core\Exception\MethodNotAllowedException;
use phpet\core\Exception\NotFoundException;

class Router
{
    /**
     * 全部路由，键为 "METHOD /path"，按注册顺序保存；重复注册会覆盖而非追加
     * @var array<string, array{method: string, path: string, regex: string, params: string[], handler: callable}>
     */
    private array $routes = [];

    /** @var array<string, callable> 静态路由快表，键为 "METHOD /path" */
    private array $static = [];

    /** @var array<string, string|null> 路径 => 正则（静态路径为 null），用于 405 判定 */
    private array $pathPatterns = [];

    /** @var array<string, array<string, bool>> 路径 => 允许的方法 */
    private array $pathMethods = [];

    public function __construct(
        private ExceptionHandler $exceptionHandler
    )
    {
    }

    /**
     * @param array<string, mixed> $meta 附加信息（控制器名、动作名等），只用于展示与调试
     */
    public function add(string $method, string $path, callable $handler, array $meta = []): void
    {
        $method = strtoupper($method);
        $path = $this->normalize($path);

        $params = [];
        $regex = $this->compile($path, $params);

        $this->routes[$method . ' ' . $path] = [
            'method' => $method,
            'path' => $path,
            'regex' => $regex,
            'params' => $params,
            'handler' => $handler,
            'meta' => $meta,
        ];

        $this->pathPatterns[$path] = $params === [] ? null : $regex;
        $this->pathMethods[$path][$method] = true;

        if ($params === []) {
            $this->static[$method . ' ' . $path] = $handler;
        }
    }

    public function dispatch(string $method, string $uri)
    {
        $method = strtoupper($method !== '' ? $method : 'GET');
        $path = $this->normalize($this->pathOf($uri));

        // HEAD 复用 GET 的处理器，响应体在 Response::send 阶段丢弃
        $lookup = $method === 'HEAD' ? 'GET' : $method;

        try {
            $staticKey = $lookup . ' ' . $path;
            if (isset($this->static[$staticKey])) {
                return ($this->static[$staticKey])([]);
            }

            foreach ($this->routes as $route) {
                if ($route['method'] !== $lookup) {
                    continue;
                }
                if (preg_match($route['regex'], $path, $matches) !== 1) {
                    continue;
                }

                $params = [];
                foreach ($route['params'] as $paramName) {
                    $params[$paramName] = $matches[$paramName] ?? null;
                }

                return ($route['handler'])($params);
            }

            $allowed = $this->allowedMethods($path);
            if ($allowed !== []) {
                if (!in_array('OPTIONS', $allowed, true)) {
                    $allowed[] = 'OPTIONS';
                }
                throw new MethodNotAllowedException($allowed);
            }

            throw new NotFoundException('Route not found');
        } catch (\Throwable $e) {
            return $this->exceptionHandler->handle($e);
        }
    }

    /**
     * 已注册路由数（便于测试与调试）
     */
    public function count(): int
    {
        return count($this->routes);
    }

    /**
     * 已注册路由的只读快照，按注册顺序。
     *
     * 只暴露 method / path / handler，正则与参数名属于内部实现细节。
     *
     * @return array<int, array{method: string, path: string, handler: callable, meta: array<string, mixed>}>
     */
    public function routes(): array
    {
        $snapshot = [];

        foreach ($this->routes as $route) {
            $snapshot[] = [
                'method'  => $route['method'],
                'path'    => $route['path'],
                'handler' => $route['handler'],
                'meta'    => $route['meta'],
            ];
        }

        return $snapshot;
    }

    /**
     * 只取 URI 的路径部分。
     * 不能用 parse_url()：`//api/x` 会被解析成 host=api、path=/x。
     */
    private function pathOf(string $uri): string
    {
        $path = explode('?', $uri, 2)[0];
        $path = explode('#', $path, 2)[0];

        return $path === '' ? '/' : $path;
    }

    /**
     * 统一路径格式：单前导斜杠、折叠重复斜杠、去掉尾斜杠。
     */
    private function normalize(string $path): string
    {
        $path = '/' . ltrim($path, '/');
        $path = preg_replace('#/+#', '/', $path) ?? $path;

        if ($path === '/') {
            return '/';
        }

        return rtrim($path, '/');
    }

    /**
     * 把 /user/{id:\d+} 编译为正则，静态片段做转义。
     *
     * @param string[] $params 输出：按顺序收集的占位符名
     */
    private function compile(string $path, array &$params): string
    {
        $parts = preg_split(
            '#(\{[a-zA-Z_]\w*(?::[^}]+)?\})#',
            $path,
            -1,
            PREG_SPLIT_DELIM_CAPTURE
        ) ?: [$path];

        $regex = '';
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }

            if (preg_match('#^\{([a-zA-Z_]\w*)(?::([^}]+))?\}$#', $part, $m) === 1) {
                $params[] = $m[1];
                $pattern = $m[2] ?? '[^/]+';
                $regex .= '(?P<' . $m[1] . '>' . $pattern . ')';
                continue;
            }

            $regex .= preg_quote($part, '#');
        }

        return '#^' . $regex . '$#';
    }

    /**
     * 匹配到路径但方法不对时，返回该路径允许的方法列表。
     *
     * @return string[]
     */
    private function allowedMethods(string $path): array
    {
        $allowed = [];

        foreach ($this->pathMethods as $routePath => $methods) {
            $regex = $this->pathPatterns[$routePath];

            $hit = $regex === null
                ? $routePath === $path
                : preg_match($regex, $path) === 1;

            if ($hit) {
                $allowed += $methods;
            }
        }

        return array_keys($allowed);
    }
}
