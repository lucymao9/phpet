<?php
declare(strict_types=1);

use fixtures\AbstractMiddleware;
use fixtures\NotAMiddleware;
use fixtures\Recorder;
use fixtures\RecordingMiddleware;
use fixtures\RetagMiddleware;
use fixtures\ShortCircuitMiddleware;
use fixtures\ThrowingMiddleware;
use phpet\core\middleware\MiddlewareInterface;
use phpet\core\middleware\MiddlewarePipeline;
use phpet\core\middleware\MiddlewareRegistry;
use phpet\core\Request;
use phpet\core\Response;

function mwRegistry(array $definitions = []): MiddlewareRegistry
{
    $registry = new MiddlewareRegistry();

    foreach ($definitions as $name => $config) {
        $registry->register($name, $config[0], $config[1] ?? []);
    }

    return $registry;
}

function mwPipeline(MiddlewareRegistry $registry): MiddlewarePipeline
{
    return new MiddlewarePipeline($registry, new Request([], [], [], []));
}

/* ------------------------------------------------------------------ *
 * 执行顺序
 * ------------------------------------------------------------------ */

test('before 正序执行，after 逆序执行', function () {
    Recorder::reset();

    $registry = mwRegistry([
        'a' => [RecordingMiddleware::class, ['a']],
        'b' => [RecordingMiddleware::class, ['b']],
    ]);

    $response = mwPipeline($registry)->handle(['a', 'b'], fn() => Response::text('core'));

    assertSame('core', $response->getContent());
    assertSame(
        ['before:a', 'before:b', 'after:b', 'after:a'],
        Recorder::$log
    );
});

test('before 返回 Response 即短路：跳过剩余中间件与核心逻辑', function () {
    Recorder::reset();

    $registry = mwRegistry([
        'rec' => [RecordingMiddleware::class, ['rec']],
        'sc'  => [ShortCircuitMiddleware::class, ['sc', 403]],
        'tail' => [RecordingMiddleware::class, ['tail']],
    ]);

    $coreRan = false;
    $response = mwPipeline($registry)->handle(
        ['rec', 'sc', 'tail'],
        function () use (&$coreRan) {
            $coreRan = true;

            return Response::text('core');
        }
    );

    assertFalse($coreRan, '核心逻辑不应执行');
    assertSame(403, $response->getStatus());

    // tail 的 before 没跑，自然也不该收到 after
    assertSame(
        ['before:rec', 'before:sc', 'after:sc', 'after:rec'],
        Recorder::$log
    );
});

test('核心逻辑抛异常时，已执行 before 的中间件仍收到 after', function () {
    Recorder::reset();

    $registry = mwRegistry([
        'a' => [RecordingMiddleware::class, ['a']],
        'b' => [RecordingMiddleware::class, ['b']],
    ]);

    assertThrows(
        RuntimeException::class,
        fn() => mwPipeline($registry)->handle(['a', 'b'], function () {
            throw new RuntimeException('core failed');
        }),
        'core failed'
    );

    assertSame(
        ['before:a', 'before:b', 'after:b:ex', 'after:a:ex'],
        Recorder::$log,
        '异常要透传给 after'
    );
});

test('中间件自身 before 抛异常时同样进入 after 链', function () {
    Recorder::reset();

    $registry = mwRegistry([
        'rec'   => [RecordingMiddleware::class, ['rec']],
        'throw' => [ThrowingMiddleware::class, []],
    ]);

    assertThrows(
        RuntimeException::class,
        fn() => mwPipeline($registry)->handle(['rec', 'throw'], fn() => Response::text('core')),
        'boom in before'
    );

    assertSame(['before:rec', 'before:throw', 'after:throw:ex', 'after:rec:ex'], Recorder::$log);
});

/* ------------------------------------------------------------------ *
 * 响应改写
 * ------------------------------------------------------------------ */

