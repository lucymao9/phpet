<?php

namespace phpet\console\command;

use phpet\console\Command;
use phpet\console\Input;
use phpet\console\Output;
use phpet\db\Migrator;

/**
 * 查看迁移状态：磁盘上有哪些、哪些已执行、在第几批。
 */
final class StatusCommand extends Command
{
    public function name(): string
    {
        return 'db:status';
    }

    public function description(): string
    {
        return 'Show migration status';
    }

    public function usage(): string
    {
        return 'console db:status';
    }

    public function run(Input $input, Output $output): int
    {
        $migrator = new Migrator($this->app()->connection(), $this->app()->migrationPath());

        $status = $migrator->status();

        if ($status === []) {
            $output->warn('No migration files in ' . $migrator->path());

            return 0;
        }

        $rows = [];
        $pending = 0;

        foreach ($status as $item) {
            $rows[] = [
                $item['name'],
                $item['batch'] === null ? '-' : (string)$item['batch'],
                $item['applied_at'] ?? 'pending',
            ];

            if ($item['batch'] === null) {
                $pending++;
            }
        }

        $output->table(['Migration', 'Batch', 'Applied at'], $rows);
        $output->newline();
        $output->dim(sprintf('%d total, %d pending.', count($status), $pending));

        return 0;
    }
}
