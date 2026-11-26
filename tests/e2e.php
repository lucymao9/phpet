<?php
declare(strict_types=1);

/**
 * 端到端测试：起一个 PHP 内置服务器，用真实 HTTP 请求打整条链路。
 *
 *   php tests/e2e.php            跑全部
 *   php tests/e2e.php json       只跑名称含 "json" 的用例
 *
 * 覆盖的是单元测试碰不到的部分：$_SERVER / php://input 解析、
 * 响应头真实下发、输出缓冲、HEAD 无响应体、内容协商。
 */

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/lib.php';

$started = microtime(true);

$filter = null;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--no-color') {
        putenv('NO_COLOR=1');
        continue;
    }
    if (!str_starts_with($arg, '-')) {
        $filter = $arg;
    }
}

Harness::$filter = $filter;
Harness::$file = '端到端（真实 HTTP）';
Harness::$color = getenv('NO_COLOR') === false
    && function_exists('stream_isatty')
    && @stream_isatty(STDOUT);

/* ------------------------------------------------------------------ *
 * 服务器生命周期
 * ------------------------------------------------------------------ */

function e2ePickPort(): int
{
    for ($i = 0; $i < 20; $i++) {
        $port = random_int(30000, 39999);
        $socket = @stream_socket_server("tcp://127.0.0.1:{$port}", $errno, $errstr);
        if ($socket !== false) {
            fclose($socket);

            return $port;
        }
    }

    throw new RuntimeException('找不到可用端口');
}

function e2eWaitForServer(int $port, float $timeout): void
{
    $deadline = microtime(true) + $timeout;

    while (microtime(true) < $deadline) {
        $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
        if ($socket !== false) {
            fclose($socket);

            return;
        }
        usleep(50000);
    }

    throw new RuntimeException("内置服务器未能在 {$timeout} 秒内就绪");
}

$port = e2ePickPort();
$serverLog = tempnam(sys_get_temp_dir(), 'phpet-server-');

$server = proc_open(
    [
        PHP_BINARY,
        '-S',
        "127.0.0.1:{$port}",
        '-t',
        PHPET_ROOT . '/public',
        PHPET_ROOT . '/public/index.php',
    ],
    [
        0 => ['pipe', 'r'],
        1 => ['file', $serverLog, 'w'],
        2 => ['file', $serverLog, 'a'],
    ],
    $pipes,
    PHPET_ROOT,
    [
        'APP_DEBUG'    => '0',
        'APP_TIMEZONE' => 'Asia/Shanghai',
        'PATH'         => getenv('PATH') ?: '/usr/bin:/bin',
    ]
);

if (!is_resource($server)) {
    fwrite(STDERR, "无法启动 PHP 内置服务器\n");
    exit(1);
}

/* ------------------------------------------------------------------ *
 * HTTP 客户端
 * ------------------------------------------------------------------ */

function http(string $method, string $path, array $options = []): array
{
    $ch = curl_init('http://127.0.0.1:' . $GLOBALS['port'] . $path);

    if ($ch === false) {
        throw new RuntimeException('curl 初始化失败');
    }

    $requestHeaders = $options['headers'] ?? [];
    $responseHeaders = [];

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$responseHeaders): int {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
            }

            return strlen($line);
        },
    ]);

    if ($method === 'HEAD') {
        curl_setopt($ch, CURLOPT_NOBODY, true);
    }

    if (isset($options['json'])) {
        $requestHeaders[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($options['json'], JSON_UNESCAPED_UNICODE));
    } elseif (isset($options['raw'])) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $options['raw']);
    }

    if ($requestHeaders !== []) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $requestHeaders);
    }

    $body = curl_exec($ch);

    if ($body === false) {
        throw new RuntimeException("{$method} {$path} 请求失败：" . curl_error($ch));
    }

    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    // curl_close() 从 PHP 8.5 起废弃且无实际作用，句柄随变量作用域释放

    return [
        'status'  => $status,
        'headers' => $responseHeaders,
        'body'    => (string)$body,
    ];
}

