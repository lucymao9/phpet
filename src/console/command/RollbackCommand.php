<?php

namespace phpet\console\command;

use phpet\console\Command;
use phpet\console\Input;
use phpet\console\Output;
use phpet\db\Migrator;

/**
 * 回滚迁移。按**批次**回滚，不是按单个迁移。
 */
final class RollbackCommand extends Command
{
    public function name(): string
    {
        return 'db:rollback';
    }

    public function description(): string
    {
        return 'Roll back the last migration batch';
    }

    public function usage(): string
    {
        return 'console db:rollback [--steps=1]';
    }

    public function run(Input $input, Output $output): int
    {
        $steps = $input->intOption('steps', 1);

        if ($steps === null || $steps < 1) {
            $output->error('--steps expects an integer >= 1');

            return 2;
        }

        $migrator = new Migrator($this->app()->connection(), $this->app()->migrationPath());

        if ($migrator->applied() === []) {
            $output->warn('Nothing to roll back.');

            return 0;
        }

        $rolledBack = $migrator->rollback($steps);

        foreach ($rolledBack as $name) {
            $output->success($name);
        }

        $output->newline();
        $output->dim(count($rolledBack) . ' migration(s) rolled back.');

        return 0;
    }
}
