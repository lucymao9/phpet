<?php

namespace phpet\db;

/**
 * 查询构造器。
 *
 * 只负责拼标准 SQL 并交给 PDO 绑定参数，不解析模型、不做关系映射。
 * 标识符用反引号包裹，因此支持 MySQL 与 SQLite；PostgreSQL 的引号规则不同，
 * 目前不支持（连接本身可以连上，构造器生成的 SQL 不行）。
 *
 * 表名、字段名不要直接来自用户输入——绑定参数能防注入，但绑不了标识符。
 */
class QueryBuilder
{
    private const OPERATORS = [
        '=', '!=', '<>', '<', '>', '<=', '>=',
        'like', 'not like', 'is', 'is not',
        'in', 'not in', 'between', 'not between',
    ];

    /** 「没有 limit 但有 offset」时用的哨兵值 */
    private const MAX_LIMIT = '18446744073709551615';

    /** @var string[] */
    private array $columns = [];

    /** @var array<int, array{table: string, first: string, operator: string, second: string, type: string}> */
    private array $joins = [];

    /** @var array<int, array{boolean: string, sql: string, bindings: array<int, mixed>}> */
    private array $wheres = [];

    /** @var string[] */
    private array $groups = [];

    /** @var array<int, array{boolean: string, sql: string, bindings: array<int, mixed>}> */
    private array $havings = [];

    /** @var array<int, array{column: string, direction: string}> */
    private array $orders = [];

    private ?int $limit = null;
    private ?int $offset = null;

    public function __construct(
        private Connection $connection,
        private string $table,
        private ?string $alias = null
    ) {
    }

    public function connection(): Connection
    {
        return $this->connection;
    }

    /* ---------------------------- 片段 ---------------------------- */

    public function select(string|array ...$columns): static
    {
        foreach ($columns as $column) {
            foreach ((array)$column as $one) {
                $this->columns[] = (string)$one;
            }
        }

        return $this;
    }

    public function where(string|\Closure $column, mixed $operator = null, mixed $value = null, string $boolean = 'and'): static
    {
        if ($column instanceof \Closure) {
            return $this->whereGroup($column, $boolean);
        }

        return $this->addWhere($column, $operator, $value, $boolean, func_num_args());
    }

    public function orWhere(string|\Closure $column, mixed $operator = null, mixed $value = null): static
    {
        if ($column instanceof \Closure) {
            return $this->whereGroup($column, 'or');
        }

        return $this->addWhere($column, $operator, $value, 'or', func_num_args());
    }

    /**
     * @param array<int, mixed> $values
     */
    public function whereIn(string $column, array $values, string $boolean = 'and'): static
    {
        return $this->whereInList($column, $values, $boolean, false);
    }

    /**
     * @param array<int, mixed> $values
     */
    public function whereNotIn(string $column, array $values, string $boolean = 'and'): static
    {
        return $this->whereInList($column, $values, $boolean, true);
    }

    public function whereNull(string $column, string $boolean = 'and', bool $not = false): static
    {
        $this->wheres[] = [
            'boolean'  => $boolean,
            'sql'      => $this->wrap($column) . ($not ? ' is not null' : ' is null'),
            'bindings' => [],
        ];

        return $this;
    }

    public function whereNotNull(string $column, string $boolean = 'and'): static
    {
        return $this->whereNull($column, $boolean, true);
    }

    public function whereBetween(string $column, mixed $min, mixed $max, string $boolean = 'and', bool $not = false): static
    {
        // 必须加括号：between 自带一个 and，与外面的 or 混用时优先级会出错
        $this->wheres[] = [
            'boolean'  => $boolean,
            'sql'      => '(' . $this->wrap($column) . ($not ? ' not between ? and ?' : ' between ? and ?') . ')',
            'bindings' => [$min, $max],
        ];

        return $this;
    }

    public function whereLike(string $column, string $pattern, string $boolean = 'and'): static
    {
        return $this->whereRaw($this->wrap($column) . ' like ?', [$pattern], $boolean);
    }

    /**
     * @param array<int, mixed> $bindings
     */
    public function whereRaw(string $sql, array $bindings = [], string $boolean = 'and'): static
    {
        $this->wheres[] = [
            'boolean'  => $boolean,
            'sql'      => '(' . $sql . ')',
            'bindings' => array_values($bindings),
        ];

        return $this;
    }

