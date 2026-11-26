<?php
declare(strict_types=1);

/**
 * 极简测试框架，无第三方依赖。
 *
 * 用法：
 *   test('路由命中静态路径', function () {
 *       assertSame('pong', $response->getContent());
 *   });
 *
 * 断言失败抛 AssertionFailed，只影响当前用例，不影响后续用例执行。
 */

if (PHP_SAPI !== 'cli') {
    exit("测试只能在 CLI 下运行\n");
}

final class AssertionFailed extends \Exception
{
}

final class TestSkipped extends \Exception
{
}

final class Harness
{
    /** @var array<int, array{file:string, name:string, ok:bool, skipped:bool, message:string, ms:float}> */
    public static array $results = [];

    public static int $assertions = 0;
    public static int $failures = 0;
    public static int $skipped = 0;

    public static bool $color = false;

    /** 当前所在的用例文件（用于展示与过滤） */
    public static string $file = '';

    /** 只运行名称或文件名包含该子串的用例 */
    public static ?string $filter = null;

    /** 当前文件的分组标题是否已输出（被过滤掉的文件不留空标题） */
    public static bool $headerPrinted = false;

    private static int $assertionsInTest = 0;

    public static function color(string $text, string $code): string
    {
        return self::$color ? "\033[{$code}m{$text}\033[0m" : $text;
    }

    public static function test(string $name, callable $fn): void
    {
        if (self::$filter !== null
            && stripos(self::$file . ' ' . $name, self::$filter) === false) {
            return;
        }

        if (!self::$headerPrinted) {
            self::$headerPrinted = true;
            printf("%s\n", self::color('▸ ' . self::$file, '36'));
        }

        $start = microtime(true);
        self::$assertionsInTest = 0;

        try {
            $fn();

            self::record($name, true, false, '', $start);

            printf(
                "  %s %s %s\n",
                self::color('✔', '32'),
                $name,
                self::color(self::meta($start), '90')
            );
        } catch (TestSkipped $e) {
            self::$skipped++;
            self::record($name, false, true, $e->getMessage(), $start);
            printf(
                "  %s %s %s\n",
                self::color('○', '33'),
                $name,
                self::color('跳过：' . $e->getMessage(), '90')
            );
        } catch (AssertionFailed $e) {
            self::$failures++;
            self::record($name, false, false, $e->getMessage(), $start);
            printf(
                "  %s %s\n      %s\n",
                self::color('✘', '31'),
                $name,
                self::color($e->getMessage(), '31')
            );
        } catch (\Throwable $e) {
            // 未预期的异常同样算失败，带上类型与位置，否则排查很痛苦
            self::$failures++;
            $message = sprintf(
                '未捕获 %s: %s  [%s:%d]',
                get_class($e),
                $e->getMessage(),
                self::shortPath($e->getFile()),
                $e->getLine()
            );
            self::record($name, false, false, $message, $start);
            printf(
                "  %s %s\n      %s\n",
                self::color('✘', '31'),
                $name,
                self::color($message, '31')
            );
        }
    }

    public static function shortPath(string $path): string
    {
        $root = defined('PHPET_ROOT') ? PHPET_ROOT . '/' : '';

        return $root !== '' && str_starts_with($path, $root)
            ? substr($path, strlen($root))
            : $path;
    }

    private static function meta(float $start): string
    {
        $ms = (microtime(true) - $start) * 1000;

        return sprintf('%d 断言 · %.1fms', self::$assertionsInTest, $ms);
    }

    private static function record(
        string $name,
        bool   $ok,
        bool   $skipped,
        string $message,
        float  $start
    ): void {
        self::$results[] = [
            'file'    => self::$file,
            'name'    => $name,
            'ok'      => $ok,
            'skipped' => $skipped,
            'message' => $message,
            'ms'      => (microtime(true) - $start) * 1000,
        ];
    }

    public static function countAssertion(): void
    {
        self::$assertions++;
        self::$assertionsInTest++;
    }

