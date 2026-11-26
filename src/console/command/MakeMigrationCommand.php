<?php

namespace phpet\console\command;

use phpet\console\Command;
use phpet\console\Input;
use phpet\console\Output;

/**
 * 生成一个迁移文件骨架。
 *
 * 文件名带时间戳，保证按文件名排序就等于按创建顺序执行。
 */
final class MakeMigrationCommand extends Command
{
    private const TEMPLATE = <<<'PHP'
<?php

use phpet\db\Connection;
use phpet\db\Migration;

return new class extends Migration {
    public function up(Connection $db): void
    {
        $db->execute('create table example (id integer primary key, name text not null)');
    }

    public function down(Connection $db): void
    {
        $db->execute('drop table example');
    }
};

PHP;

    public function name(): string
    {
        return 'make:migration';
    }

    public function description(): string
    {
        return 'Create a migration file';
    }

    public function usage(): string
    {
        return 'console make:migration create_users';
    }

    public function run(Input $input, Output $output): int
    {
        $name = (string)$input->argument(0, '');

        // 名字会变成文件名的一部分，只允许小写字母、数字、下划线，杜绝路径穿越
        if (!preg_match('/^[a-z0-9_]+$/', $name)) {
            $output->error('Migration name must match /^[a-z0-9_]+$/ (e.g. create_users)');

            return 2;
        }

        $dir = $this->app()->migrationPath();

        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            $output->error("Cannot create directory: {$dir}");

            return 1;
        }

        $file = $dir . '/' . date('Y_m_d_His') . '_' . $name . '.php';

        if (is_file($file)) {
            $output->error('A migration with the same timestamp already exists: ' . basename($file));

            return 1;
        }

        if (@file_put_contents($file, self::TEMPLATE) === false) {
            $output->error("Cannot write file: {$file}");

            return 1;
        }

        $output->success('Created ' . $this->relative($file));

        return 0;
    }

    private function relative(string $file): string
    {
        $base = $this->app()->basePath();

        if (str_starts_with($file, $base . '/')) {
            return substr($file, strlen($base) + 1);
        }

        return $file;
    }
}
