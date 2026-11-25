<?php

namespace phpet\core\Log;

/**
 * 极简分级日志。
 *
 * 默认写入项目根目录的 runtime/logs/，该目录位于 web 根（public/）之外，
 * 避免日志被直接下载。可通过 init() 覆盖落盘目录。
 */
class Logger
{
    public const DEBUG = 'debug';
    public const INFO = 'info';
    public const WARNING = 'warning';
    public const ERROR = 'error';

    private static ?string $dir = null;

    private const LEVEL_WEIGHT = [
        self::DEBUG => 0,
        self::INFO => 1,
        self::WARNING => 2,
        self::ERROR => 3,
    ];

    private static string $minLevel = self::DEBUG;

    public static function init(?string $dir = null, string $minLevel = self::DEBUG): void
    {
        if ($dir !== null) {
            self::$dir = rtrim($dir, '/\\');
        }
        self::$minLevel = $minLevel;
    }

    public static function debug(string $message, array $context = []): void
    {
        self::log(self::DEBUG, $message, $context);
    }

    public static function info(string $message, array $context = []): void
    {
        self::log(self::INFO, $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::log(self::WARNING, $message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::log(self::ERROR, $message, $context);
    }

    public static function log(string $level, string $message, array $context = []): void
    {
        if (self::LEVEL_WEIGHT[$level] < self::LEVEL_WEIGHT[self::$minLevel]) {
            return;
        }

        $dir = self::$dir ?? self::defaultDir();
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            // 日志目录不可写时退回系统日志，绝不因日志失败影响主流程
            error_log("[phpet][{$level}] {$message}");
            return;
        }

        $line = sprintf(
            "[%s] %s: %s%s%s",
            date('Y-m-d H:i:s'),
            strtoupper($level),
            $message,
            $context ? ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '',
            PHP_EOL
        );

        @file_put_contents(
            $dir . '/app-' . date('Y-m-d') . '.log',
            $line,
            FILE_APPEND | LOCK_EX
        );
    }

    public static function defaultDir(): string
    {
        // src/core/Log -> 项目根
        return dirname(__DIR__, 3) . '/runtime/logs';
    }
}