    /**
     * 失败信息追加调用位置，形如 [tests/cases/01_router.php:42]
     */
    public static function fail(string $message): never
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            if (!isset($frame['file'], $frame['line'])) {
                continue;
            }
            if (str_ends_with($frame['file'], DIRECTORY_SEPARATOR . 'lib.php')) {
                continue;
            }
            $message .= sprintf(
                '  [%s:%d]',
                self::shortPath($frame['file']),
                $frame['line']
            );
            break;
        }

        throw new AssertionFailed($message);
    }
}

/* ------------------------------------------------------------------ *
 * 用例声明
 * ------------------------------------------------------------------ */

function test(string $name, callable $fn): void
{
    Harness::test($name, $fn);
}

/* ------------------------------------------------------------------ *
 * 断言
 * ------------------------------------------------------------------ */

function assertTrue($actual, string $message = ''): void
{
    Harness::countAssertion();
    if ($actual !== true) {
        Harness::fail(($message !== '' ? $message . '：' : '') . '期望 true，实际 ' . dump($actual));
    }
}

function assertFalse($actual, string $message = ''): void
{
    Harness::countAssertion();
    if ($actual !== false) {
        Harness::fail(($message !== '' ? $message . '：' : '') . '期望 false，实际 ' . dump($actual));
    }
}

function assertNull($actual, string $message = ''): void
{
    Harness::countAssertion();
    if ($actual !== null) {
        Harness::fail(($message !== '' ? $message . '：' : '') . '期望 null，实际 ' . dump($actual));
    }
}

function assertNotNull($actual, string $message = ''): void
{
    Harness::countAssertion();
    if ($actual === null) {
        Harness::fail(($message !== '' ? $message . '：' : '') . '期望非 null，实际 null');
    }
}

function assertSame($expected, $actual, string $message = ''): void
{
    Harness::countAssertion();
    if ($expected !== $actual) {
        Harness::fail(sprintf(
            '%s期望 %s，实际 %s',
            $message !== '' ? $message . '：' : '',
            dump($expected),
            dump($actual)
        ));
    }
}

function assertNotSame($expected, $actual, string $message = ''): void
{
    Harness::countAssertion();
    if ($expected === $actual) {
        Harness::fail(($message !== '' ? $message . '：' : '') . '期望两者不是同一值，实际相同 ' . dump($actual));
    }
}

function assertEquals($expected, $actual, string $message = ''): void
{
    Harness::countAssertion();
    if ($expected != $actual) {
        Harness::fail(sprintf(
            '%s期望 %s，实际 %s',
            $message !== '' ? $message . '：' : '',
            dump($expected),
            dump($actual)
        ));
    }
}

function assertCount(int $expected, $actual, string $message = ''): void
{
    Harness::countAssertion();
    $count = is_countable($actual) ? count($actual) : null;
    if ($count !== $expected) {
        Harness::fail(sprintf(
            '%s期望 %d 个，实际 %s',
            $message !== '' ? $message . '：' : '',
            $expected,
            $count === null ? dump($actual) . '（不可计数）' : $count . ' 个'
        ));
    }
}

function assertEmpty($actual, string $message = ''): void
{
    Harness::countAssertion();
    if (!empty($actual)) {
        Harness::fail(($message !== '' ? $message . '：' : '') . '期望为空，实际 ' . dump($actual));
    }
}

function assertNotEmpty($actual, string $message = ''): void
{
    Harness::countAssertion();
    if (empty($actual)) {
        Harness::fail(($message !== '' ? $message . '：' : '') . '期望非空，实际为空');
    }
}

function assertContains($needle, array $haystack, string $message = ''): void
{
    Harness::countAssertion();
    if (!in_array($needle, $haystack, true)) {
        Harness::fail(sprintf(
            '%s期望包含 %s，实际 %s',
            $message !== '' ? $message . '：' : '',
            dump($needle),
            dump($haystack)
        ));
    }
}

function assertNotContains($needle, array $haystack, string $message = ''): void
{
    Harness::countAssertion();
    if (in_array($needle, $haystack, true)) {
        Harness::fail(sprintf(
            '%s期望不包含 %s，实际 %s',
            $message !== '' ? $message . '：' : '',
            dump($needle),
            dump($haystack)
        ));
    }
}

