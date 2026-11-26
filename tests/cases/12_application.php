<?php
declare(strict_types=1);

use fixtures\Recorder;
use fixtures\RecordingMiddleware;
use fixtures\RetagMiddleware;
use fixtures\ShortCircuitMiddleware;
use phpet\cache\FileCache;
use phpet\core\Application;
use phpet\core\Container;
use phpet\core\Exception\ExceptionHandler;
use phpet\core\Log\Logger;
use phpet\core\Request;
use phpet\core\RouteCache;

/**
 * 在一个独立 basePath 下装配 Application，结束后清理。
 * 回调签名：($app, $basePath)
 */
function withApp(array $overrides, callable $fn)
{
    $base = mktmp('phpet-app-');

    $options = array_merge([
        'basePath'            => $base,
        'controllerDir'       => PHPET_TESTS . '/fixtures/controllers',
        'controllerNamespace' => 'fixtures\controllers',
        'middlewares'         => [
            'rec'   => RecordingMiddleware::class,
            'block' => ShortCircuitMiddleware::class,
            'retag' => RetagMiddleware::class,
        ],
        'logDir'              => sys_get_temp_dir() . '/phpet-tests-logs',
    ], $overrides);

    try {
        return $fn(new Application($options), $base);
    } finally {
        rmtree($base);
    }
}

function appRequest(string $uri, string $method = 'GET', array $body = []): Request
{
    return new Request([], $body, [
        'REQUEST_URI'     => $uri,
        'REQUEST_METHOD'  => $method,
    ], []);
}

/* ------------------------------------------------------------------ *
 * 配置
 * ------------------------------------------------------------------ */

test('路径类配置默认从 basePath 推导', function () {
    withApp([], function (Application $app, string $base) {
        assertSame($base, $app->basePath());

        $app->handle(appRequest('/api/items/1'));

        // 默认 logDir / cacheDir 都落在 basePath 下，路由表存进 cacheDir
        assertTrue(is_dir($base . '/runtime/cache/store'), '默认缓存目录应在 basePath 下');
        assertTrue($app->cache()->has(RouteCache::DEFAULT_KEY), '路由表应落进通用缓存');
    });
});

test('cachePath 已移除，报错要指出替代方案', function () {
    $e = assertThrows(
        InvalidArgumentException::class,
        fn() => new Application(['basePath' => sys_get_temp_dir(), 'cachePath' => '/tmp/x.php']),
        'removed in 0.3.0'
    );

    assertStringContains('cacheDir', $e->getMessage(), '应告诉用户改用 cacheDir');
});

test('未知配置项立即报错并列出支持的项', function () {
    $e = assertThrows(
        InvalidArgumentException::class,
        fn() => new Application(['basepath' => '/tmp']),
        'Unknown Application option'
    );

    assertStringContains('basePath', $e->getMessage(), '应提示正确的配置项名');
});

test('debug 默认取自 APP_DEBUG 环境变量', function () {
    $previous = getenv('APP_DEBUG');

    try {
        putenv('APP_DEBUG=1');
        assertTrue((new Application(['basePath' => sys_get_temp_dir()]))->isDebug());

        putenv('APP_DEBUG=0');
        assertFalse((new Application(['basePath' => sys_get_temp_dir()]))->isDebug());
    } finally {
        $previous === false ? putenv('APP_DEBUG') : putenv('APP_DEBUG=' . $previous);
    }
});

test('时区配置生效', function () {
    $previous = date_default_timezone_get();

    try {
        withApp(['timezone' => 'UTC'], function (Application $app) {
            // 时区在装配阶段应用，这里通过一次请求触发
            $app->handle(appRequest('/api/items/1'));
            assertSame('UTC', date_default_timezone_get());
        });
    } finally {
        date_default_timezone_set($previous);
    }
});

/* ------------------------------------------------------------------ *
 * 请求处理
 * ------------------------------------------------------------------ */

test('handle 直接返回响应，不经过 SAPI', function () {
    withApp([], function (Application $app) {
        $response = $app->handle(appRequest('/api/items/7'));

        assertSame(200, $response->getStatus());
        assertSame(['id' => 7], json_decode($response->getContent(), true));
    });
});

test('未命中路由照样返回 404 响应', function () {
    withApp([], function (Application $app) {
        assertSame(404, $app->handle(appRequest('/nope'))->getStatus());
    });
});

test('控制器目录不存在时报错', function () {
    assertThrows(
        RuntimeException::class,
        fn() => (new Application([
            'basePath'      => sys_get_temp_dir(),
            'controllerDir' => PHPET_TESTS . '/fixtures/no-such-dir',
        ]))->handle(appRequest('/')),
        'Controller directory not found'
    );
});

