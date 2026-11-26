<?php
declare(strict_types=1);

use fixtures\controllers\ApiController;
use fixtures\RecordingMiddleware;
use fixtures\ShortCircuitMiddleware;
use phpet\core\Container;
use phpet\core\ControllerScanner;
use phpet\core\Exception\ExceptionHandler;
use phpet\core\middleware\MiddlewareRegistry;
use phpet\core\Request;
use phpet\core\Response;
use phpet\core\Router;

/**
 * 组装一套扫描器依赖：路由、注册表、容器（内含 Request 单例）。
 *
 * @return array{0: Router, 1: ControllerScanner, 2: Container}
 */
function scannerStack(array $body = [], array $query = []): array
{
    $router = new Router(new ExceptionHandler(false));

    $registry = new MiddlewareRegistry();
    $registry->register('rec', RecordingMiddleware::class, ['rec']);
    $registry->register('block', ShortCircuitMiddleware::class, ['block']);

    $container = new Container();
    $container->singleton(Request::class, new Request($query, $body, [], []));

    return [$router, new ControllerScanner($router, $registry, $container), $container];
}

function scannedStack(array $body = [], array $query = []): array
{
    [$router, $scanner] = scannerStack($body, $query);
    $scanner->scanDirectory(PHPET_TESTS . '/fixtures/controllers', 'fixtures\controllers');

    return [$router, $scanner];
}

function jsonBody(Response $response): array
{
    $decoded = json_decode($response->getContent(), true);

    return is_array($decoded) ? $decoded : [];
}

/* ------------------------------------------------------------------ */

test('扫描返回所有带 #[Controller] 的类，跳过普通类', function () {
    [, $scanner] = scannedStack();

    $classes = $scanner->scanDirectory(PHPET_TESTS . '/fixtures/controllers', 'fixtures\controllers');

    assertContains(ApiController::class, $classes);
    assertNotContains('fixtures\controllers\PlainClass', $classes);
});

test('每条路由额外注册一个 OPTIONS 占位', function () {
    [$router] = scannedStack();

    // ApiController 有 4 个方法，每个 1 种方法 + 1 个 OPTIONS = 8
    assertSame(8, $router->count());
});

test('Route 路径与控制器前缀拼接', function () {
    [$router] = scannedStack();

    $response = $router->dispatch('GET', '/api/items/7');

    assertSame(200, $response->getStatus());
    assertSame(['id' => 7], jsonBody($response));
});

test('路径参数按反射类型转换', function () {
    [$router] = scannedStack();

    $id = jsonBody($router->dispatch('GET', '/api/items/7'))['id'];

    assertSame(7, $id);
    assertTrue(is_int($id), '声明为 int 的参数应转换成整数');
});

test('OPTIONS 占位返回 204', function () {
    [$router] = scannedStack();

    $response = $router->dispatch('OPTIONS', '/api/items');

    assertSame(204, $response->getStatus());
    assertSame('', $response->getContent());
});

test('方法不匹配返回 405，Allow 含 OPTIONS', function () {
    [$router] = scannedStack();

    $response = $router->dispatch('DELETE', '/api/items/7');

    assertSame(405, $response->getStatus());
    assertStringContains('GET', $response->getHeaders()['Allow'] ?? '');
    assertStringContains('OPTIONS', $response->getHeaders()['Allow'] ?? '');
});

test('FromQuery 从查询串取值', function () {
    [$router] = scannedStack();

    withGlobals(['_GET' => ['q' => 'hello']], function () use ($router) {
        assertSame(['q' => 'hello'], jsonBody($router->dispatch('GET', '/api/echo')));
    });
});

test('FromQuery 缺省时使用参数默认值', function () {
    [$router] = scannedStack();

    withGlobals(['_GET' => []], function () use ($router) {
        assertSame(['q' => ''], jsonBody($router->dispatch('GET', '/api/echo')));
    });
});

