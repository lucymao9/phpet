<?php
declare(strict_types=1);

use phpet\core\Exception\NotFoundException;
use phpet\core\Response;

function contentType(Response $response): ?string
{
    return $response->getHeaders()['Content-Type'] ?? null;
}

test('text 默认 200 与纯文本类型', function () {
    $response = Response::text('hi');

    assertSame(200, $response->getStatus());
    assertSame('hi', $response->getContent());
    assertSame('text/plain; charset=utf-8', contentType($response));
});

test('text 可指定状态码', function () {
    assertSame(201, Response::text('created', 201)->getStatus());
});

test('json 不转义中文与斜杠', function () {
    $response = Response::json(['name' => '张三', 'url' => 'https://a.com/b']);

    assertSame('application/json; charset=utf-8', contentType($response));
    assertSame('{"name":"张三","url":"https://a.com/b"}', $response->getContent());
});

test('json 支持标量、列表与嵌套结构', function () {
    assertSame('[1,2,3]', Response::json([1, 2, 3])->getContent());
    assertSame('null', Response::json(null)->getContent());
    assertSame(
        '{"a":{"b":[1,2]}}',
        Response::json(['a' => ['b' => [1, 2]]])->getContent()
    );
});

test('json 可指定状态码', function () {
    assertSame(404, Response::json(['message' => 'x'], 404)->getStatus());
});

test('json 编码失败时降级为 500', function () {
    // NAN 无法被 json_encode，属于典型的编码失败
    $response = Response::json(['value' => NAN]);

    assertSame(500, $response->getStatus());
    assertSame('{"error":"Internal Server Error"}', $response->getContent());
});

test('html 的类型与状态', function () {
    $response = Response::html('<p>hi</p>', 200);

    assertSame('text/html; charset=utf-8', contentType($response));
    assertSame('<p>hi</p>', $response->getContent());
});

test('noContent 是 204 且无响应体', function () {
    $response = Response::noContent();

    assertSame(204, $response->getStatus());
    assertSame('', $response->getContent());
});

test('redirect 默认 302 并带 Location', function () {
    $response = Response::redirect('/next');

    assertSame(302, $response->getStatus());
    assertSame('/next', $response->getHeaders()['Location'] ?? null);

    assertSame(301, Response::redirect('/next', 301)->getStatus());
});

test('header 支持链式调用且同名覆盖', function () {
    $response = Response::text('x')
        ->header('X-A', '1')
        ->header('X-B', '2')
        ->header('X-A', '3');

    assertSame('3', $response->getHeaders()['X-A']);
    assertSame('2', $response->getHeaders()['X-B']);
});

test('status 链式调用覆盖初始状态', function () {
    assertSame(418, Response::text('teapot')->status(418)->getStatus());
});

test('download 同时输出 filename 与 filename*', function () {
    $file = tempnam(sys_get_temp_dir(), 'phpet-dl-');
    file_put_contents($file, 'payload');

    try {
        $response = Response::download($file, '报告.txt');
        $disposition = $response->getHeaders()['Content-Disposition'] ?? '';

        assertStringContains('attachment;', $disposition);
        assertStringContains('filename=', $disposition);
        assertStringContains(
            "filename*=UTF-8''" . rawurlencode('报告.txt'),
            $disposition,
            '中文名需要同时给出 RFC 5987 形式'
        );
        assertSame('7', $response->getHeaders()['Content-Length'] ?? null);
        assertSame(200, $response->getStatus());
    } finally {
        @unlink($file);
    }
});

test('download 未指定文件名时用原文件名', function () {
    $file = tempnam(sys_get_temp_dir(), 'phpet-dl-');
    file_put_contents($file, 'x');

    try {
        $disposition = Response::download($file)->getHeaders()['Content-Disposition'] ?? '';

        assertStringContains('filename="' . basename($file) . '"', $disposition);
    } finally {
        @unlink($file);
    }
});

test('file 返回文件字节数与探测到的类型', function () {
    $file = tempnam(sys_get_temp_dir(), 'phpet-f-');
    file_put_contents($file, 'hello');

    try {
        $response = Response::file($file);

        assertSame(5, (int)($response->getHeaders()['Content-Length'] ?? 0));
        assertNotEmpty(contentType($response));
        assertArrayNotHasKey('Content-Disposition', $response->getHeaders(), '内联展示不带下载头');
    } finally {
        @unlink($file);
    }
});

test('file 可显式指定 Content-Type', function () {
    $file = tempnam(sys_get_temp_dir(), 'phpet-f-');
    file_put_contents($file, 'x');

    try {
        assertSame(
            'application/pdf',
            contentType(Response::file($file, 'application/pdf'))
        );
    } finally {
        @unlink($file);
    }
});

test('文件不存在时抛 NotFoundException', function () {
    assertThrows(
        NotFoundException::class,
        fn() => Response::download('/no/such/file.txt'),
        'File not found'
    );

    assertThrows(
        NotFoundException::class,
        fn() => Response::file('/no/such/file.txt')
    );
});