test('控制器目录为空时报错', function () {
    $dir = mktmp('phpet-empty-ctrl-');

    try {
        assertThrows(
            RuntimeException::class,
            fn() => (new Application([
                'basePath'            => sys_get_temp_dir(),
                'controllerDir'       => $dir,
                'controllerNamespace' => 'fixtures\empty',
            ]))->handle(appRequest('/')),
            'No controllers found'
        );
    } finally {
        rmtree($dir);
    }
});

test('容器注入的 Request 单例就是当次请求', function () {
    $container = new Container();

    withApp(['container' => $container], function (Application $app) use ($container) {
        $request = appRequest('/api/items/1');
        $app->handle($request);

        assertSame($request, $container->get(Request::class));
        assertSame($container, $app->container());
    });
});

test('exceptionHandler 可注入', function () {
    $handler = new ExceptionHandler(true);

    withApp(['exceptionHandler' => $handler], function (Application $app) use ($handler) {
        assertSame($handler, $app->exceptionHandler());
    });
});

test('装配完成后能拿到路由表', function () {
    withApp([], function (Application $app) {
        assertNull($app->router(), '未处理请求前不该装配');

        $app->handle(appRequest('/api/items/1'));

        assertNotNull($app->router());
        assertSame(8, $app->router()->count());
    });
});

/* ------------------------------------------------------------------ *
 * 中间件
 * ------------------------------------------------------------------ */

test('配置里的中间件被注册，方法级注解可短路', function () {
    withApp([], function (Application $app) {
        assertSame(403, $app->handle(appRequest('/api/guarded'))->getStatus());
    });
});

test('全局中间件包在路由外层', function () {
    withApp(['globalMiddlewares' => ['retag']], function (Application $app) {
        $response = $app->handle(appRequest('/nope'));

        assertSame(404, $response->getStatus());
        assertSame('1', $response->getHeaders()['X-Retag'] ?? null, '404 也要经过全局中间件');
    });
});

test('中间件支持 [class, params] 写法', function () {
    withApp([
        'middlewares' => [
            'rec'  => [RecordingMiddleware::class, ['rec']],
            'rec2' => [RecordingMiddleware::class, ['from-array']],
        ],
        'globalMiddlewares' => ['rec2'],
    ], function (Application $app) {
        Recorder::reset();
        $app->handle(appRequest('/api/items/1'));

        // 全局那层在最外，控制器上的 'rec' 在里层
        assertSame(
            ['before:from-array', 'before:rec', 'after:rec', 'after:from-array'],
            Recorder::$log
        );
    });
});

/* ------------------------------------------------------------------ *
 * 路由缓存
 * ------------------------------------------------------------------ */

test('非 debug 模式会写缓存，debug 模式不写', function () {
    withApp([], function (Application $app, string $base) {
        $app->handle(appRequest('/api/items/1'));
        assertTrue($app->cache()->has(RouteCache::DEFAULT_KEY), '非 debug 应落盘');
    });

    withApp(['debug' => true], function (Application $app, string $base) {
        $app->handle(appRequest('/api/items/1'));
        assertFalse($app->cache()->has(RouteCache::DEFAULT_KEY), 'debug 模式应绕过缓存');
    });
});

test('cache=false 时不写缓存文件', function () {
    withApp(['cache' => false], function (Application $app, string $base) {
        $app->handle(appRequest('/api/items/1'));
        assertFalse($app->cache()->has(RouteCache::DEFAULT_KEY));
    });
});

test('第二个实例命中缓存，行为与首次一致', function () {
    $base = mktmp('phpet-app-');

    $options = [
        'basePath'            => $base,
        'controllerDir'       => PHPET_TESTS . '/fixtures/controllers',
        'controllerNamespace' => 'fixtures\controllers',
        'middlewares'         => ['rec' => RecordingMiddleware::class],
        'logDir'              => sys_get_temp_dir() . '/phpet-tests-logs',
        'logLevel'            => Logger::DEBUG,
    ];

    try {
        $first = (new Application($options))->handle(appRequest('/api/items/7'));
        assertSame(200, $first->getStatus());

        $store = new FileCache($base . '/runtime/cache/store');
        assertTrue($store->has(RouteCache::DEFAULT_KEY), '首次装配应写入路由表');

        // 第二次装配应当读缓存而不是重新扫描
        $second = (new Application($options))->handle(appRequest('/api/items/7'));

        assertSame(200, $second->getStatus());
        assertSame($first->getContent(), $second->getContent());

        $log = (string)file_get_contents(
            sys_get_temp_dir() . '/phpet-tests-logs/app-' . date('Y-m-d') . '.log'
        );
        assertStringContains('Routes loaded from cache', $log);
    } finally {
        rmtree($base);
    }
});
