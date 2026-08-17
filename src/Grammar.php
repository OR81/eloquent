<?php

namespace Or81\Eloquent;

use RuntimeException;

/**
 * Turns a Builder into SQL. Handles the differences between MySQL and SQLite.
 */
class Grammar
{
    protected string $driver;

    /**
     * Order matters: it defines both the SQL layout and the binding order.
     */
    protected array $selectComponents = [
        'aggregate', 'columns', 'from', 'joins', 'wheres',
        'groups', 'havings', 'orders', 'limit', 'offset', 'lock',
    ];

    public function __construct(string $driver = 'mysql')
    {
        $this->driver = $driver;
    }

    public function getDriver(): string
    {
        return $this->driver;
    }

    /* ------------------------------------------------------------------
     | Identifiers & parameters
     | ------------------------------------------------------------------ */

    /**
     * @param Expression|string $value
     */
    public function wrap($value): string
    {
        if ($value instanceof Expression) {
            return $value->getValue();
        }

        $value = (string) $value;

        if (preg_match('/\s+as\s+/i', $value)) {
            $segments = preg_split('/\s+as\s+/i', $value, 2);

            return $this->wrap($segments[0]) . ' as ' . $this->wrapValue($segments[1]);
        }

        return implode('.', array_map([$this, 'wrapValue'], explode('.', $value)));
    }

    /**
     * @param Expression|string $table
     */
    public function wrapTable($table): string
    {
        return $table instanceof Expression ? $table->getValue() : $this->wrap($table);
    }

    public function columnize(array $columns): string
    {
        return implode(', ', array_map([$this, 'wrap'], $columns));
    }

    public function parameterize(array $values): string
    {
        return implode(', ', array_map([$this, 'parameter'], $values));
    }

    public function parameter($value): string
    {
        return $value instanceof Expression ? $value->getValue() : '?';
    }

    protected function wrapValue(string $value): string
    {
        $value = trim($value);

        if ($value === '*' || $value === '') {
            return $value;
        }

        [$open, $close] = $this->delimiters();

        return $open . str_replace($close, $close . $close, $value) . $close;
    }

    protected function delimiters(): array
    {
        return $this->driver === 'mysql' ? ['`', '`'] : ['"', '"'];
    }

    /* ------------------------------------------------------------------
     | SELECT
     | ------------------------------------------------------------------ */

    public function compileSelect(Builder $query): string
    {
        $sql = trim(implode(' ', array_filter($this->compileComponents($query), 'strlen')));

        if (! empty($query->unions)) {
            $sql = $this->wrapUnion($sql) . ' ' . $this->compileUnions($query);
        }

        return $sql;
    }

    public function compileExists(Builder $query): string
    {
        return 'select exists(' . $this->compileSelect($query) . ') as ' . $this->wrapValue('exists');
    }

    protected function compileComponents(Builder $query): array
    {
        $sql = [];

        foreach ($this->selectComponents as $component) {
            $method = 'compile' . ucfirst($component);
            $sql[$component] = $this->$method($query);
        }

        return $sql;
    }

    protected function compileAggregate(Builder $query): string
    {
        if ($query->aggregate === null) {
            return '';
        }

        $column = $this->columnize($query->aggregate['columns']);

        if ($query->distinct && $column !== '*') {
            $column = 'distinct ' . $column;
        }

        return 'select ' . $query->aggregate['function'] . '(' . $column . ') as aggregate';
    }

    protected function compileColumns(Builder $query): string
    {
        if ($query->aggregate !== null) {
            return '';
        }

        $columns = empty($query->columns) ? ['*'] : $query->columns;

        return ($query->distinct ? 'select distinct ' : 'select ') . $this->columnize($columns);
    }

    protected function compileFrom(Builder $query): string
    {
        return $query->from === null ? '' : 'from ' . $this->wrapTable($query->from);
    }

    protected function compileJoins(Builder $query): string
    {
        $sql = [];

        foreach ($query->joins as $join) {
            $table = $this->wrapTable($join->table);

            if ($join->type === 'cross' && empty($join->wheres)) {
                $sql[] = "cross join {$table}";
                continue;
            }

            $conditions = $this->compileWheres($join, false);

            $sql[] = trim("{$join->type} join {$table} on {$conditions}");
        }

        return implode(' ', $sql);
    }

    protected function compileGroups(Builder $query): string
    {
        return empty($query->groups) ? '' : 'group by ' . $this->columnize($query->groups);
    }

    protected function compileOrders(Builder $query): string
    {
        return $this->orderList($query->orders);
    }

