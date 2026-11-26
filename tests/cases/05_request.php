<?php
declare(strict_types=1);

use phpet\core\Request;

/**
 * 构造一个 Request，默认空请求。
 */
function makeRequest(array $overrides = []): Request
{
    return new Request(
        $overrides['query'] ?? [],
        $overrides['body'] ?? [],
        $overrides['server'] ?? [],
        $overrides['headers'] ?? [],
        $overrides['files'] ?? []
    );
}

test('query / body / input / has 取值', function () {
    $request = makeRequest([
        'query' => ['page' => '2', 'only_query' => 'q'],
        'body'  => ['name' => 'tom'],
    ]);

    assertSame('2', $request->query('page'));
    assertSame('tom', $request->body('name'));
    assertNull($request->query('missing'));
    assertSame('fallback', $request->query('missing', 'fallback'));

    assertSame('tom', $request->input('name'), 'input 优先取请求体');
    assertSame('q', $request->input('only_query'), '请求体没有则回落 query');

    assertTrue($request->has('name'));
    assertTrue($request->has('only_query'));
    assertFalse($request->has('missing'));
});

test('请求头键名不区分大小写', function () {
    $request = makeRequest([
        'headers' => ['content-type' => 'application/json', 'x-token' => 'abc'],
    ]);

    assertSame('application/json', $request->header('Content-Type'));
    assertSame('application/json', $request->header('CONTENT-TYPE'));
    assertSame('abc', $request->header('X-Token'));
    assertNull($request->header('X-Missing'));
});

test('请求方法统一转大写，缺失按 GET', function () {
    assertSame('POST', makeRequest(['server' => ['REQUEST_METHOD' => 'post']])->method());
    assertSame('GET', makeRequest()->method());
});

test('path 截掉 query 与 fragment', function () {
    assertSame('/a/b', makeRequest(['server' => ['REQUEST_URI' => '/a/b?x=1']])->path());
    assertSame('/a/b', makeRequest(['server' => ['REQUEST_URI' => '/a/b#frag']])->path());
    assertSame('/a/b', makeRequest(['server' => ['REQUEST_URI' => '/a/b']])->path());
});

test('path 为空时返回根路径', function () {
    assertSame('/', makeRequest(['server' => ['REQUEST_URI' => '']])->path());
    assertSame('/', makeRequest()->path());
    assertSame('/', makeRequest(['server' => ['REQUEST_URI' => '?x=1']])->path());
});

test('path 不用 parse_url，双斜杠开头不会被吃掉一段', function () {
    // parse_url('//api/users') 会解析成 host=api、path=/users，
    // 这正是之前 /api 下出现 404 的根因
    $request = makeRequest(['server' => ['REQUEST_URI' => '//api/users']]);

    assertSame('//api/users', $request->path());
});

test('isJson 依据 content-type 判断', function () {
    assertTrue(makeRequest(['headers' => ['content-type' => 'application/json; charset=utf-8']])->isJson());
    assertFalse(makeRequest(['headers' => ['content-type' => 'text/plain']])->isJson());
    assertFalse(makeRequest()->isJson());
});

test('accepts 判断客户端是否接受指定类型', function () {
    assertTrue(makeRequest(['headers' => ['accept' => 'application/json']])->accepts('application/json'));
    assertTrue(makeRequest(['headers' => ['accept' => '*/*']])->accepts('application/json'));
    assertTrue(makeRequest(['headers' => ['accept' => 'text/html, */*']])->accepts('application/json'));
    assertFalse(makeRequest(['headers' => ['accept' => 'text/html']])->accepts('application/json'));
    assertFalse(makeRequest()->accepts('application/json'), '没有 Accept 头视为不接受');
});

test('wantsJson 按 Accept 的优先顺序判断', function () {
    $cases = [
        ''                                                              => true,
        '*/*'                                                           => true,
        'application/json'                                              => true,
        'application/json, text/html'                                   => true,
        'text/html'                                                     => false,
        'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8' => false,
        'text/html;q=0.9, application/json;q=1.0'                       => false,
    ];

    foreach ($cases as $accept => $expected) {
        $request = makeRequest(['headers' => ['accept' => (string)$accept]]);

        assertSame(
            $expected,
            $request->wantsJson(),
            'Accept 为 ' . ($accept === '' ? '(空)' : $accept)
        );
    }
});

test('clientIp 默认只信任 REMOTE_ADDR', function () {
    $request = makeRequest([
        'server'  => ['REMOTE_ADDR' => '10.0.0.1'],
        'headers' => ['x-forwarded-for' => '1.2.3.4, 5.6.7.8'],
    ]);

    assertSame('10.0.0.1', $request->clientIp());
    assertSame('1.2.3.4', $request->clientIp(true), '开启信任代理后取第一个');
});

test('clientIp 在开启信任代理但没有转发头时回退', function () {
    $request = makeRequest(['server' => ['REMOTE_ADDR' => '10.0.0.1']]);

    assertSame('10.0.0.1', $request->clientIp(true));
});

test('上传文件的读取与判定', function () {
    $request = makeRequest([
        'files' => [
            'avatar' => ['name' => 'a.png', 'error' => UPLOAD_ERR_OK, 'size' => 10],
            'empty'  => ['name' => '', 'error' => UPLOAD_ERR_NO_FILE, 'size' => 0],
        ],
    ]);

    assertSame('a.png', $request->file('avatar')['name']);
    assertNull($request->file('missing'));

    assertTrue($request->hasFile('avatar'));
    assertFalse($request->hasFile('empty'), 'UNLOAD_ERR_NO_FILE 视为没有文件');
    assertFalse($request->hasFile('missing'));

    assertCount(2, $request->files());
});

test('原始数组访问器', function () {
    $request = makeRequest([
        'query'   => ['a' => '1'],
        'body'    => ['b' => '2'],
        'headers' => ['x' => 'y'],
    ]);

    assertSame(['a' => '1'], $request->getQuery());
    assertSame(['b' => '2'], $request->all());
    assertSame(['x' => 'y'], $request->headers());
});