test('DTO 自动注入并按分组校验', function () {
    [$router] = scannedStack(['title' => 'hello']);

    $response = $router->dispatch('POST', '/api/items');

    assertSame(200, $response->getStatus());
    assertSame(['title' => 'hello'], jsonBody($response));
});

test('DTO 校验失败返回 400 与字段错误', function () {
    [$router] = scannedStack([]);

    $response = $router->dispatch('POST', '/api/items');

    assertSame(400, $response->getStatus());

    $payload = jsonBody($response);
    assertSame('Validation failed', $payload['message'] ?? null);
    assertArrayHasKey('title', $payload['errors'] ?? []);
});

test('方法级中间件可短路控制器', function () {
    [$router] = scannedStack();

    $response = $router->dispatch('GET', '/api/guarded');

    assertSame(403, $response->getStatus());
    assertSame(['blocked' => true], jsonBody($response));
});

test('未声明方法级中间件的路由不受影响', function () {
    [$router] = scannedStack();

    assertSame(200, $router->dispatch('GET', '/api/items/1')->getStatus());
});

test('definitions 合并类级与方法级中间件', function () {
    [, $scanner] = scannedStack();

    $definitions = $scanner->definitions();
    assertCount(4, $definitions);

    $byAction = [];
    foreach ($definitions as $def) {
        $byAction[$def['action']] = $def;
    }

    assertSame(['rec'], $byAction['show']['middlewares'], '只有类级中间件');
    assertSame(['rec', 'block'], $byAction['guarded']['middlewares'], '类级在前、方法级在后');
});

test('definitions 字段完整，可直接落盘', function () {
    [, $scanner] = scannedStack();

    $def = $scanner->definitions()[0];

    foreach (['path', 'methods', 'controller', 'action', 'middlewares'] as $key) {
        assertArrayHasKey($key, $def);
    }
    assertSame(['GET'], $def['methods']);
    assertSame('show', $def['action']);
    assertSame('/api/items/{id}', $def['path']);
    assertSame(ApiController::class, $def['controller']);
});

test('从定义注册与扫描结果一致', function () {
    [, $scanner] = scannedStack();
    $definitions = $scanner->definitions();

    [$router2, $scanner2] = scannerStack();
    $scanner2->registerDefinitions($definitions);

    assertSame(8, $router2->count(), '路由数量应与扫描路径一致');

    [$router1] = scannedStack();
    assertSame(
        jsonBody($router1->dispatch('GET', '/api/items/7')),
        jsonBody($router2->dispatch('GET', '/api/items/7')),
        '两条注册路径的行为应完全一致'
    );
});

test('从定义注册时不会加载控制器类（按需实例化）', function () {
    [$router, $scanner] = scannerStack();

    $lazyClass = 'fixtures\lazy\LazyController';
    assertFalse(class_exists($lazyClass, false), '前置条件：类尚未加载');

    $scanner->registerDefinitions([[
        'path'        => '/lazy/never',
        'methods'     => ['GET'],
        'controller'  => $lazyClass,
        'action'      => 'never',
        'middlewares' => [],
    ]]);

    assertSame(2, $router->count(), '路由 + OPTIONS');
    assertFalse(
        class_exists($lazyClass, false),
        '只注册不调用时，控制器类不应被加载'
    );
});

test('目录为空时返回空列表而不报错', function () {
    $dir = mktmp('phpet-empty-');

    try {
        [$router, $scanner] = scannerStack();

        assertSame([], $scanner->scanDirectory($dir, 'fixtures\empty'));
        assertSame(0, $router->count());
    } finally {
        rmtree($dir);
    }
});

test('目录不存在时报错', function () {
    [$router, $scanner] = scannerStack();

    assertThrows(
        RuntimeException::class,
        fn() => $scanner->scanDirectory(PHPET_TESTS . '/fixtures/no-such-dir', 'fixtures\x'),
        'Controller directory not found'
    );
});
