<?php
declare(strict_types=1);

use phpet\core\Exception\ExceptionHandler;
use phpet\core\Response;
use phpet\core\Router;

function makeRouter(): Router
{
    return new Router(new ExceptionHandler(false));
}

function payload(Response $response): array
{
    $decoded = json_decode($response->getContent(), true);

    return is_array($decoded) ? $decoded : [];
}

/* ------------------------------------------------------------------ */

test('静态路由命中并返回处理器结果', function () {
    $router = makeRouter();
    $router->add('GET', '/ping', fn() => Response::text('pong'));

    $response = $router->dispatch('GET', '/ping');

    assertInstanceOf(Response::class, $response);
    assertSame(200, $response->getStatus());
    assertSame('pong', $response->getContent());
});

test('路径归一：尾斜杠、重复斜杠、空路径', function () {
    $router = makeRouter();
    $router->add('GET', '/ping', fn() => Response::text('pong'));

    assertSame('pong', $router->dispatch('GET', '/ping/')->getContent(), '尾斜杠');
    assertSame('pong', $router->dispatch('GET', '//ping')->getContent(), '重复前导斜杠');
    assertSame('pong', $router->dispatch('GET', 'ping')->getContent(), '缺前导斜杠');
});

test('注册时同样做路径归一', function () {
    $router = makeRouter();
    $router->add('GET', 'a//b/', fn() => Response::text('hit'));

    assertSame(1, $router->count());
    assertSame('hit', $router->dispatch('GET', '/a/b')->getContent());
});

test('URI 中的 query 与 fragment 不参与匹配', function () {
    $router = makeRouter();
    $router->add('GET', '/ping', fn() => Response::text('pong'));

    assertSame('pong', $router->dispatch('GET', '/ping?x=1&y=2')->getContent());
    assertSame('pong', $router->dispatch('GET', '/ping#frag')->getContent());
});

test('动态参数按名称传给处理器', function () {
    $router = makeRouter();
    $router->add('GET', '/user/{id}/post/{postId}', function (array $params) {
        return Response::json($params);
    });

    assertSame(
        ['id' => '7', 'postId' => '99'],
        payload($router->dispatch('GET', '/user/7/post/99'))
    );
});

test('占位符正则约束不满足时按 404 处理', function () {
    $router = makeRouter();
    $router->add('GET', '/user/{id:\d+}', fn() => Response::json(['ok' => true]));

    assertSame(200, $router->dispatch('GET', '/user/42')->getStatus());
    assertSame(404, $router->dispatch('GET', '/user/abc')->getStatus());
});

test('无约束占位符不跨路径分隔符', function () {
    $router = makeRouter();
    $router->add('GET', '/p/{slug}', fn() => Response::text('hit'));

    assertSame('hit', $router->dispatch('GET', '/p/abc')->getContent());
    assertSame(404, $router->dispatch('GET', '/p/a/b')->getStatus());
});

test('静态路由优先于同形状的动态路由', function () {
    $router = makeRouter();
    $router->add('GET', '/user/{id}', fn() => Response::text('dynamic'));
    $router->add('GET', '/user/me', fn() => Response::text('static'));

    assertSame('static', $router->dispatch('GET', '/user/me')->getContent());
    assertSame('dynamic', $router->dispatch('GET', '/user/7')->getContent());
});

test('未匹配路径返回 404 JSON', function () {
    $router = makeRouter();

    $response = $router->dispatch('GET', '/nope');

    assertSame(404, $response->getStatus());
    assertSame('application/json; charset=utf-8', $response->getHeaders()['Content-Type'] ?? null);
    assertSame('Route not found', payload($response)['message'] ?? null);
});

test('路径存在但方法不对返回 405 并带 Allow 头', function () {
    $router = makeRouter();
    $router->add('GET', '/only', fn() => Response::text('get'));
    $router->add('POST', '/only', fn() => Response::text('post'));

    $response = $router->dispatch('DELETE', '/only');

    assertSame(405, $response->getStatus());

    $allow = $response->getHeaders()['Allow'] ?? '';
    assertStringContains('GET', $allow);
    assertStringContains('POST', $allow);
    assertStringContains('OPTIONS', $allow, '405 的 Allow 应自动补上 OPTIONS');
});

test('405 判定对动态路由同样生效', function () {
    $router = makeRouter();
    $router->add('GET', '/user/{id:\d+}', fn() => Response::text('get'));

    $response = $router->dispatch('POST', '/user/12');

    assertSame(405, $response->getStatus());
    assertStringContains('GET', $response->getHeaders()['Allow'] ?? '');
});

test('HEAD 复用 GET 的处理器', function () {
    $router = makeRouter();
    $router->add('GET', '/ping', fn() => Response::text('pong'));

    $response = $router->dispatch('HEAD', '/ping');

    assertSame(200, $response->getStatus());
    // 响应体在 Response::send() 阶段丢弃，路由层照常返回内容
    assertSame('pong', $response->getContent());
});

test('静态片段中的正则元字符被转义', function () {
    $router = makeRouter();
    $router->add('GET', '/file.json', fn() => Response::text('json'));

    assertSame('json', $router->dispatch('GET', '/file.json')->getContent());
    assertSame(404, $router->dispatch('GET', '/fileXjson')->getStatus(), '`.` 不应匹配任意字符');
});

test('方法名大小写不敏感，统一转大写', function () {
    $router = makeRouter();
    $router->add('post', '/x', fn() => Response::text('posted'));

    assertSame('posted', $router->dispatch('POST', '/x')->getContent());
    assertSame('posted', $router->dispatch('post', '/x')->getContent());
});

test('空方法名按 GET 处理', function () {
    $router = makeRouter();
    $router->add('GET', '/ping', fn() => Response::text('pong'));

    assertSame('pong', $router->dispatch('', '/ping')->getContent());
});

test('同方法同路径重复注册会覆盖而非追加', function () {
    $router = makeRouter();
    $router->add('GET', '/dup', fn() => Response::text('first'));
    $router->add('GET', '/dup', fn() => Response::text('second'));

    assertSame(1, $router->count());
    assertSame('second', $router->dispatch('GET', '/dup')->getContent());
});

test('count 统计的是「方法 + 路径」组合数', function () {
    $router = makeRouter();
    $router->add('GET', '/a', fn() => Response::text('a'));
    $router->add('POST', '/a', fn() => Response::text('a'));
    $router->add('GET', '/b', fn() => Response::text('b'));

    assertSame(3, $router->count());
});
