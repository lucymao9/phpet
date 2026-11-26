<?php

use app\middleware\AuthMiddleware;
use app\middleware\CorsMiddleware;
use app\middleware\LogMiddleware;
use phpet\core\Application;

require dirname(__DIR__) . '/bootstrap/autoload.php';

(new Application([
    'basePath'    => dirname(__DIR__),
    'middlewares' => [
        'auth' => AuthMiddleware::class,
        'log'  => LogMiddleware::class,
        // 需要跨域携带 Cookie 时把来源写成具体域名并开启 credentials：
        // 'cors' => [CorsMiddleware::class, [['https://your.site'], true]],
        'cors' => CorsMiddleware::class,
    ],
    // 全局中间件包在路由外层，404 / 405 这类未命中路由的响应也能带上 CORS 头
    'globalMiddlewares' => ['cors'],
]))->run();
