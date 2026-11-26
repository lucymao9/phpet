<?php
declare(strict_types=1);

use phpet\core\Exception\ExceptionHandler;
use phpet\core\Exception\ErrorRenderer;
use phpet\core\Exception\MethodNotAllowedException;
use phpet\core\Exception\NotFoundException;
use phpet\core\Exception\ValidationException;

function handleWith(\Throwable $e, bool $debug = false, ?string $accept = null)
{
    return withAccept($accept, fn() => (new ExceptionHandler($debug))->handle($e));
}

/* ------------------------------------------------------------------ *
 * JSON 分支
 * ------------------------------------------------------------------ */

test('404 返回 message 字段的 JSON', function () {
    $response = handleWith(new NotFoundException('Route not found'));

    assertSame(404, $response->getStatus());
    assertSame('application/json; charset=utf-8', $response->getHeaders()['Content-Type'] ?? null);
    assertSame(['message' => 'Route not found'], json_decode($response->getContent(), true));
});

test('校验失败返回 errors 明细', function () {
    $response = handleWith(new ValidationException([
        'username' => ['用户名不能为空'],
    ]));

    $payload = json_decode($response->getContent(), true);

    assertSame(400, $response->getStatus());
    assertSame('Validation failed', $payload['message']);
    assertSame(['用户名不能为空'], $payload['errors']['username']);
});

test('405 响应带 Allow 头', function () {
    $response = handleWith(new MethodNotAllowedException(['GET', 'POST']));

    assertSame(405, $response->getStatus());
    assertSame('GET, POST', $response->getHeaders()['Allow'] ?? null);
});

test('非 debug 的 500 不泄露异常细节', function () {
    $response = handleWith(new RuntimeException('数据库密码是 hunter2'));

    $payload = json_decode($response->getContent(), true);

    assertSame(500, $response->getStatus());
    assertSame('Internal Server Error', $payload['error']);
    assertArrayHasKey('requestId', $payload);
    assertArrayNotHasKey('exception', $payload);
    assertArrayNotHasKey('file', $payload);
    assertStringNotContains('hunter2', $response->getContent());
});

test('debug 的 500 透出异常类型、消息与位置', function () {
    $response = handleWith(new RuntimeException('boom'), true);

    $payload = json_decode($response->getContent(), true);

    assertSame(RuntimeException::class, $payload['exception'] ?? null);
    assertSame('boom', $payload['message'] ?? null);
    assertStringContains('.php:', $payload['file'] ?? '');
});

test('requestId 优先取请求头 X-Request-Id', function () {
    $_SERVER['HTTP_X_REQUEST_ID'] = 'req-abc';
    try {
        // 只有非 HttpException 的 5xx 才会在 JSON 里带 requestId，
        // 4xx 属于业务流，不回显内部编号
        $payload = json_decode(
            handleWith(new RuntimeException('boom'))->getContent(),
            true
        );

        assertSame('req-abc', $payload['requestId'] ?? null);
    } finally {
        unset($_SERVER['HTTP_X_REQUEST_ID']);
    }
});

/* ------------------------------------------------------------------ *
 * HTML 分支
 * ------------------------------------------------------------------ */

test('浏览器请求拿到排版后的错误页', function () {
    $response = handleWith(
        new NotFoundException('Route not found'),
        false,
        'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8'
    );

    assertSame(404, $response->getStatus());
    assertSame('text/html; charset=utf-8', $response->getHeaders()['Content-Type'] ?? null);
    assertStringContains('<!DOCTYPE html>', $response->getContent());
    assertStringContains('404', $response->getContent());
    assertStringContains('Route not found', $response->getContent());
});

test('错误页对异常消息做 HTML 转义', function () {
    $response = handleWith(
        new NotFoundException('<script>alert(1)</script>'),
        false,
        'text/html'
    );

    assertStringNotContains('<script>alert(1)</script>', $response->getContent());
    assertStringContains('&lt;script&gt;', $response->getContent());
});

test('错误页列出字段级校验错误', function () {
    $response = handleWith(
        new ValidationException(['user.username' => ['用户名不能为空', '长度不足']]),
        false,
        'text/html'
    );

    $body = $response->getContent();

    assertStringContains('字段校验失败', $body);
    assertStringContains('user.username', $body);
    assertStringContains('长度不足', $body);
});

test('生产环境的 500 错误页不暴露异常消息', function () {
    $response = handleWith(new RuntimeException('内部细节'), false, 'text/html');

    assertStringContains('服务器内部错误', $response->getContent());
    assertStringNotContains('内部细节', $response->getContent());
});

test('debug 错误页包含源码片段与调用栈', function () {
    $response = handleWith(new RuntimeException('boom'), true, 'text/html');

    $body = $response->getContent();

    assertStringContains('调试信息', $body);
    assertStringContains('调用栈', $body);
    assertStringContains('class="source"', $body, '应渲染出错位置附近的源码');
    assertStringContains(RuntimeException::class, $body);
});

test('错误页页脚带请求编号', function () {
    $_SERVER['HTTP_X_REQUEST_ID'] = 'trace-42';
    try {
        $body = handleWith(new NotFoundException('x'), false, 'text/html')->getContent();

        assertStringContains('trace-42', $body);
    } finally {
        unset($_SERVER['HTTP_X_REQUEST_ID']);
    }
});

/* ------------------------------------------------------------------ *
 * 内容协商
 * ------------------------------------------------------------------ */

test('wantsHtml 按 Accept 判定', function () {
    $renderer = new ErrorRenderer();

    // [Accept, 期望]
    $cases = [
        [null, false],
        ['', false],
        ['*/*', false],
        ['application/json', false],
        ['text/html', true],
        ['text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8', true],
        ['application/json, text/html', false],
        ['text/html;q=0.9, application/json;q=1.0', true],
    ];

    foreach ($cases as [$accept, $expected]) {
        $actual = withAccept($accept, fn() => $renderer->wantsHtml());

        assertSame(
            $expected,
            $actual,
            'Accept = ' . ($accept === null ? '(无)' : ($accept === '' ? '(空)' : $accept))
        );
    }
});

test('同一次请求的 JSON 与 HTML 分支携带同样的业务信息', function () {
    $e = new ValidationException(['name' => ['必填']]);

    $json = json_decode(handleWith($e, false, 'application/json')->getContent(), true);
    $html = handleWith($e, false, 'text/html')->getContent();

    assertSame('Validation failed', $json['message']);
    assertStringContains($json['message'], $html);
    assertStringContains('必填', $html);
});