function assertArrayHasKey($key, array $array, string $message = ''): void
{
    Harness::countAssertion();
    if (!array_key_exists($key, $array)) {
        Harness::fail(sprintf(
            '%s期望存在键 %s，实际键为 %s',
            $message !== '' ? $message . '：' : '',
            dump($key),
            dump(array_keys($array))
        ));
    }
}

function assertArrayNotHasKey($key, array $array, string $message = ''): void
{
    Harness::countAssertion();
    if (array_key_exists($key, $array)) {
        Harness::fail(($message !== '' ? $message . '：' : '') . '期望不存在键 ' . dump($key));
    }
}

function assertStringContains(string $needle, ?string $haystack, string $message = ''): void
{
    Harness::countAssertion();
    if ($haystack === null || !str_contains($haystack, $needle)) {
        Harness::fail(sprintf(
            '%s期望包含 %s，实际 %s',
            $message !== '' ? $message . '：' : '',
            dump($needle),
            dump((string)$haystack)
        ));
    }
}

function assertStringNotContains(string $needle, ?string $haystack, string $message = ''): void
{
    Harness::countAssertion();
    if ($haystack !== null && str_contains($haystack, $needle)) {
        Harness::fail(sprintf(
            '%s期望不包含 %s，实际 %s',
            $message !== '' ? $message . '：' : '',
            dump($needle),
            dump($haystack)
        ));
    }
}

function assertMatches(string $pattern, ?string $subject, string $message = ''): void
{
    Harness::countAssertion();
    if ($subject === null || preg_match($pattern, $subject) !== 1) {
        Harness::fail(sprintf(
            '%s期望匹配 %s，实际 %s',
            $message !== '' ? $message . '：' : '',
            $pattern,
            dump((string)$subject)
        ));
    }
}

function assertInstanceOf(string $class, $actual, string $message = ''): void
{
    Harness::countAssertion();
    if (!$actual instanceof $class) {
        Harness::fail(sprintf(
            '%s期望 %s 的实例，实际 %s',
            $message !== '' ? $message . '：' : '',
            $class,
            is_object($actual) ? get_class($actual) : dump($actual)
        ));
    }
}

function assertGreaterThan($expected, $actual, string $message = ''): void
{
    Harness::countAssertion();
    if (!($actual > $expected)) {
        Harness::fail(sprintf(
            '%s期望大于 %s，实际 %s',
            $message !== '' ? $message . '：' : '',
            dump($expected),
            dump($actual)
        ));
    }
}

function assertLessThan($expected, $actual, string $message = ''): void
{
    Harness::countAssertion();
    if (!($actual < $expected)) {
        Harness::fail(sprintf(
            '%s期望小于 %s，实际 %s',
            $message !== '' ? $message . '：' : '',
            dump($expected),
            dump($actual)
        ));
    }
}

function assertFileExists(string $path, string $message = ''): void
{
    Harness::countAssertion();
    if (!is_file($path)) {
        Harness::fail(($message !== '' ? $message . '：' : '') . '期望文件存在 ' . $path);
    }
}

/**
 * 断言抛出指定异常，返回该异常便于进一步检查。
 * $messageContains 传入时，额外断言异常消息包含该子串。
 */
function assertThrows(string $class, callable $fn, ?string $messageContains = null): \Throwable
{
    Harness::countAssertion();

    try {
        $fn();
    } catch (\Throwable $e) {
        if (!$e instanceof $class) {
            Harness::fail(sprintf(
                '期望抛出 %s，实际抛出 %s: %s',
                $class,
                get_class($e),
                $e->getMessage()
            ));
        }
        if ($messageContains !== null && !str_contains($e->getMessage(), $messageContains)) {
            Harness::fail(sprintf(
                '期望异常消息包含 %s，实际 %s',
                dump($messageContains),
                dump($e->getMessage())
            ));
        }

        return $e;
    }

    Harness::fail('期望抛出 ' . $class . '，但没有抛出任何异常');
}

function skip(string $reason): never
{
    throw new TestSkipped($reason);
}

/* ------------------------------------------------------------------ *
 * 汇总
 * ------------------------------------------------------------------ */

/**
 * 打印统计与失败清单，返回进程退出码。
 */
