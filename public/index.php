<?php
namespace app;
require_once __DIR__ . '/../vendor/autoload.php';

use app\controller\UserController;
use app\middleware\AuthMiddleware;
use app\middleware\CorsMiddleware;
use app\middleware\LogMiddleware;
use phpet\core\Container;
use phpet\core\ControllerScanner;
use phpet\core\Exception\ExceptionHandler;
use phpet\core\middleware\MiddlewareRegistry;
use phpet\core\Request;
use phpet\core\Response;
use phpet\core\Router;


$container = new Container();
$request = Request::fromGlobals();
$container->singleton(Request::class, $request);


$registry = new MiddlewareRegistry();
$registry->register('auth', AuthMiddleware::class);
$registry->register('log', LogMiddleware::class);
$registry->register('cors', CorsMiddleware::class);
//ExceptionHandler 可自定义
$router = new Router(new ExceptionHandler());
$router->addGlobalMiddleware('cors');
$scanner = new ControllerScanner($router, $registry, $container);

// 扫描控制器
$scanner->scan(UserController::class);

// 模拟请求
$method = $_SERVER['REQUEST_METHOD'];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$response = $router->dispatch($method, $uri);

if ($response instanceof Response) {
    $response->send();
} else {
    // 兜底兼容（字符串）
    echo $response;
}