    protected function compileLimit(Builder $query): string
    {
        return $query->limit === null ? '' : 'limit ' . (int) $query->limit;
    }

    protected function compileOffset(Builder $query): string
    {
        if ($query->offset === null) {
            return '';
        }

        // Both MySQL and SQLite require a LIMIT before an OFFSET.
        $prefix = $query->limit === null ? 'limit ' . $this->noLimitValue() . ' ' : '';

        return $prefix . 'offset ' . (int) $query->offset;
    }

    protected function compileLock(Builder $query): string
    {
        if ($query->lock === null || $this->driver !== 'mysql') {
            return '';
        }

        if (is_string($query->lock)) {
            return $query->lock;
        }

        return $query->lock ? 'for update' : 'lock in share mode';
    }

    protected function orderList(array $orders): string
    {
        if (empty($orders)) {
            return '';
        }

        $compiled = array_map(function (array $order) {
            return isset($order['sql'])
                ? $order['sql']
                : $this->wrap($order['column']) . ' ' . $order['direction'];
        }, $orders);

        return 'order by ' . implode(', ', $compiled);
    }

    protected function noLimitValue(): string
    {
        return $this->driver === 'mysql' ? '18446744073709551615' : '-1';
    }

    public function compileRandom(): string
    {
        return $this->driver === 'mysql' ? 'RAND()' : 'RANDOM()';
    }

    /* ------------------------------------------------------------------
     | Unions
     | ------------------------------------------------------------------ */

    protected function compileUnions(Builder $query): string
    {
        $sql = '';

        foreach ($query->unions as $union) {
            $sql .= ' union ' . ($union['all'] ? 'all ' : '')
                . $this->wrapUnion($this->compileSelect($union['query']));
        }

        if (! empty($query->unionOrders)) {
            $sql .= ' ' . $this->orderList($query->unionOrders);
        }

        if ($query->unionLimit !== null) {
            $sql .= ' limit ' . (int) $query->unionLimit;
        }

        if ($query->unionOffset !== null) {
            $prefix = $query->unionLimit === null ? ' limit ' . $this->noLimitValue() : '';
            $sql .= $prefix . ' offset ' . (int) $query->unionOffset;
        }

        return ltrim($sql);
    }

    /**
     * SQLite does not allow a parenthesised SELECT as a compound operand.
     */
    protected function wrapUnion(string $sql): string
    {
        return $this->driver === 'sqlite' ? 'select * from (' . $sql . ')' : '(' . $sql . ')';
    }

    /* ------------------------------------------------------------------
     | Where clauses
     | ------------------------------------------------------------------ */

    public function compileWheres(Builder $query, bool $withKeyword = true): string
    {
        if (empty($query->wheres)) {
            return '';
        }

        $sql = [];

        foreach ($query->wheres as $where) {
            $method = 'where' . $where['type'];
            $sql[] = $where['boolean'] . ' ' . $this->$method($query, $where);
        }

        $conjoined = $this->removeLeadingBoolean(implode(' ', $sql));

        return $withKeyword ? 'where ' . $conjoined : $conjoined;
    }

    protected function whereBasic(Builder $query, array $where): string
    {
        return $this->wrap($where['column']) . ' ' . $where['operator'] . ' ' . $this->parameter($where['value']);
    }

    protected function whereRaw(Builder $query, array $where): string
    {
        return (string) $where['sql'];
    }

    protected function whereIn(Builder $query, array $where): string
    {
        if (empty($where['values'])) {
            return '0 = 1';
        }

        return $this->wrap($where['column']) . ' in (' . $this->parameterize($where['values']) . ')';
    }

    protected function whereNotIn(Builder $query, array $where): string
    {
        if (empty($where['values'])) {
            return '1 = 1';
        }

        return $this->wrap($where['column']) . ' not in (' . $this->parameterize($where['values']) . ')';
    }

    protected function whereInSub(Builder $query, array $where): string
    {
        return $this->wrap($where['column']) . ' in (' . $this->compileSelect($where['query']) . ')';
    }

    protected function whereNotInSub(Builder $query, array $where): string
    {
        return $this->wrap($where['column']) . ' not in (' . $this->compileSelect($where['query']) . ')';
    }

    protected function whereNull(Builder $query, array $where): string
    {
        return $this->wrap($where['column']) . ' is null';
    }

    protected function whereNotNull(Builder $query, array $where): string
    {
        return $this->wrap($where['column']) . ' is not null';
    }

    protected function whereBetween(Builder $query, array $where): string
    {
        $between = $where['not'] ? 'not between' : 'between';

        return $this->wrap($where['column']) . " {$between} "
            . $this->parameter($where['values'][0]) . ' and ' . $this->parameter($where['values'][1]);
    }