function report_summary(float $startedAt): int
{
    $elapsed = (microtime(true) - $startedAt) * 1000;

    $total = count(Harness::$results);
    $passed = $total - Harness::$failures - Harness::$skipped;

    printf("%s\n", Harness::color(str_repeat('─', 64), '90'));

    if ($total === 0) {
        printf("%s\n", Harness::color('没有执行任何用例', '33'));

        return 1;
    }

    $summary = sprintf(
        '通过 %d  ·  失败 %d  ·  跳过 %d  ·  断言 %d  ·  %.0fms',
        $passed,
        Harness::$failures,
        Harness::$skipped,
        Harness::$assertions,
        $elapsed
    );

    printf(
        "%s\n",
        Harness::$failures === 0
            ? Harness::color('全部通过  ' . $summary, '32;1')
            : Harness::color('存在失败  ' . $summary, '31;1')
    );

    if (Harness::$failures > 0) {
        echo "\n";
        printf("%s\n", Harness::color('失败清单：', '31'));
        foreach (Harness::$results as $result) {
            if ($result['ok'] || $result['skipped']) {
                continue;
            }
            printf("  · %s  %s\n    %s\n", $result['file'], $result['name'], $result['message']);
        }
    }

    return Harness::$failures === 0 ? 0 : 1;
}

/* ------------------------------------------------------------------ *
 * 辅助
 * ------------------------------------------------------------------ */

/**
 * 紧凑打印任意值，用于断言失败信息。
 */
function dump($value): string
{
    if ($value === null) {
        return 'null';
    }
    if (is_bool($value)) {
        return $value ? 'true' : 'false';
    }
    if (is_string($value)) {
        $text = mb_strlen($value) > 120 ? mb_substr($value, 0, 120) . '…' : $value;

        return "'" . str_replace(["\n", "\r"], ['\n', '\r'], $text) . "'";
    }
    if (is_int($value) || is_float($value)) {
        return (string)$value;
    }
    if (is_object($value)) {
        return get_class($value) . ' ' . dump(get_object_vars($value));
    }

    $json = json_encode(
        $value,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR
    );

    if (!is_string($json)) {
        return gettype($value);
    }

    return mb_strlen($json) > 240 ? mb_substr($json, 0, 240) . '…' : $json;
}

/**
 * 创建临时目录，调用方负责 rmtree()。
 */
function mktmp(string $prefix = 'phpet-test-'): string
{
    $dir = sys_get_temp_dir() . '/' . $prefix . bin2hex(random_bytes(6));
    if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new \RuntimeException('无法创建临时目录：' . $dir);
    }

    return $dir;
}

function rmtree(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }

    $iterator = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iterator as $entry) {
        $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
    }

    @rmdir($dir);
}

/**
 * 临时替换 HTTP_ACCEPT，执行后还原。
 */
function withAccept(?string $accept, callable $fn)
{
    $had = array_key_exists('HTTP_ACCEPT', $_SERVER);
    $old = $_SERVER['HTTP_ACCEPT'] ?? null;

    if ($accept === null) {
        unset($_SERVER['HTTP_ACCEPT']);
    } else {
        $_SERVER['HTTP_ACCEPT'] = $accept;
    }

    try {
        return $fn();
    } finally {
        if ($had) {
            $_SERVER['HTTP_ACCEPT'] = $old;
        } else {
            unset($_SERVER['HTTP_ACCEPT']);
        }
    }
}

/**
 * 临时覆盖若干 $_GET / $_SERVER 值，执行后还原。
 */
function withGlobals(array $vars, callable $fn)
{
    $saved = ['_GET' => $_GET, '_SERVER' => $_SERVER, '_COOKIE' => $_COOKIE];

    foreach ($vars as $name => $value) {
        if (!array_key_exists($name, $GLOBALS)) {
            throw new \RuntimeException('未知全局变量 ' . $name);
        }
        $GLOBALS[$name] = $value;
    }

    try {
        return $fn();
    } finally {
        $_GET = $saved['_GET'];
        $_SERVER = $saved['_SERVER'];
        $_COOKIE = $saved['_COOKIE'];
    }
}
