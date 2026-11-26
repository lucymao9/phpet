<?php

namespace phpet\db;

/**
 * 极简迁移器。
 *
 * 只做四件事：记录已执行、按文件名顺序执行待执行、按批次回滚、查状态。
 * 迁移文件之间没有依赖推导，顺序完全由文件名决定，所以命名请带时间戳：
 * 2026_11_26_120000_create_users.php
 *
 * 每个迁移**不包在事务里**：MySQL 的 DDL 是隐式提交的，包了也只是给人
 * 错误的安全感。需要原子性时请在 up() 内部自己判断并补偿。
 */
class Migrator
{
    private const TABLE = 'migrations';

    public function __construct(
        private Connection $db,
        private string $dir
    ) {
    }

    public function path(): string
    {
        return $this->dir;
    }

    /**
     * 建记录表（幂等），migrate / status / rollback 都会先调用。
     */
    public function ensureRepository(): void
    {
        $this->db->execute($this->createTableSql());
    }

    /**
     * 执行全部待执行迁移，返回本次执行的名字。
     *
     * @return string[]
     */
    public function migrate(): array
    {
        $this->ensureRepository();

        $pending = $this->pending();

        if ($pending === []) {
            return [];
        }

        $batch = $this->nextBatch();
        $applied = [];

        foreach ($pending as $name) {
            $migration = $this->load($name);
            $migration->up($this->db);

            $this->db->execute(
                'insert into ' . $this->quote(self::TABLE)
                . ' (' . $this->quote('migration') . ', ' . $this->quote('batch')
                . ', ' . $this->quote('applied_at') . ') values (?, ?, ?)',
                [$name, $batch, date('Y-m-d H:i:s')]
            );

            $applied[] = $name;
        }

        return $applied;
    }

    /**
     * 回滚最近 $steps 个批次（不是最近 $steps 个迁移）。
     *
     * @return string[]
     */
    public function rollback(int $steps = 1): array
    {
        if ($steps < 1) {
            throw new \InvalidArgumentException('Rollback steps must be at least 1');
        }

        $this->ensureRepository();

        $batches = $this->db->select(
            'select distinct ' . $this->quote('batch') . ' from ' . $this->quote(self::TABLE)
            . ' order by ' . $this->quote('batch') . ' desc limit ' . $steps
        );

        if ($batches === []) {
            return [];
        }

        $rolledBack = [];

        foreach ($batches as $row) {
            $batch = (int)$row['batch'];

            $names = $this->db->select(
                'select ' . $this->quote('migration') . ' from ' . $this->quote(self::TABLE)
                . ' where ' . $this->quote('batch') . ' = ?'
                . ' order by ' . $this->quote('migration') . ' desc',
                [$batch]
            );

            foreach ($names as $record) {
                $name = (string)$record['migration'];

                $this->load($name)->down($this->db);

                $this->db->execute(
                    'delete from ' . $this->quote(self::TABLE) . ' where ' . $this->quote('migration') . ' = ?',
                    [$name]
                );

                $rolledBack[] = $name;
            }
        }

        return $rolledBack;
    }

    /**
     * 磁盘上有、但还没执行的迁移名，按文件名排序。
     *
     * @return string[]
     */
    public function pending(): array
    {
        $applied = $this->applied();

        return array_values(array_filter(
            $this->files(),
            static fn(string $name): bool => !isset($applied[$name])
        ));
    }

    /**
     * @return array<string, array{batch: int, applied_at: string}>
     */
    public function applied(): array
    {
        $this->ensureRepository();

        $rows = $this->db->select(
            'select ' . $this->quote('migration') . ', ' . $this->quote('batch')
            . ', ' . $this->quote('applied_at') . ' from ' . $this->quote(self::TABLE)
        );

        $applied = [];

        foreach ($rows as $row) {
            $applied[(string)$row['migration']] = [
                'batch'      => (int)$row['batch'],
                'applied_at' => (string)$row['applied_at'],
            ];
        }

        return $applied;
    }

    /**
     * 全部迁移的状态，按文件名排序。
     *
     * @return array<int, array{name: string, batch: int|null, applied_at: string|null}>
     */
    public function status(): array
    {
        $applied = $this->applied();
        $result = [];

        foreach ($this->files() as $name) {
            $result[] = [
                'name'       => $name,
                'batch'      => $applied[$name]['batch'] ?? null,
                'applied_at' => $applied[$name]['applied_at'] ?? null,
            ];
        }

        return $result;
    }

    /**
     * 磁盘上存在的迁移文件名（不含 .php），按文件名排序。
     *
     * @return string[]
     */
    public function files(): array
    {
        if (!is_dir($this->dir)) {
            return [];
        }

        $names = [];

        foreach ((array)scandir($this->dir) as $entry) {
            $entry = (string)$entry;

            if ($entry === '.' || $entry === '..' || !str_ends_with($entry, '.php')) {
                continue;
            }

            $names[] = substr($entry, 0, -4);
        }

        sort($names, SORT_STRING);

        return $names;
    }

    public function load(string $name): Migration
    {
        $path = $this->dir . '/' . $name . '.php';

        if (!is_file($path)) {
            throw new \RuntimeException("Migration file not found: {$path}");
        }

        $migration = require $path;

        if (!$migration instanceof Migration) {
            throw new \RuntimeException(
                "Migration {$name} must return an instance of " . Migration::class
            );
        }

        return $migration;
    }

    private function nextBatch(): int
    {
        $max = $this->db->scalar('select max(' . $this->quote('batch') . ') from ' . $this->quote(self::TABLE));

        return (int)$max + 1;
    }

    private function createTableSql(): string
    {
        $table = $this->quote(self::TABLE);

        if ($this->driver() === 'sqlite') {
            return 'create table if not exists ' . $table . ' ('
                . 'id integer primary key autoincrement, '
                . $this->quote('migration') . ' text not null unique, '
                . $this->quote('batch') . ' integer not null, '
                . $this->quote('applied_at') . ' text not null)';
        }

        return 'create table if not exists ' . $table . ' ('
            . 'id int unsigned not null auto_increment, '
            . $this->quote('migration') . ' varchar(191) not null, '
            . $this->quote('batch') . ' int not null, '
            . $this->quote('applied_at') . ' varchar(19) not null, '
            . 'primary key (id), '
            . 'unique key ' . $this->quote('uni_migrations_migration') . ' (' . $this->quote('migration') . ')'
            . ') engine=InnoDB default charset=utf8mb4';
    }

    private function driver(): string
    {
        $config = $this->db->config();
        $driver = (string)($config['driver'] ?? '');

        if ($driver !== '') {
            return $driver;
        }

        return str_starts_with($this->db->dsn(), 'sqlite:') ? 'sqlite' : 'mysql';
    }

    private function quote(string $identifier): string
    {
        return '`' . $identifier . '`';
    }
}