    /**
     * 括号分组：$q->where(function ($q) { $q->where('a', 1)->orWhere('b', 2); })
     */
    public function whereGroup(\Closure $callback, string $boolean = 'and'): static
    {
        $nested = new self($this->connection, $this->table);
        $callback($nested);

        $compiled = $nested->compileWheres();

        if ($compiled['sql'] === '') {
            return $this;
        }

        $this->wheres[] = [
            'boolean'  => $boolean,
            'sql'      => '(' . $compiled['sql'] . ')',
            'bindings' => $compiled['bindings'],
        ];

        return $this;
    }

    public function join(
        string $table,
        string $first,
        string $operator,
        string $second,
        string $type = 'inner'
    ): static {
        $type = strtolower($type) . ' join';

        if (!in_array($type, ['inner join', 'left join', 'right join'], true)) {
            throw new \InvalidArgumentException("Unsupported join type '{$type}'");
        }

        $this->joins[] = [
            'table'    => $table,
            'first'    => $first,
            'operator' => $this->normalizeOperator($operator),
            'second'   => $second,
            'type'     => $type,
        ];

        return $this;
    }

    public function leftJoin(string $table, string $first, string $operator, string $second): static
    {
        return $this->join($table, $first, $operator, $second, 'left');
    }

    public function groupBy(string ...$columns): static
    {
        foreach ($columns as $column) {
            $this->groups[] = $column;
        }

        return $this;
    }

    public function having(string $column, mixed $operator, mixed $value, string $boolean = 'and'): static
    {
        $this->havings[] = [
            'boolean'  => $boolean,
            'sql'      => $this->wrap($column) . ' ' . $this->normalizeOperator((string)$operator) . ' ?',
            'bindings' => [$value],
        ];

        return $this;
    }

    public function orderBy(string $column, string $direction = 'asc'): static
    {
        $direction = strtolower($direction);

        if (!in_array($direction, ['asc', 'desc'], true)) {
            throw new \InvalidArgumentException("Order direction must be asc or desc, got '{$direction}'");
        }

        $this->orders[] = ['column' => $column, 'direction' => $direction];

        return $this;
    }

    public function limit(?int $limit): static
    {
        $this->limit = $limit;

        return $this;
    }

    public function offset(?int $offset): static
    {
        $this->offset = $offset;

        return $this;
    }

    /* --------------------------- 读取 --------------------------- */

    /**
     * @return array<int, array<string, mixed>>
     */
    public function get(): array
    {
        return $this->connection->select($this->toSql(), $this->bindings());
    }

    /**
     * @return array<string, mixed>|null
     */
    public function first(): ?array
    {
        $sql = $this->toSql();

        if ($this->limit === null) {
            $sql .= ' limit 1';
        }

        return $this->connection->selectOne($sql, $this->bindings());
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int|string $id, string $column = 'id'): ?array
    {
        return (clone $this)->where($column, $id)->first();
    }

    public function value(string $column): mixed
    {
        $query = clone $this;
        $query->columns = [$column];

        $row = $query->first();

        return $row === null ? null : reset($row);
    }

    /**
     * @return array<int, mixed>
     */
    public function pluck(string $column, ?string $key = null): array
    {
        $query = clone $this;
        $query->columns = $key === null ? [$column] : [$key, $column];

        $rows = $query->get();

        if ($key === null) {
            return array_map(static fn(array $row): mixed => reset($row), $rows);
        }

        $result = [];
        foreach ($rows as $row) {
            $result[$row[$key]] = $row[$column];
        }

        return $result;
    }

    public function count(string $column = '*'): int
    {
        $query = clone $this;
        $query->orders = [];
        $query->limit = null;
        $query->offset = null;

        if ($query->groups !== []) {
            // 有 group by 时，count 要数分组个数，套一层子查询
            $sql = 'select count(*) as aggregate from (' . $query->toSql() . ') as phpet_count';
        } else {
            $query->columns = ['count(' . $column . ') as aggregate'];
            $sql = $query->toSql();
        }

        return (int)$this->connection->scalar($sql, $query->bindings());
    }

    public function exists(): bool
    {
        return $this->count() > 0;
    }

