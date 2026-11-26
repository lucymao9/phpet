<?php
declare(strict_types=1);

use phpet\core\Log\Logger;

/**
 * 在独立日志目录里跑一段逻辑，结束后清理并把日志目录还原。
 */
function withLogDir(callable $fn, string $minLevel = Logger::DEBUG)
{
    $dir = mktmp('phpet-log-');
    Logger::init($dir, $minLevel);

    try {
        return $fn($dir);
    } finally {
        Logger::init(sys_get_temp_dir() . '/phpet-tests-logs', Logger::DEBUG);
        rmtree($dir);
    }
}

function todayLogFile(string $dir): string
{
    return $dir . '/app-' . date('Y-m-d') . '.log';
}

test('日志写入按日期命名的文件', function () {
    withLogDir(function (string $dir) {
        Logger::info('hello');

        assertFileExists(todayLogFile($dir));
    });
});

test('日志行包含时间、级别、消息与上下文', function () {
    withLogDir(function (string $dir) {
        Logger::info('user login', ['userId' => 7]);

        $line = (string)file_get_contents(todayLogFile($dir));

        assertMatches('/^\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\] INFO: user login/', $line);
        assertStringContains('"userId":7', $line);
    });
});

test('上下文中的中文保持可读', function () {
    withLogDir(function (string $dir) {
        Logger::error('失败', ['原因' => '超时']);

        assertStringContains('{"原因":"超时"}', (string)file_get_contents(todayLogFile($dir)));
    });
});

test('多次写入追加而非覆盖', function () {
    withLogDir(function (string $dir) {
        Logger::info('first');
        Logger::info('second');

        $content = (string)file_get_contents(todayLogFile($dir));

        assertStringContains('first', $content);
        assertStringContains('second', $content);
        assertSame(2, substr_count($content, PHP_EOL));
    });
});

test('minLevel 以下的日志被丢弃', function () {
    withLogDir(function (string $dir) {
        Logger::debug('d');
        Logger::info('i');
        Logger::warning('w');
        Logger::error('e');

        $content = (string)file_get_contents(todayLogFile($dir));

        assertStringNotContains('d' . PHP_EOL, $content);
        assertStringNotContains('i' . PHP_EOL, $content);
        assertStringContains('WARNING: w', $content);
        assertStringContains('ERROR: e', $content);
    }, Logger::WARNING);
});

test('minLevel 为 ERROR 时只留错误', function () {
    withLogDir(function (string $dir) {
        Logger::warning('w');
        Logger::error('e');

        $content = (string)file_get_contents(todayLogFile($dir));

        assertStringNotContains('w', $content);
        assertStringContains('e', $content);
    }, Logger::ERROR);
});

test('日志目录不可写时退回系统错误日志，不影响主流程', function () {
    $dir = mktmp('phpet-log-');
    $blocker = $dir . '/blocker';
    file_put_contents($blocker, 'x');

    $fallback = tempnam(sys_get_temp_dir(), 'phpet-errlog-');
    $previous = ini_set('error_log', $fallback);

    try {
        Logger::init($blocker . '/logs', Logger::DEBUG);

        // 关键点：不抛异常
        Logger::error('fallback-boom');

        assertStringContains('fallback-boom', (string)file_get_contents($fallback));
    } finally {
        ini_set('error_log', (string)$previous);
        Logger::init(sys_get_temp_dir() . '/phpet-tests-logs', Logger::DEBUG);
        @unlink($fallback);
        rmtree($dir);
    }
});
