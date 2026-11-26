<?php

namespace app;

use app\middleware\AuthMiddleware;
use app\middleware\CorsMiddleware;
use app\middleware\LogMiddleware;
use phpet\core\Container;
use phpet\core\ControllerScanner;
use phpet\core\Exception\ExceptionHandler;
use phpet\core\Log\Logger;
use phpet\core\middleware\MiddlewarePipeline;
use phpet\core\middleware\MiddlewareRegistry;
use phpet\core\Request;
use phpet\core\Response;
use phpet\core\RouteCache;
use phpet\core\Router;

// 输出缓冲：控制器里任何意外的 echo 都不会提前发送响应头，
// 最终由 Response::send() 统一清理并输出
ob_start();

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!is_file($autoload)) {
    http_response_code(500);
    exit('vendor/autoload.php not found, run "composer install" first.');
}
require_once $autoload;

$debug = in_array(
    getenv('APP_DEBUG') ?: '',
    ['1', 'true', 'on'],
    true
);

// 统一时区，避免日志时间与业务时间差 8 小时
date_default_timezone_set(getenv('APP_TIMEZONE') ?: 'Asia/Shanghai');

Logger::init(null, $debug ? Logger::DEBUG : Logger::INFO);

// ExceptionHandler 可自定义；debug 模式下 500 响应会附带异常细节
$exceptionHandler = new ExceptionHandler($debug);

try {
    $container = new Container();

    // 中间件注册提前到解析请求之前：请求体解析失败时也补得上 CORS 头
    $registry = new MiddlewareRegistry();
    $registry->register('auth', AuthMiddleware::class);
    $registry->register('log', LogMiddleware::class);
    // 需要跨域携带 Cookie 时：
    // $registry->register('cors', CorsMiddleware::class, [['https://your.site'], true]);
    $registry->register('cors', CorsMiddleware::class);

    $request = Request::fromGlobals();
    $container->singleton(Request::class, $request);

    $router = new Router($exceptionHandler);
    $scanner = new ControllerScanner($router, $registry, $container);

    // 扫描 app/controller 下所有带 #[Controller] 的类
    $controllerDir = __DIR__ . '/../app/controller';
    $cache = new RouteCache(RouteCache::defaultPath());
    $signature = $cache->signature($controllerDir);

    // debug 模式跳过缓存：改完控制器立刻生效，不用担心签名判定的时间粒度
    if (!$debug && $cache->isFresh($signature)) {
        $definitions = $cache->load();
        $scanner->registerDefinitions($definitions);
        Logger::debug('Routes loaded from cache', [
            'file'   => $cache->path(),
            'routes' => count($definitions),
        ]);
    } else {
        $controllers = $scanner->scanDirectory($controllerDir, 'app\controller');
        if ($controllers === []) {
            throw new \RuntimeException('No controllers found under ' . $controllerDir);
        }

        if (!$debug) {
            $cache->save($signature, $scanner->definitions());
        }
    }

    // 全局中间件包在路由外层，404/405 等未命中路由的响应也能带上 CORS 头
    $pipeline = new MiddlewarePipeline($registry, $request);
    $response = $pipeline->handle(
        ['cors'],
        fn() => $router->dispatch($request->method(), $request->path())
    );

    if (!$response instanceof Response) {
        // 兜底兼容（字符串 / 数组）
        $response = is_array($response)
            ? Response::json($response)
            : Response::text(is_scalar($response) ? (string)$response : '');
    }

    $response->send();
} catch (\Throwable $e) {
    $response = $exceptionHandler->handle($e);

    // 引导阶段就失败时，尽量补上 CORS 头，避免浏览器只看到跨域错误
    try {
        $response = $registry->resolve('cors')->after($response) ?? $response;
    } catch (\Throwable) {
        // 中间件不可用时忽略
    }

    $response->send();
}