    /**
     * 分页遍历，回调返回 false 时提前结束。
     *
     * 没有 order by 时数据库不保证顺序，翻页可能漏行或重复——需要确定性
     * 结果时请自己加 orderBy。
     *
     * @param callable(array<int, array<string, mixed>>): (bool|void) $callback
     */
    public function chunk(int $size, callable $callback): void
    {
        if ($size < 1) {
            throw new \InvalidArgumentException('Chunk size must be at least 1');
        }

        $page = 0;

        while (true) {
            $query = clone $this;
            $query->limit($size)->offset($page * $size);

            $rows = $query->get();

            if ($rows === []) {
                return;
            }

            if ($callback($rows) === false) {
                return;
            }

            if (count($rows) < $size) {
                return;
            }

            $page++;
        }
    }

    public function toSql(): string
    {
        $columns = $this->columns === []
            ? '*'
            : implode(', ', array_map(fn(string $c): string => $this->wrap($c), $this->columns));

        $sql = 'select ' . $columns . ' from ' . $this->wrapTable($this->table);

        if ($this->alias !== null) {
            $sql .= ' as ' . $this->wrap($this->alias);
        }

        foreach ($this->joins as $join) {
            $sql .= ' ' . $join['type'] . ' ' . $this->wrapTable($join['table'])
                . ' on ' . $this->wrap($join['first'])
                . ' ' . $join['operator'] . ' ' . $this->wrap($join['second']);
        }

        $wheres = $this->compileWheres();
        if ($wheres['sql'] !== '') {
            $sql .= ' where ' . $wheres['sql'];
        }

        if ($this->groups !== []) {
            $sql .= ' group by ' . implode(', ', array_map(fn(string $c): string => $this->wrap($c), $this->groups));
        }

        $havings = $this->compileClauses($this->havings);
        if ($havings['sql'] !== '') {
            $sql .= ' having ' . $havings['sql'];
        }

        if ($this->orders !== []) {
            $sql .= ' order by ' . implode(', ', array_map(
                fn(array $o): string => $this->wrap($o['column']) . ' ' . $o['direction'],
                $this->orders
            ));
        }

        if ($this->limit !== null) {
            $sql .= ' limit ' . $this->limit;
        } elseif ($this->offset !== null) {
            // 只给 offset 不给 limit 时，MySQL 与 SQLite 都需要一个 limit 才能带 offset
            $sql .= ' limit ' . self::MAX_LIMIT;
        }

        if ($this->offset !== null) {
            $sql .= ' offset ' . $this->offset;
        }

        return $sql;
    }

    /**
     * @return array<int, mixed>
     */
    public function bindings(): array
    {
        return array_merge(
            $this->compileWheres()['bindings'],
            $this->compileClauses($this->havings)['bindings']
        );
    }

    /* --------------------------- 写入 --------------------------- */

    /**
     * @param array<string, mixed> $row
     */
    public function insert(array $row): int
    {
        if ($row === []) {
            throw new \InvalidArgumentException('insert() needs at least one column');
        }

        $columns = array_keys($row);
        $sql = 'insert into ' . $this->wrap($this->table)
            . ' (' . implode(', ', array_map(fn(string $c): string => $this->wrap($c), $columns)) . ')'
            . ' values (' . implode(', ', array_fill(0, count($columns), '?')) . ')';

        return $this->connection->execute($sql, array_values($row));
    }

    /**
     * @param array<string, mixed> $row
     */
    public function insertGetId(array $row): int|string
    {
        $this->insert($row);

        return $this->connection->lastInsertId();
    }

    /**
     * @param array<string, mixed> $values
     */
    public function update(array $values): int
    {
        if ($values === []) {
            throw new \InvalidArgumentException('update() needs at least one column');
        }

        $sets = [];
        foreach (array_keys($values) as $column) {
            $sets[] = $this->wrap($column) . ' = ?';
        }

        $wheres = $this->compileWheres();

        $sql = 'update ' . $this->wrap($this->table) . ' set ' . implode(', ', $sets);

        if ($wheres['sql'] !== '') {
            $sql .= ' where ' . $wheres['sql'];
        }

        return $this->connection->execute(
            $sql,
            array_merge(array_values($values), $wheres['bindings'])
        );
    }

    public function delete(): int
    {
        $wheres = $this->compileWheres();

        $sql = 'delete from ' . $this->wrap($this->table);

        if ($wheres['sql'] !== '') {
            $sql .= ' where ' . $wheres['sql'];
        }

        return $this->connection->execute($sql, $wheres['bindings']);
    }

