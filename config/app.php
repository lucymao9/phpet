<?php

use app\middleware\AuthMiddleware;
use app\middleware\CorsMiddleware;
use app\middleware\LogMiddleware;

// web 入口（public/index.php）与控制台（bin/console）共用这份配置，
// 避免两边各写一套、改了一边忘另一边。

$root = dirname(__DIR__);

return [
    'basePath' => $root,

    // 名字 => 类名，或 名字 => [类名, [构造参数]]
    'middlewares' => [
        'auth' => AuthMiddleware::class,
        'log'  => LogMiddleware::class,

        // 需要跨域携带 Cookie 时把来源写成具体域名并开启 credentials：
        // 'cors' => [CorsMiddleware::class, [['https://your.site'], true]],
        'cors' => CorsMiddleware::class,
    ],

    // 包在路由外层：404 / 405 这类未命中路由的响应也能带上 CORS 头
    'globalMiddlewares' => ['cors'],

    // 数据库。默认 sqlite，不需要起任何服务，第一次连接时自动建库文件。
    // 换 MySQL：DB_DRIVER=mysql，再按需设 DB_HOST / DB_DATABASE / DB_USERNAME / DB_PASSWORD。
    'db' => [
        'driver'   => getenv('DB_DRIVER') ?: 'sqlite',
        'database' => getenv('DB_DATABASE') ?: $root . '/database/phpet.sqlite',
        'host'     => getenv('DB_HOST') ?: '127.0.0.1',
        'port'     => (int)(getenv('DB_PORT') ?: 3306),
        'username' => getenv('DB_USERNAME') ?: 'root',
        'password' => getenv('DB_PASSWORD') ?: '',
        'charset'  => getenv('DB_CHARSET') ?: 'utf8mb4',
    ],

    // 通用缓存目录（路由缓存是另一个文件，见 cachePath）
    'cacheDir' => $root . '/runtime/cache/store',
];
