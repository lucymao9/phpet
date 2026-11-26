<?php

namespace phpet\core;

use phpet\core\Exception\ExceptionHandler;
use phpet\core\Log\Logger;
use phpet\core\middleware\MiddlewarePipeline;
use phpet\core\middleware\MiddlewareRegistry;

/**
 * 应用内核：把「装配 + 分发 + 输出」从入口脚本里收拢到一处。
 *
 * 入口脚本只需要 new Application([...])->run()；
 * 测试或嵌入场景可以用 handle($request) 直接拿 Response，不经过 SAPI。
 *
 * 所有路径都从 basePath 推导，不再依赖框架自身的安装位置，
 * 因此放在 vendor/ 下也能正常工作。
 */
class Application
{
    private const OPTIONS = [
        'basePath',
        'debug',
        'controllerDir',
        'controllerNamespace',
        'cachePath',
        'cache',
        'logDir',
        'logLevel',
        'timezone',
        'middlewares',
        'globalMiddlewares',
        'exceptionHandler',
        'container',
    ];

    private string $basePath;
    private bool $debug;
    private string $controllerDir;
    private string $controllerNamespace;
    private string $cachePath;
    private bool $cacheEnabled;
    private string $logDir;
    private string $logLevel;
    private string $timezone;

    /** @var array<string, string|array{class: string, params: array}> */
    private array $middlewares;

    /** @var string[] */
    private array $globalMiddlewares;

    private ExceptionHandler $exceptionHandler;
    private Container $container;

    private ?Router $router = null;
    private ?MiddlewareRegistry $registry = null;

    /**
     * @param array{
     *     basePath?: string,
     *     debug?: bool,
     *     controllerDir?: string,
     *     controllerNamespace?: string,
     *     cachePath?: string,
     *     cache?: bool,
     *     logDir?: string,
     *     logLevel?: string,
     *     timezone?: string,
     *     middlewares?: array,
     *     globalMiddlewares?: string[],
     *     exceptionHandler?: ExceptionHandler,
     *     container?: Container
     * } $options
     */
    public function __construct(array $options = [])
    {
        $unknown = array_diff(array_keys($options), self::OPTIONS);
        if ($unknown !== []) {
            // 配置项写错时立刻报错，比「设置没生效但也不报错」好排查得多
            throw new \InvalidArgumentException(
                'Unknown Application option(s): ' . implode(', ', $unknown)
                . '. Supported: ' . implode(', ', self::OPTIONS)
            );
        }

        $basePath = (string)($options['basePath'] ?? getcwd());

        $this->basePath = rtrim($basePath, '/\\');
        $this->debug = (bool)($options['debug'] ?? self::envFlag('APP_DEBUG'));

        $this->controllerDir = $options['controllerDir']
            ?? $this->basePath . '/app/controller';
        $this->controllerNamespace = trim(
            $options['controllerNamespace'] ?? 'app\controller',
            '\\'
        );

        $this->cachePath = $options['cachePath']
            ?? RouteCache::defaultPath($this->basePath);
        $this->cacheEnabled = (bool)($options['cache'] ?? true);

        $this->logDir = $options['logDir'] ?? Logger::defaultDir($this->basePath);
        $this->logLevel = $options['logLevel']
            ?? ($this->debug ? Logger::DEBUG : Logger::INFO);

        $this->timezone = $options['timezone']
            ?? (getenv('APP_TIMEZONE') ?: 'Asia/Shanghai');

        $this->middlewares = $options['middlewares'] ?? [];
        $this->globalMiddlewares = $options['globalMiddlewares'] ?? [];

        $this->exceptionHandler = $options['exceptionHandler']
            ?? new ExceptionHandler($this->debug);
        $this->container = $options['container'] ?? new Container();
    }

    /**
     * 处理当前全局请求并输出响应。入口脚本只需要调用这一个方法。
     */
    public function run(): void
    {
        // 输出缓冲：控制器里意外的 echo 不会提前发送响应头，
        // 最终由 Response::send() 统一清理
        ob_start();

        try {
            $response = $this->handle(Request::fromGlobals());
        } catch (\Throwable $e) {
            $response = $this->failure($e);
        }

        $response->send();
    }