    protected function whereColumn(Builder $query, array $where): string
    {
        return $this->wrap($where['first']) . ' ' . $where['operator'] . ' ' . $this->wrap($where['second']);
    }

    protected function whereNested(Builder $query, array $where): string
    {
        return '(' . $this->compileWheres($where['query'], false) . ')';
    }

    protected function whereSub(Builder $query, array $where): string
    {
        return $this->wrap($where['column']) . ' ' . $where['operator']
            . ' (' . $this->compileSelect($where['query']) . ')';
    }

    protected function whereExists(Builder $query, array $where): string
    {
        return 'exists (' . $this->compileSelect($where['query']) . ')';
    }

    protected function whereNotExists(Builder $query, array $where): string
    {
        return 'not exists (' . $this->compileSelect($where['query']) . ')';
    }

    protected function whereDate(Builder $query, array $where): string
    {
        return $this->dateBasedWhere('%Y-%m-%d', 'date', $where);
    }

    protected function whereYear(Builder $query, array $where): string
    {
        return $this->dateBasedWhere('%Y', 'year', $where);
    }

    protected function whereMonth(Builder $query, array $where): string
    {
        return $this->dateBasedWhere('%m', 'month', $where);
    }

    protected function whereDay(Builder $query, array $where): string
    {
        return $this->dateBasedWhere('%d', 'day', $where);
    }

    protected function whereTime(Builder $query, array $where): string
    {
        return $this->dateBasedWhere('%H:%M:%S', 'time', $where);
    }

    protected function dateBasedWhere(string $format, string $function, array $where): string
    {
        $value = $this->parameter($where['value']);
        $column = $this->wrap($where['column']);

        if ($this->driver === 'mysql') {
            return "{$function}({$column}) {$where['operator']} {$value}";
        }

        return "strftime('{$format}', {$column}) {$where['operator']} cast({$value} as text)";
    }

    protected function removeLeadingBoolean(string $value): string
    {
        return preg_replace('/^(and |or |and not |or not )/i', '', $value, 1);
    }

    /* ------------------------------------------------------------------
     | Having clauses
     | ------------------------------------------------------------------ */

    protected function compileHavings(Builder $query): string
    {
        if (empty($query->havings)) {
            return '';
        }

        $sql = [];

        foreach ($query->havings as $having) {
            $sql[] = $having['boolean'] . ' ' . $this->compileHaving($having);
        }

        return 'having ' . $this->removeLeadingBoolean(implode(' ', $sql));
    }

    protected function compileHaving(array $having): string
    {
        switch ($having['type']) {
            case 'Raw':
                return (string) $having['sql'];
            case 'Null':
                return $this->wrap($having['column']) . ' is null';
            case 'NotNull':
                return $this->wrap($having['column']) . ' is not null';
            case 'Between':
                $between = $having['not'] ? 'not between' : 'between';

                return $this->wrap($having['column']) . " {$between} "
                    . $this->parameter($having['values'][0]) . ' and ' . $this->parameter($having['values'][1]);
            default:
                return $this->wrap($having['column']) . ' ' . $having['operator'] . ' ' . $this->parameter($having['value']);
        }
    }

    /* ------------------------------------------------------------------
     | INSERT
     | ------------------------------------------------------------------ */

    /**
     * @param array $values a list of rows, each an associative array
     */
    public function compileInsert(Builder $query, array $values, string $modifier = ''): string
    {
        $table = $this->wrapTable($query->from);

        if ($values === [] || reset($values) === []) {
            return $this->driver === 'mysql'
                ? "insert into {$table} () values ()"
                : "insert into {$table} default values";
        }

        $verb = 'insert';

        if ($modifier === 'ignore') {
            $verb = $this->driver === 'mysql' ? 'insert ignore' : 'insert or ignore';
        }

        $columns = $this->columnize(array_keys(reset($values)));

        $parameters = implode(', ', array_map(function (array $record) {
            return '(' . $this->parameterize($record) . ')';
        }, $values));

        return "{$verb} into {$table} ({$columns}) values {$parameters}";
    }

    /**
     * insert into t (cols) select ...
     */
    public function compileInsertUsing(Builder $query, array $columns, string $select): string
    {
        $table = $this->wrapTable($query->from);

        if ($columns === []) {
            return "insert into {$table} {$select}";
        }

        return "insert into {$table} (" . $this->columnize($columns) . ") {$select}";
    }

