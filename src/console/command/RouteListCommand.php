<?php

namespace phpet\console\command;

use phpet\console\Command;
use phpet\console\Input;
use phpet\console\Output;

/**
 * 打印已注册的全部路由。
 *
 * 走的是与线上请求完全相同的装配流程（含路由缓存），所以这里看到的
 * 就是真实生效的那张表，而不是「理论上应该注册的」。
 */
final class RouteListCommand extends Command
{
    public function name(): string
    {
        return 'route:list';
    }

    public function description(): string
    {
        return 'List registered routes';
    }

    public function usage(): string
    {
        return 'console route:list [--method=GET] [--all]';
    }

    public function run(Input $input, Output $output): int
    {
        $app = $this->app();
        $app->boot();

        $router = $app->router();
        $routes = $router?->routes() ?? [];

        $method = $input->option('method');
        $method = is_string($method) ? strtoupper($method) : null;
        $showGenerated = $input->hasOption('all');

        $rows = [];
        $hidden = 0;

        foreach ($routes as $route) {
            if ($method !== null && $route['method'] !== $method) {
                continue;
            }

            // 每个路径的 OPTIONS 占位路由是框架自动补的，默认不列，免得盖住真实路由
            if (!$showGenerated && ($route['meta']['generated'] ?? false) === true) {
                $hidden++;
                continue;
            }

            $rows[] = [
                $route['method'],
                $route['path'],
                $this->describeHandler($route),
            ];
        }

        if ($rows === []) {
            $output->warn($routes === []
                ? 'No routes registered.'
                : "No routes match method {$method}.");

            return 0;
        }

        $output->table(['Method', 'Path', 'Handler'], $rows);
        $output->newline();

        $note = count($rows) . ' route(s).';
        if ($hidden > 0) {
            $note .= " {$hidden} generated OPTIONS route(s) hidden, use --all to show.";
        }

        $output->dim($note);

        return 0;
    }

    /**
     * @param array{method: string, path: string, handler: callable, meta: array<string, mixed>} $route
     */
    private function describeHandler(array $route): string
    {
        $meta = $route['meta'];

        if (isset($meta['controller'], $meta['action'])) {
            $short = substr((string)$meta['controller'], (int)strrpos((string)$meta['controller'], '\\') + 1);

            return $short . '::' . $meta['action'];
        }

        return $this->describeCallable($route['handler']);
    }

    private function describeCallable(mixed $handler): string
    {
        if ($handler instanceof \Closure) {
            return 'Closure';
        }

        if (is_array($handler) && count($handler) === 2) {
            $class = is_object($handler[0]) ? $handler[0]::class : (string)$handler[0];

            return $class . '::' . (string)$handler[1];
        }

        if (is_string($handler)) {
            return $handler;
        }

        if (is_object($handler) && method_exists($handler, '__invoke')) {
            return $handler::class . '::__invoke';
        }

        return get_debug_type($handler);
    }
}