function jsonOf(array $response): array
{
    $decoded = json_decode($response['body'], true);

    return is_array($decoded) ? $decoded : [];
}

function headerOf(array $response, string $name): string
{
    return $response['headers'][strtolower($name)] ?? '';
}

/* ------------------------------------------------------------------ *
 * 用例
 * ------------------------------------------------------------------ */

try {
    e2eWaitForServer($port, 5.0);

    printf(
        "%s\n\n",
        Harness::color("phpet 端到端测试  ·  php -S 127.0.0.1:{$port}", '1')
    );

    test('GET /api/users 返回 200 与 JSON', function () {
        $response = http('GET', '/api/users');

        assertSame(200, $response['status']);
        assertStringContains('application/json', headerOf($response, 'Content-Type'));

        $payload = jsonOf($response);
        assertSame('', $payload['gender'] ?? null);
        assertSame('127.0.0.1:' . $GLOBALS['port'], $payload['host'] ?? null, 'FromHeader 应取到 Host');
    });

    test('查询串被注入到 FromQuery 参数', function () {
        $response = http('GET', '/api/users?gender=male');

        assertSame(200, $response['status']);
        assertSame('male', jsonOf($response)['gender'] ?? null);
    });

    test('全局 CORS 中间件给所有响应补头', function () {
        $response = http('GET', '/api/users');

        assertSame('*', headerOf($response, 'Access-Control-Allow-Origin'));
        assertStringContains('GET', headerOf($response, 'Access-Control-Allow-Methods'));
    });

    test('OPTIONS 预检直接返回 204', function () {
        $response = http('OPTIONS', '/api/users', [
            'headers' => ['Origin' => 'https://example.com', 'Access-Control-Request-Method' => 'POST'],
        ]);

        assertSame(204, $response['status']);
        assertSame('', $response['body']);
        assertSame('*', headerOf($response, 'Access-Control-Allow-Origin'));
    });

    test('路径参数按声明类型转换后注入', function () {
        $response = http('GET', '/api/user/42');

        assertSame(200, $response['status']);
        $payload = jsonOf($response);
        assertSame(42, $payload['id'] ?? null);
        assertTrue(is_int($payload['id'] ?? null), 'id 应为整数');
    });

    test('POST 合法 JSON 时 DTO 自动注入并回显', function () {
        $response = http('POST', '/api/user', [
            'json' => [
                'user'    => ['username' => 'tom', 'age' => 20],
                'address' => ['city' => '北京', 'zip' => '100000'],
            ],
        ]);

        assertSame(200, $response['status']);

        $dto = jsonOf($response)['dto'] ?? [];
        assertSame('tom', $dto['user']['username'] ?? null);
        assertSame('北京', $dto['address']['city'] ?? null);
        assertSame(20, $dto['user']['age'] ?? null);
    });

    test('POST 校验失败返回 400 与嵌套字段错误', function () {
        $response = http('POST', '/api/user', ['json' => ['user' => []]]);

        assertSame(400, $response['status']);

        $payload = jsonOf($response);
        assertSame('Validation failed', $payload['message'] ?? null);
        assertArrayHasKey('user.username', $payload['errors'] ?? []);
    });

    test('格式类规则在真实请求中同样生效', function () {
        $response = http('POST', '/api/user', [
            'json' => ['user' => ['username' => 'tom', 'age' => 20, 'email' => 'bad-email']],
        ]);

        assertSame(400, $response['status']);
        assertArrayHasKey('user.email', jsonOf($response)['errors'] ?? []);
    });

    test('请求体不是合法 JSON 时返回 400', function () {
        $response = http('POST', '/api/user', [
            'headers' => ['Content-Type: application/json'],
            'raw'     => '{"user":',
        ]);

        assertSame(400, $response['status']);
        assertStringContains('Malformed JSON body', jsonOf($response)['message'] ?? '');
    });

    test('批量接口的校验错误带条目索引', function () {
        $response = http('POST', '/api/user/batch', [
            'json' => [
                'list' => [
                    ['user' => ['username' => '']],
                    ['user' => ['username' => '']],
                ],
            ],
        ]);

        assertSame(400, $response['status']);

        $errors = jsonOf($response)['errors'] ?? [];
        assertArrayHasKey('list.0.user.username', $errors);
        assertArrayHasKey('list.1.user.username', $errors);
    });

    test('批量接口成功时返回全部条目', function () {
        $response = http('POST', '/api/user/batch', [
            'json' => [
                'list' => [
                    ['user' => ['username' => 'u001', 'age' => 20]],
                    ['user' => ['username' => 'u002', 'age' => 30]],
                ],
            ],
        ]);

        assertSame(200, $response['status']);

        $list = jsonOf($response)['list'] ?? [];
        assertCount(2, $list);
        assertSame('u002', $list[1]['user']['username'] ?? null);
    });

    test('未知路径返回 404 JSON', function () {
        $response = http('GET', '/nope');

        assertSame(404, $response['status']);
        assertSame('Route not found', jsonOf($response)['message'] ?? null);
    });

    test('方法不匹配返回 405 并带 Allow', function () {
        $response = http('DELETE', '/api/users');

        assertSame(405, $response['status']);
        assertStringContains('GET', headerOf($response, 'Allow'));
        assertStringContains('OPTIONS', headerOf($response, 'Allow'));
    });

    test('浏览器 Accept 拿到 HTML 错误页', function () {
        $response = http('GET', '/nope', [
            'headers' => ['Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8'],
        ]);

        assertSame(404, $response['status']);
        assertStringContains('text/html', headerOf($response, 'Content-Type'));
        assertStringContains('<!DOCTYPE html>', $response['body']);
        assertStringContains('404', $response['body']);
    });

    test('HEAD 返回 200 但无响应体', function () {
        $response = http('HEAD', '/api/user/42');

        assertSame(200, $response['status']);
        assertSame('', $response['body']);
    });

    test('Response::html 正常渲染且转义查询参数', function () {
        $page = http('GET', '/demo/page?name=' . urlencode('<script>alert(1)</script>'));

        assertSame(200, $page['status']);
        assertStringContains('text/html', headerOf($page, 'Content-Type'));
        assertStringContains('&lt;script&gt;', $page['body']);
        assertStringNotContains('<script>alert(1)</script>', $page['body']);
    });

    test('Response::redirect 返回 302 与 Location', function () {
        $response = http('GET', '/demo/go');

        assertSame(302, $response['status']);
        assertSame('/demo/page?name=redirected', headerOf($response, 'Location'));
    });

    test('Response::noContent 返回 204 且无响应体', function () {
        $response = http('GET', '/demo/ping');

        assertSame(204, $response['status']);
        assertSame('', $response['body']);
    });

    test('Response::download 带下载头', function () {
        $response = http('GET', '/demo/download');

        assertSame(200, $response['status']);
        assertStringContains('attachment;', headerOf($response, 'Content-Disposition'));
        assertStringContains('filename=', headerOf($response, 'Content-Disposition'));
        assertGreaterThan(0, strlen($response['body']));
    });

    test('路由缓存生效后接口行为不变', function () {
        // 首次请求已经写过缓存（APP_DEBUG=0），再打一轮验证命中缓存路径
        assertFileExists(PHPET_ROOT . '/runtime/cache/routes.php');

        $response = http('GET', '/api/user/7');

        assertSame(200, $response['status']);
        assertSame(7, jsonOf($response)['id'] ?? null);
    });
} catch (\Throwable $e) {
    Harness::$failures++;
    Harness::$results[] = [
        'file'    => Harness::$file,
        'name'    => '(初始化失败)',
        'ok'      => false,
        'skipped' => false,
        'message' => get_class($e) . ': ' . $e->getMessage(),
        'ms'      => 0.0,
    ];
    printf("  %s %s\n", Harness::color('✘', '31'), $e->getMessage());
} finally {
    proc_terminate($server);
    proc_close($server);
    @unlink($serverLog);
}

echo "\n";

exit(report_summary($started));
