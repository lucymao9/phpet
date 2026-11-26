<?php

namespace phpet\db;

use PDO;
use PDOStatement;

/**
 * 一层很薄的 PDO 封装：惰性连接、参数绑定、事务（含嵌套）。
 *
 * 刻意不做连接池、读写分离、重试——PHP 的请求模型下这些多半是负担，
 * 真要就说清楚：一个请求一个连接，请求结束就释放。
 */
class Connection
{
    private ?PDO $pdo = null;

    /** @var array<string, mixed> */
    private array $config;

    /** 当前嵌套深度；0 表示没有活动事务 */
    private int $level = 0;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function fromConfig(array $config): self
    {
        return new self($config);
    }

    /**
     * @return array<string, mixed>
     */
    public function config(): array
    {
        return $this->config;
    }

    public function dsn(): string
    {
        $explicit = $this->config['dsn'] ?? null;

        if (is_string($explicit) && $explicit !== '') {
            return $explicit;
        }

        $driver = (string)($this->config['driver'] ?? 'sqlite');
        $database = (string)($this->config['database'] ?? '');

        if ($database === '') {
            throw new \InvalidArgumentException('Database config requires "database" (or an explicit "dsn")');
        }

        switch ($driver) {
            case 'sqlite':
                return 'sqlite:' . $database;

            case 'mysql':
                return sprintf(
                    'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                    (string)($this->config['host'] ?? '127.0.0.1'),
                    (int)($this->config['port'] ?? 3306),
                    $database,
                    (string)($this->config['charset'] ?? 'utf8mb4')
                );

            case 'pgsql':
                return sprintf(
                    'pgsql:host=%s;port=%d;dbname=%s',
                    (string)($this->config['host'] ?? '127.0.0.1'),
                    (int)($this->config['port'] ?? 5432),
                    $database
                );

            default:
                throw new \InvalidArgumentException("Unsupported database driver '{$driver}'");
        }
    }

    public function pdo(): PDO
    {
        return $this->pdo ??= $this->connect();
    }

    public function isConnected(): bool
    {
        return $this->pdo !== null;
    }

    public function disconnect(): void
    {
        $this->pdo = null;
        $this->level = 0;
    }

    /**
     * @param array<int|string, mixed> $bindings
     * @return array<int, array<string, mixed>>
     */
    public function select(string $sql, array $bindings = []): array
    {
        return $this->run($sql, $bindings)->fetchAll();
    }

    /**
     * @param array<int|string, mixed> $bindings
     * @return array<string, mixed>|null
     */
    public function selectOne(string $sql, array $bindings = []): ?array
    {
        $row = $this->run($sql, $bindings)->fetch();

        return $row === false ? null : $row;
    }

    /**
     * 取第一行第一列，常用于 count / sum / exists 这类查询。
     *
     * @param array<int|string, mixed> $bindings
     */
    public function scalar(string $sql, array $bindings = []): mixed
    {
        $value = $this->run($sql, $bindings)->fetchColumn();

        return $value === false ? null : $value;
    }

    /**
     * 执行写语句，返回受影响行数。
     *
     * @param array<int|string, mixed> $bindings
     */
    public function execute(string $sql, array $bindings = []): int
    {
        return $this->run($sql, $bindings)->rowCount();
    }

    public function lastInsertId(): string
    {
        return $this->pdo()->lastInsertId();
    }

    public function table(string $table, ?string $alias = null): QueryBuilder
    {
        return new QueryBuilder($this, $table, $alias);
    }

    /**
     * 在一个事务里执行回调，回调收到连接本身。
     *
     * 嵌套调用会用 savepoint，所以内层失败只回滚内层，
     * 不会把外层已经写完的数据一起丢掉。
     */
    public function transaction(callable $callback): mixed
    {
        $this->beginTransaction();

        try {
            $result = $callback($this);
        } catch (\Throwable $e) {
            $this->rollBack();

            throw $e;
        }

        $this->commit();

        return $result;
    }

    public function beginTransaction(): void
    {
        if ($this->level === 0) {
            $this->pdo()->beginTransaction();
        } else {
            $this->pdo()->exec('SAVEPOINT ' . $this->savepointName($this->level));
        }

        $this->level++;
    }

    public function commit(): void
    {
        if ($this->level === 0) {
            return;
        }

        $this->level--;

        if ($this->level === 0) {
            $this->pdo()->commit();

            return;
        }

        $this->pdo()->exec('RELEASE SAVEPOINT ' . $this->savepointName($this->level));
    }

    public function rollBack(): void
    {
        if ($this->level === 0) {
            return;
        }

        $this->level--;

        if ($this->level === 0) {
            $this->pdo()->rollBack();

            return;
        }

        $name = $this->savepointName($this->level);
        $this->pdo()->exec('ROLLBACK TO SAVEPOINT ' . $name);
        $this->pdo()->exec('RELEASE SAVEPOINT ' . $name);
    }

    public function transactionLevel(): int
    {
        return $this->level;
    }

    /* ------------------------------------------------------------------ */

    private function savepointName(int $level): string
    {
        return 'phpet_sp_' . $level;
    }

    private function connect(): PDO
    {
        $dsn = $this->dsn();

        if (str_starts_with($dsn, 'sqlite:') && $dsn !== 'sqlite::memory:') {
            $this->ensureSqliteDirectory(substr($dsn, 7));
        }

        $options = (array)($this->config['options'] ?? []);
        $options += [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // 用真正的预处理语句，参数不会经过字符串拼接
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        $pdo = new PDO(
            $dsn,
            $this->config['username'] ?? null,
            $this->config['password'] ?? null,
            $options
        );

        if (str_starts_with($dsn, 'sqlite:')) {
            // sqlite 默认不校验外键，显式打开，行为才和其他库一致
            $pdo->exec('PRAGMA foreign_keys = ON');
        }

        return $pdo;
    }

    private function ensureSqliteDirectory(string $path): void
    {
        $dir = dirname($path);

        if ($dir === '' || $dir === '.' || is_dir($dir)) {
            return;
        }

        if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create database directory: {$dir}");
        }
    }

    /**
     * @param array<int|string, mixed> $bindings
     */
    private function run(string $sql, array $bindings): PDOStatement
    {
        $statement = $this->pdo()->prepare($sql);

        foreach (array_values($bindings) as $index => $value) {
            $statement->bindValue($index + 1, $value, self::paramType($value));
        }

        $statement->execute();

        return $statement;
    }

    private static function paramType(mixed $value): int
    {
        return match (true) {
            is_bool($value)    => PDO::PARAM_BOOL,
            is_int($value)     => PDO::PARAM_INT,
            $value === null    => PDO::PARAM_NULL,
            is_resource($value) => PDO::PARAM_LOB,
            default            => PDO::PARAM_STR,
        };
    }
}