    public function compileUpsert(Builder $query, array $values, array $uniqueBy, array $update): string
    {
        $sql = $this->compileInsert($query, $values);

        $columns = [];

        if ($this->driver === 'mysql') {
            foreach ($update as $key => $value) {
                $columns[] = is_numeric($key)
                    ? $this->wrap($value) . ' = values(' . $this->wrap($value) . ')'
                    : $this->wrap($key) . ' = ' . $this->parameter($value);
            }

            return $sql . ' on duplicate key update ' . implode(', ', $columns);
        }

        foreach ($update as $key => $value) {
            $columns[] = is_numeric($key)
                ? $this->wrap($value) . ' = ' . $this->wrapValue('excluded') . '.' . $this->wrap($value)
                : $this->wrap($key) . ' = ' . $this->parameter($value);
        }

        return $sql . ' on conflict (' . $this->columnize($uniqueBy) . ') do update set ' . implode(', ', $columns);
    }

    /* ------------------------------------------------------------------
     | UPDATE / DELETE / TRUNCATE
     | ------------------------------------------------------------------ */

    public function compileUpdate(Builder $query, array $values): string
    {
        $table = $this->wrapTable($query->from);

        $columns = [];

        foreach ($values as $key => $value) {
            $columns[] = $this->wrap($key) . ' = ' . $this->parameter($value);
        }

        $columns = implode(', ', $columns);

        if (! empty($query->joins)) {
            $this->requireMySql('joins in an update statement');

            $sql = "update {$table} " . $this->compileJoins($query) . " set {$columns}";
        } else {
            $sql = "update {$table} set {$columns}";
        }

        return trim($sql . ' ' . $this->compileWheres($query) . $this->compileModifiers($query));
    }

    public function compileDelete(Builder $query): string
    {
        $table = $this->wrapTable($query->from);

        if (! empty($query->joins)) {
            $this->requireMySql('joins in a delete statement');

            $sql = 'delete ' . $this->tableAlias($query->from) . " from {$table} " . $this->compileJoins($query);
        } else {
            $sql = "delete from {$table}";
        }

        return trim($sql . ' ' . $this->compileWheres($query) . $this->compileModifiers($query));
    }

    /**
     * @return array<string, array> SQL statement => bindings
     */
    public function compileTruncate(Builder $query): array
    {
        $table = $this->wrapTable($query->from);

        if ($this->driver === 'sqlite') {
            return [
                'delete from ' . $table => [],
                'delete from sqlite_sequence where name = ?' => [$this->unaliasedTable($query->from)],
            ];
        }

        return ['truncate table ' . $table => []];
    }

    /**
     * ORDER BY / LIMIT on an UPDATE or DELETE. MySQL only, and not alongside a join.
     */
    protected function compileModifiers(Builder $query): string
    {
        if (empty($query->orders) && $query->limit === null) {
            return '';
        }

        $this->requireMySql('order by / limit on an update or delete statement');

        if (! empty($query->joins)) {
            throw new RuntimeException('MySQL does not support order by / limit on a multi-table update or delete.');
        }

        $sql = '';

        if (! empty($query->orders)) {
            $sql .= ' ' . $this->orderList($query->orders);
        }

        if ($query->limit !== null) {
            $sql .= ' limit ' . (int) $query->limit;
        }

        return $sql;
    }

    protected function requireMySql(string $feature): void
    {
        if ($this->driver !== 'mysql') {
            throw new RuntimeException("The [{$this->driver}] driver does not support {$feature}.");
        }
    }

    protected function tableAlias($from): string
    {
        if ($from instanceof Expression) {
            return '';
        }

        $segments = preg_split('/\s+as\s+/i', (string) $from);

        return $this->wrap(trim(end($segments)));
    }

    protected function unaliasedTable($from): string
    {
        $segments = preg_split('/\s+as\s+/i', (string) $from);

        return trim($segments[0]);
    }

    /* ------------------------------------------------------------------
     | Binding order
     | ------------------------------------------------------------------ */

    public function prepareBindingsForUpdate(Builder $query, array $values): array
    {
        $bindings = $query->getRawBindings();

        $valueBindings = [];

        foreach ($values as $value) {
            if (! $value instanceof Expression) {
                $valueBindings[] = $value;
            }
        }

        return array_values(array_merge(
            $bindings['join'],
            $valueBindings,
            $bindings['where'],
            $bindings['order']
        ));
    }

    public function prepareBindingsForDelete(Builder $query): array
    {
        $bindings = $query->getRawBindings();

        return array_values(array_merge($bindings['join'], $bindings['where'], $bindings['order']));
    }
}
