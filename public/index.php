<?php

namespace app;

use app\controller\UserController;
use app\middleware\AuthMiddleware;
use app\middleware\CorsMiddleware;
use app\middleware\LogMiddleware;
use phpet\core\Container;
use phpet\core\ControllerScanner;
use phpet\core\Exception\ExceptionHandler;
use phpet\core\Log\Logger;
use phpet\core\middleware\MiddlewareRegistry;
use phpet\core\Request;
use phpet\core\Response;
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

$container = new Container();
$request = Request::fromGlobals();
$container->singleton(Request::class, $request);

$registry = new MiddlewareRegistry();
$registry->register('auth', AuthMiddleware::class);
$registry->register('log', LogMiddleware::class);
// 需要跨域携带 Cookie 时，改为：register('cors', CorsMiddleware::class, [['https://your.site'], true])
$registry->register('cors', CorsMiddleware::class);

// ExceptionHandler 可自定义；debug 模式下 500 响应会附带异常细节
$router = new Router(new ExceptionHandler($debug));
$router->addGlobalMiddleware('cors');
$scanner = new ControllerScanner($router, $registry, $container);

// 扫描控制器（当前为手工登记，后续可扩展为目录扫描）
$scanner->scan(UserController::class);

try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

    $response = $router->dispatch($method, $uri);

    if ($response instanceof Response) {
        $response->send();
        exit;
    }

    // 兜底兼容（字符串 / 数组）
    Response::text(is_scalar($response) ? (string)$response : '')
        ->send();
} catch (\Throwable $e) {
    (new ExceptionHandler($debug))->handle($e)->send();
}