    public function increment(string $column, int $by = 1): int
    {
        $wheres = $this->compileWheres();
        $wrapped = $this->wrap($column);

        $sql = 'update ' . $this->wrap($this->table) . ' set ' . $wrapped . ' = ' . $wrapped . ' + ?';

        if ($wheres['sql'] !== '') {
            $sql .= ' where ' . $wheres['sql'];
        }

        return $this->connection->execute($sql, array_merge([$by], $wheres['bindings']));
    }

    public function decrement(string $column, int $by = 1): int
    {
        return $this->increment($column, -$by);
    }

    /* --------------------------- 内部 --------------------------- */

    /**
     * @param array<int, mixed> $values
     */
    private function whereInList(string $column, array $values, string $boolean, bool $not): static
    {
        // 空列表不能拼成 in ()，语法就是错的；按恒真/恒假处理
        if ($values === []) {
            $this->wheres[] = [
                'boolean'  => $boolean,
                'sql'      => $not ? '1 = 1' : '1 = 0',
                'bindings' => [],
            ];

            return $this;
        }

        $placeholders = implode(', ', array_fill(0, count($values), '?'));

        $this->wheres[] = [
            'boolean'  => $boolean,
            'sql'      => $this->wrap($column) . ($not ? ' not in (' : ' in (') . $placeholders . ')',
            'bindings' => array_values($values),
        ];

        return $this;
    }

    /**
     * @return array{sql: string, bindings: array<int, mixed>}
     */
    private function compileWheres(): array
    {
        return $this->compileClauses($this->wheres);
    }

    /**
     * @param array<int, array{boolean: string, sql: string, bindings: array<int, mixed>}> $clauses
     * @return array{sql: string, bindings: array<int, mixed>}
     */
    private function compileClauses(array $clauses): array
    {
        $sql = '';
        $bindings = [];

        foreach (array_values($clauses) as $index => $clause) {
            $sql .= ($index === 0 ? '' : ' ' . $clause['boolean'] . ' ') . $clause['sql'];

            foreach ($clause['bindings'] as $binding) {
                $bindings[] = $binding;
            }
        }

        return ['sql' => $sql, 'bindings' => $bindings];
    }

    private function normalizeOperator(string $operator): string
    {
        $normalized = strtolower(trim($operator));

        if (!in_array($normalized, self::OPERATORS, true)) {
            throw new \InvalidArgumentException("Unsupported SQL operator '{$operator}'");
        }

        return $normalized;
    }

    /**
     * 包反引号。含空格或括号的按原样表达式处理（例如 count(*) / a as b）。
     */
    /**
     * 表名可能带别名（`orders as o`），别名要单独包起来。
     */
    private function wrapTable(string $table): string
    {
        if (preg_match('/^([\w.]+)\s+as\s+([\w]+)$/i', trim($table), $m) === 1) {
            return $this->wrap($m[1]) . ' as ' . $this->wrap($m[2]);
        }

        return $this->wrap($table);
    }

    private function wrap(string $column): string
    {
        $column = trim($column);

        if ($column === '' || $column === '*') {
            return $column === '' ? '' : '*';
        }

        if (strpbrk($column, '(` ') !== false) {
            return $column;
        }

        $parts = [];

        foreach (explode('.', $column) as $part) {
            $parts[] = $part === '*' ? '*' : '`' . str_replace('`', '', $part) . '`';
        }

        return implode('.', $parts);
    }

    /**
     * @param int $argc 调用方实际传了几个参数，用来区分 where('id', 5) 与 where('id', '>', 5)
     */
    private function addWhere(string $column, mixed $operator, mixed $value, string $boolean, int $argc): static
    {
        if ($argc === 1) {
            throw new \InvalidArgumentException(
                "where('{$column}') needs a value; use whereNull('{$column}') for null checks"
            );
        }

        if ($argc === 2) {
            $value = $operator;
            $operator = '=';
        }

        $operator = $this->normalizeOperator((string)$operator);

        if ($value === null) {
            if ($operator === '=') {
                return $this->whereNull($column, $boolean);
            }

            if ($operator === '!=' || $operator === '<>') {
                return $this->whereNull($column, $boolean, true);
            }
        }

        $this->wheres[] = [
            'boolean'  => $boolean,
            'sql'      => $this->wrap($column) . ' ' . $operator . ' ?',
            'bindings' => [$value],
        ];

        return $this;
    }
}
