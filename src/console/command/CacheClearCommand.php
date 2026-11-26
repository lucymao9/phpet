<?php

namespace phpet\console\command;

use phpet\cache\FileCache;
use phpet\console\Command;
use phpet\console\Input;
use phpet\console\Output;

/**
 * 清空通用缓存。
 *
 * `--expired` 只删已过期的，适合放定时任务里；不带参数是全清。
 */
final class CacheClearCommand extends Command
{
    public function name(): string
    {
        return 'cache:clear';
    }

    public function description(): string
    {
        return 'Clear the application cache';
    }

    public function usage(): string
    {
        return 'console cache:clear [--expired]';
    }

    public function run(Input $input, Output $output): int
    {
        $app = $this->app();
        $cache = $app->cache();
        $dir = $app->cacheDir();

        if ($input->hasOption('expired')) {
            if (!$cache instanceof FileCache) {
                $output->error('--expired is only supported by the file cache driver');

                return 1;
            }

            $removed = $cache->prune();
            $output->success("Removed {$removed} expired cache file(s).");

            return 0;
        }

        if (!$cache->clear()) {
            $output->error('Failed to clear cache directory: ' . $dir);

            return 1;
        }

        $output->success('Cache cleared: ' . $dir);

        return 0;
    }
}