test('after 返回 null 表示不改写响应', function () {
    Recorder::reset();

    $registry = mwRegistry(['a' => [RecordingMiddleware::class, ['a']]]);

    $response = mwPipeline($registry)->handle(['a'], fn() => Response::text('keep'));

    assertSame('keep', $response->getContent());
    assertArrayNotHasKey('X-Retag', $response->getHeaders());
});

test('after 返回 Response 可改写响应', function () {
    $registry = mwRegistry(['retag' => [RetagMiddleware::class, []]]);

    $response = mwPipeline($registry)->handle(['retag'], fn() => Response::text('body'));

    assertSame('body', $response->getContent());
    assertSame('1', $response->getHeaders()['X-Retag'] ?? null);
});

test('核心逻辑返回值按类型自动包装', function () {
    $pipeline = mwPipeline(mwRegistry());

    assertSame(
        '{"a":1}',
        $pipeline->handle([], fn() => ['a' => 1])->getContent(),
        '数组转 JSON'
    );
    assertSame(
        '{"x":1}',
        $pipeline->handle([], fn() => (object)['x' => 1])->getContent(),
        '对象转 JSON'
    );
    assertSame('plain', $pipeline->handle([], fn() => 'plain')->getContent(), '字符串转纯文本');
    assertSame('', $pipeline->handle([], fn() => null)->getContent(), 'null 转空文本');
});

/* ------------------------------------------------------------------ *
 * 注册校验
 * ------------------------------------------------------------------ */

test('注册不存在的类直接报错', function () {
    assertThrows(
        RuntimeException::class,
        fn() => (new MiddlewareRegistry())->register('bad', 'fixtures\NotExists'),
        'class not found'
    );
});

test('注册未实现接口的类直接报错', function () {
    assertThrows(
        RuntimeException::class,
        fn() => (new MiddlewareRegistry())->register('bad', NotAMiddleware::class),
        'must implement'
    );
});

test('把接口本身当中间件注册时报错指向接口约定', function () {
    $e = assertThrows(
        RuntimeException::class,
        fn() => (new MiddlewareRegistry())->register('bad', MiddlewareInterface::class)
    );

    // class_exists() 对接口返回 false，早期版本会误报「类不存在」
    assertStringNotContains('class not found', $e->getMessage());
    assertStringContains('must implement', $e->getMessage());
});

test('抽象类能通过接口检查，但要单独拦下来', function () {
    assertThrows(
        RuntimeException::class,
        fn() => (new MiddlewareRegistry())->register('bad', AbstractMiddleware::class),
        'must be instantiable'
    );
});

test('注册时可传入构造参数', function () {
    Recorder::reset();

    $registry = new MiddlewareRegistry();
    $registry->register('rec', RecordingMiddleware::class, ['custom']);

    mwPipeline($registry)->handle(['rec'], fn() => Response::text('x'));

    assertSame(['before:custom', 'after:custom'], Recorder::$log);
});

test('registerInstance 使用现成实例', function () {
    Recorder::reset();

    $registry = new MiddlewareRegistry();
    $instance = new RecordingMiddleware('shared');
    $registry->registerInstance('rec', $instance);

    assertTrue($registry->has('rec'));
    assertSame($instance, $registry->resolve('rec'));

    mwPipeline($registry)->handle(['rec'], fn() => Response::text('x'));
    assertSame(['before:shared', 'after:shared'], Recorder::$log);
});

test('resolve 未注册的中间件时报错', function () {
    assertThrows(
        RuntimeException::class,
        fn() => (new MiddlewareRegistry())->resolve('nope'),
        'not registered'
    );
});

test('has 覆盖两种注册方式', function () {
    $registry = new MiddlewareRegistry();

    assertFalse($registry->has('rec'));

    $registry->register('rec', RecordingMiddleware::class);
    assertTrue($registry->has('rec'));

    $registry->registerInstance('inst', new RecordingMiddleware());
    assertTrue($registry->has('inst'));
});
