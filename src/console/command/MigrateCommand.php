<?php

namespace phpet\console\command;

use phpet\console\Command;
use phpet\console\Input;
use phpet\console\Output;
use phpet\db\Migrator;

/**
 * 执行全部待执行迁移。
 */
final class MigrateCommand extends Command
{
    public function name(): string
    {
        return 'db:migrate';
    }

    public function description(): string
    {
        return 'Run pending migrations';
    }

    public function usage(): string
    {
        return 'console db:migrate';
    }

    public function run(Input $input, Output $output): int
    {
        $migrator = new Migrator($this->app()->connection(), $this->app()->migrationPath());

        if ($migrator->pending() === []) {
            $output->warn('Nothing to migrate.');

            return 0;
        }

        $applied = $migrator->migrate();

        foreach ($applied as $name) {
            $output->success($name);
        }

        $output->newline();
        $output->dim(count($applied) . ' migration(s) applied.');

        return 0;
    }
}