    /**
     * 处理一次请求，返回响应对象（不输出、不发响应头）。
     *
     * 首次调用会完成全部装配，之后的调用复用同一套路由表。
     */
    public function handle(Request $request): Response
    {
        $this->boot($request);

        /** @var Response $response */
        $response = (new MiddlewarePipeline($this->registry, $request))->handle(
            $this->globalMiddlewares,
            fn() => $this->router->dispatch($request->method(), $request->path())
        );

        return $response;
    }

    public function basePath(): string
    {
        return $this->basePath;
    }

    public function isDebug(): bool
    {
        return $this->debug;
    }

    public function container(): Container
    {
        return $this->container;
    }

    public function exceptionHandler(): ExceptionHandler
    {
        return $this->exceptionHandler;
    }

    /**
     * 已装配的路由表；未装配时为 null。
     */
    public function router(): ?Router
    {
        return $this->router;
    }

    /* ------------------------------------------------------------------ */

    private function boot(Request $request): void
    {
        // 每次请求都覆盖 Request 单例，让控制器注入到的总是当次请求
        $this->container->singleton(Request::class, $request);

        if ($this->router !== null) {
            return;
        }

        date_default_timezone_set($this->timezone);
        Logger::init($this->logDir, $this->logLevel);

        $registry = $this->buildRegistry();
        $router = new Router($this->exceptionHandler);
        $scanner = new ControllerScanner($router, $registry, $this->container);

        // debug 模式完全绕过缓存：改完控制器立刻生效，
        // 不用去猜签名判定的时间粒度
        if ($this->cacheEnabled && !$this->debug) {
            $cache = new RouteCache($this->cachePath);
            $signature = $cache->signature($this->controllerDir);

            if ($cache->isFresh($signature)) {
                $definitions = $cache->load();
                $scanner->registerDefinitions($definitions);
                Logger::debug('Routes loaded from cache', [
                    'file'   => $cache->path(),
                    'routes' => count($definitions),
                ]);
            } else {
                $this->scan($scanner);
                $cache->save($signature, $scanner->definitions());
            }
        } else {
            $this->scan($scanner);
        }

        $this->registry = $registry;
        $this->router = $router;
    }

    private function scan(ControllerScanner $scanner): void
    {
        $controllers = $scanner->scanDirectory(
            $this->controllerDir,
            $this->controllerNamespace
        );

        if ($controllers === []) {
            throw new \RuntimeException(
                'No controllers found under ' . $this->controllerDir
            );
        }
    }

    private function buildRegistry(): MiddlewareRegistry
    {
        $registry = new MiddlewareRegistry();

        foreach ($this->middlewares as $name => $config) {
            if (is_string($config)) {
                $registry->register($name, $config);
                continue;
            }

            // [class, params] 或 ['class' => ..., 'params' => [...]]
            $class = $config['class'] ?? $config[0] ?? null;
            $params = $config['params'] ?? $config[1] ?? [];

            if (!is_string($class)) {
                throw new \InvalidArgumentException(
                    "Middleware {$name} must be a class name or [class, params]"
                );
            }

            $registry->register($name, $class, (array)$params);
        }

        return $registry;
    }

    /**
     * 装配阶段就失败时的兜底响应。
     */
    private function failure(\Throwable $e): Response
    {
        $response = $this->exceptionHandler->handle($e);

        // 例如请求体解析失败：此时路由还没建起来，但全局中间件的收尾
        // （最典型的是 CORS 头）仍该补上，否则浏览器只看到一个跨域错误
        foreach ($this->globalMiddlewares as $name) {
            try {
                $after = $this->registry?->resolve($name)->after($response, $e);
                if ($after instanceof Response) {
                    $response = $after;
                }
            } catch (\Throwable) {
                // 中间件本身不可用时忽略，不能因为补头失败再抛一次
            }
        }

        return $response;
    }

    private static function envFlag(string $name): bool
    {
        return in_array(
            getenv($name) ?: '',
            ['1', 'true', 'on'],
            true
        );
    }
}
