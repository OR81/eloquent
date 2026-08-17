<?php

namespace Or81\Eloquent;

use Closure;
use Generator;
use InvalidArgumentException;
use RuntimeException;

/**
 * A fluent SQL query builder. Every value is sent as a bound parameter,
 * only identifiers are interpolated (and those are quoted by the grammar).
 */
class Builder
{
    protected Connection $connection;
    protected Grammar $grammar;

    /* The compiled query state. Public so the grammar can read it. */

    public array $columns = [];
    public bool $distinct = false;

    /** @var Expression|string|null */
    public $from = null;

    /** @var JoinClause[] */
    public array $joins = [];
    public array $wheres = [];
    public array $groups = [];
    public array $havings = [];
    public array $orders = [];
    public ?int $limit = null;
    public ?int $offset = null;
    public array $unions = [];
    public array $unionOrders = [];
    public ?int $unionLimit = null;
    public ?int $unionOffset = null;

    /** @var bool|string|null */
    public $lock = null;
    public ?array $aggregate = null;

    /**
     * Bindings are grouped so they stay in the same order as the compiled SQL.
     */
    public array $bindings = [
        'select' => [],
        'from' => [],
        'join' => [],
        'where' => [],
        'groupBy' => [],
        'having' => [],
        'order' => [],
        'union' => [],
    ];

    public array $operators = [
        '=', '<', '>', '<=', '>=', '<>', '!=', '<=>',
        'like', 'like binary', 'not like', 'ilike', 'not ilike',
        '&', '|', '^', '<<', '>>',
        'rlike', 'not rlike', 'regexp', 'not regexp',
    ];

    /**
     * How each date column stores its value: column => [mode, format].
     * See the Jalali class for the modes.
     */
    protected array $dateStorage = [];

    /** @var array{0: string, 1: string|null} */
    protected array $defaultDateStorage = [Jalali::GREGORIAN, 'Y-m-d'];

    /** Columns to convert to Jalali on the way out: column => output format. */
    protected array $jalaliCasts = [];

    /** The primary key column, used by find(), create() and delete($id). */
    protected string $keyName = 'id';

    /** Storage detected by probing a table, keyed by connection|table|column. */
    protected static array $detectedStorage = [];

    public function __construct(Connection $connection, ?Grammar $grammar = null)
    {
        $this->connection = $connection;
        $this->grammar = $grammar ?: $connection->getGrammar();

        $mode = $connection->getConfig('date_storage');

        if ($mode !== null) {
            $this->defaultDateStorage = [$mode, $connection->getConfig('date_format') ?: Jalali::defaultFormatFor($mode)];
        }
    }

    public function getConnection(): Connection
    {
        return $this->connection;
    }

    public function getGrammar(): Grammar
    {
        return $this->grammar;
    }

    public function newQuery(): Builder
    {
        return new Builder($this->connection, $this->grammar);
    }

    /* ------------------------------------------------------------------
     | SELECT
     | ------------------------------------------------------------------ */

    /**
     * select('id', 'name') or select(['id', 'name']) or select(['total' => $subQuery])
     *
     * @param array|string|Expression $columns
     */
    public function select($columns = ['*']): self
    {
        $this->columns = [];
        $this->bindings['select'] = [];

        $columns = is_array($columns) ? $columns : func_get_args();

        foreach ($columns as $as => $column) {
            if (is_string($as) && $this->isQueryable($column)) {
                $this->selectSub($column, $as);
            } else {
                $this->columns[] = $column;

                if ($column instanceof Expression && $column->hasBindings()) {
                    $this->addBinding($column->getBindings(), 'select');
                }
            }
        }

        return $this;
    }

    /**
     * @param array|string|Expression $columns
     */
    public function addSelect($columns): self
    {
        $columns = is_array($columns) ? $columns : func_get_args();

        foreach ($columns as $as => $column) {
            if (is_string($as) && $this->isQueryable($column)) {
                $this->selectSub($column, $as);
            } else {
                $this->columns[] = $column;

                if ($column instanceof Expression && $column->hasBindings()) {
                    $this->addBinding($column->getBindings(), 'select');
                }
            }
        }

        return $this;
    }

    public function selectRaw(string $expression, array $bindings = []): self
    {
        $this->columns[] = new Expression($expression);

        if ($bindings) {
            $this->addBinding($bindings, 'select');
        }

        return $this;
    }

    /**
     * @param Closure|Builder|string $query
     */
    public function selectSub($query, string $as): self
    {
        [$sql, $bindings] = $this->parseSub($query);

        return $this->selectRaw('(' . $sql . ') as ' . $this->grammar->wrap($as), $bindings);
    }

    public function distinct(bool $distinct = true): self
    {
        $this->distinct = $distinct;

        return $this;
    }

    /**
     * @param Expression|string $table
     */
    public function from($table, ?string $as = null): self
    {
        if ($as !== null && ! $table instanceof Expression) {
            $table = $table . ' as ' . $as;
        }

        $this->from = $table;

        return $this;
    }

    /**
     * @param Closure|Builder|string $query
     */
    public function fromSub($query, string $as): self
    {
        [$sql, $bindings] = $this->parseSub($query);

        $this->addBinding($bindings, 'from');

        $this->from = new Expression('(' . $sql . ') as ' . $this->grammar->wrap($as));

        return $this;
    }

    public function table($table, ?string $as = null): self
    {
        return $this->from($table, $as);
    }

    /* ------------------------------------------------------------------
     | Joins
     | ------------------------------------------------------------------ */

    /**
     * join('posts', 'users.id', '=', 'posts.user_id')
     * join('posts', 'users.id', 'posts.user_id')            // '=' is implied
     * join('posts', fn (JoinClause $join) => $join->on(...)->where(...))
     *
     * @param Expression|string $table
     * @param Closure|string $first
     */
    public function join($table, $first, ?string $operator = null, $second = null, string $type = 'inner'): self
    {
        $join = new JoinClause($this, $type, $table);

        if ($first instanceof Closure) {
            $first($join);
        } else {
            $join->on($first, $operator, $second);
        }

        $this->joins[] = $join;
        $this->addBinding($join->getBindings(), 'join');

        return $this;
    }

    public function leftJoin($table, $first, ?string $operator = null, $second = null): self
    {
        return $this->join($table, $first, $operator, $second, 'left');
    }

    public function rightJoin($table, $first, ?string $operator = null, $second = null): self
    {
        return $this->join($table, $first, $operator, $second, 'right');
    }

    /**
     * @param Expression|string $table
     */
    public function crossJoin($table, $first = null, ?string $operator = null, $second = null): self
    {
        if ($first !== null) {
            return $this->join($table, $first, $operator, $second, 'cross');
        }

        $this->joins[] = new JoinClause($this, 'cross', $table);

        return $this;
    }

    /**
     * @param Closure|Builder|string $query
     */
    public function joinSub($query, string $as, $first, ?string $operator = null, $second = null, string $type = 'inner'): self
    {
        [$sql, $bindings] = $this->parseSub($query);

        $this->addBinding($bindings, 'join');

        return $this->join(
            new Expression('(' . $sql . ') as ' . $this->grammar->wrap($as)),
            $first,
            $operator,
            $second,
            $type
        );
    }

    public function leftJoinSub($query, string $as, $first, ?string $operator = null, $second = null): self
    {
        return $this->joinSub($query, $as, $first, $operator, $second, 'left');
    }

    /* ------------------------------------------------------------------
     | Where clauses
     | ------------------------------------------------------------------ */

    /**
     * where('votes', '>', 100)
     * where('name', 'John')                     // '=' is implied
     * where(['name' => 'John', 'active' => 1])
     * where([['votes', '>', 100], ['name', 'John']])
     * where(fn (Builder $q) => $q->where(...)->orWhere(...))
     *
     * @param Closure|array|Expression|string $column
     */
    public function where($column, $operator = null, $value = null, string $boolean = 'and'): self
    {
        if (is_array($column)) {
            return $this->addArrayOfWheres($column, $boolean);
        }

        [$value, $operator] = $this->prepareValueAndOperator($value, $operator, func_num_args() === 2);

        if ($column instanceof Closure && $operator === null) {
            return $this->whereNested($column, $boolean);
        }

        if (is_string($operator) && ! in_array(strtolower($operator), $this->operators, true)) {
            throw new InvalidArgumentException(
                "Unsupported operator [{$operator}]. Use whereRaw() for anything the builder does not know."
            );
        }

        if ($this->isQueryable($value)) {
            return $this->whereSub($column, $operator, $value, $boolean);
        }

        if ($value === null) {
            return $this->whereNull($column, $boolean, $operator !== '=');
        }

        $type = 'Basic';

        $this->wheres[] = compact('type', 'column', 'operator', 'value', 'boolean');

        $this->addValueBinding($value, 'where');

        return $this;
    }

    public function orWhere($column, $operator = null, $value = null): self
    {
        [$value, $operator] = $this->prepareValueAndOperator($value, $operator, func_num_args() === 2);

        return $this->where($column, $operator, $value, 'or');
    }

    public function whereNot($column, $operator = null, $value = null, string $boolean = 'and'): self
    {
        [$value, $operator] = $this->prepareValueAndOperator($value, $operator, func_num_args() === 2);

        return $this->whereNested(function (Builder $query) use ($column, $operator, $value) {
            $query->where($column, $operator, $value);
        }, $boolean . ' not');
    }

    public function orWhereNot($column, $operator = null, $value = null): self
    {
        [$value, $operator] = $this->prepareValueAndOperator($value, $operator, func_num_args() === 2);

        return $this->whereNot($column, $operator, $value, 'or');
    }

    public function whereRaw(string $sql, array $bindings = [], string $boolean = 'and'): self
    {
        $type = 'Raw';

        $this->wheres[] = compact('type', 'sql', 'boolean');

        $this->addBinding($bindings, 'where');

        return $this;
    }

    public function orWhereRaw(string $sql, array $bindings = []): self
    {
        return $this->whereRaw($sql, $bindings, 'or');
    }

    /**
     * @param array|Closure|Builder $values
     */
    public function whereIn(string $column, $values, string $boolean = 'and', bool $not = false): self
    {
        if ($this->isQueryable($values)) {
            $query = $this->createSub($values);
            $type = $not ? 'NotInSub' : 'InSub';

            $this->wheres[] = compact('type', 'column', 'query', 'boolean');
            $this->addBinding($query->getBindings(), 'where');

            return $this;
        }

        $type = $not ? 'NotIn' : 'In';
        $values = array_values($values);

        $this->wheres[] = compact('type', 'column', 'values', 'boolean');

        foreach ($values as $value) {
            $this->addValueBinding($value, 'where');
        }

        return $this;
    }

    public function orWhereIn(string $column, $values): self
    {
        return $this->whereIn($column, $values, 'or');
    }

    public function whereNotIn(string $column, $values, string $boolean = 'and'): self
    {
        return $this->whereIn($column, $values, $boolean, true);
    }

    public function orWhereNotIn(string $column, $values): self
    {
        return $this->whereIn($column, $values, 'or', true);
    }

    /**
     * @param array|string $columns
     */
    public function whereNull($columns, string $boolean = 'and', bool $not = false): self
    {
        $type = $not ? 'NotNull' : 'Null';

        foreach ((array) $columns as $column) {
            $this->wheres[] = compact('type', 'column', 'boolean');
        }

        return $this;
    }

    public function orWhereNull($columns): self
    {
        return $this->whereNull($columns, 'or');
    }

    public function whereNotNull($columns, string $boolean = 'and'): self
    {
        return $this->whereNull($columns, $boolean, true);
    }

    public function orWhereNotNull($columns): self
    {
        return $this->whereNull($columns, 'or', true);
    }

    public function whereBetween(string $column, array $values, string $boolean = 'and', bool $not = false): self
    {
        if (count($values) < 2) {
            throw new InvalidArgumentException('whereBetween() expects exactly two values.');
        }

        $type = 'Between';
        $values = array_slice(array_values($values), 0, 2);

        $this->wheres[] = compact('type', 'column', 'values', 'boolean', 'not');

        foreach ($values as $value) {
            $this->addValueBinding($value, 'where');
        }

        return $this;
    }

    public function orWhereBetween(string $column, array $values): self
    {
        return $this->whereBetween($column, $values, 'or');
    }

    public function whereNotBetween(string $column, array $values, string $boolean = 'and'): self
    {
        return $this->whereBetween($column, $values, $boolean, true);
    }

    public function orWhereNotBetween(string $column, array $values): self
    {
        return $this->whereBetween($column, $values, 'or', true);
    }

    public function whereColumn(string $first, ?string $operator = null, ?string $second = null, string $boolean = 'and'): self
    {
        if ($second === null) {
            [$second, $operator] = [$operator, '='];
        }

        $type = 'Column';

        $this->wheres[] = compact('type', 'first', 'operator', 'second', 'boolean');

        return $this;
    }

    public function orWhereColumn(string $first, ?string $operator = null, ?string $second = null): self
    {
        return $this->whereColumn($first, $operator, $second, 'or');
    }

    public function whereLike(string $column, string $value, string $boolean = 'and', bool $not = false): self
    {
        return $this->where($column, $not ? 'not like' : 'like', $value, $boolean);
    }

    public function orWhereLike(string $column, string $value): self
    {
        return $this->whereLike($column, $value, 'or');
    }

    public function whereNotLike(string $column, string $value, string $boolean = 'and'): self
    {
        return $this->whereLike($column, $value, $boolean, true);
    }

    /**
     * The same condition across several columns, any of which may match. The
     * whole thing is one group, so it cannot leak into the clauses around it.
     *
     *     ->whereAny(['name', 'email', 'phone'], 'like', "%{$term}%")
     */
    public function whereAny(array $columns, $operator = null, $value = null, string $boolean = 'and'): self
    {
        [$value, $operator] = $this->prepareValueAndOperator($value, $operator, func_num_args() === 2);

        return $this->whereNested(function (Builder $query) use ($columns, $operator, $value) {
            foreach ($columns as $column) {
                $query->where($column, $operator, $value, 'or');
            }
        }, $boolean);
    }

    public function orWhereAny(array $columns, $operator = null, $value = null): self
    {
        [$value, $operator] = $this->prepareValueAndOperator($value, $operator, func_num_args() === 2);

        return $this->whereAny($columns, $operator, $value, 'or');
    }

    /**
     * The same condition across several columns, all of which must match.
     */
    public function whereAll(array $columns, $operator = null, $value = null, string $boolean = 'and'): self
    {
        [$value, $operator] = $this->prepareValueAndOperator($value, $operator, func_num_args() === 2);

        return $this->whereNested(function (Builder $query) use ($columns, $operator, $value) {
            foreach ($columns as $column) {
                $query->where($column, $operator, $value, 'and');
            }
        }, $boolean);
    }

    public function orWhereAll(array $columns, $operator = null, $value = null): self
    {
        [$value, $operator] = $this->prepareValueAndOperator($value, $operator, func_num_args() === 2);

        return $this->whereAll($columns, $operator, $value, 'or');
    }

    /**
     * The same condition across several columns, none of which may match.
     */
    public function whereNone(array $columns, $operator = null, $value = null, string $boolean = 'and'): self
    {
        [$value, $operator] = $this->prepareValueAndOperator($value, $operator, func_num_args() === 2);

        return $this->whereAny($columns, $operator, $value, $boolean . ' not');
    }

    public function whereNested(Closure $callback, string $boolean = 'and'): self
    {
        $query = $this->forNestedWhere();

        $callback($query);

        if (! empty($query->wheres)) {
            $type = 'Nested';

            $this->wheres[] = compact('type', 'query', 'boolean');
            $this->addBinding($query->getRawBindings()['where'], 'where');
        }

        return $this;
    }

    /**
     * @param Closure|Builder $callback
     */
    public function whereExists($callback, string $boolean = 'and', bool $not = false): self
    {
        $query = $this->createSub($callback);
        $type = $not ? 'NotExists' : 'Exists';

        $this->wheres[] = compact('type', 'query', 'boolean');
        $this->addBinding($query->getBindings(), 'where');

        return $this;
    }

    public function orWhereExists($callback): self
    {
        return $this->whereExists($callback, 'or');
    }

    public function whereNotExists($callback, string $boolean = 'and'): self
    {
        return $this->whereExists($callback, $boolean, true);
    }

    public function orWhereNotExists($callback): self
    {
        return $this->whereExists($callback, 'or', true);
    }

    public function whereDate(string $column, $operator, $value = null, string $boolean = 'and'): self
    {
        [$value, $operator] = $this->prepareValueAndOperator($value, $operator, func_num_args() === 2);

        return $this->addDateBasedWhere('Date', $column, $operator, $value, $boolean);
    }

    public function whereYear(string $column, $operator, $value = null, string $boolean = 'and'): self
    {
        [$value, $operator] = $this->prepareValueAndOperator($value, $operator, func_num_args() === 2);

        return $this->addDateBasedWhere('Year', $column, $operator, $value, $boolean);
    }

    public function whereMonth(string $column, $operator, $value = null, string $boolean = 'and'): self
    {
        [$value, $operator] = $this->prepareValueAndOperator($value, $operator, func_num_args() === 2);

        return $this->addDateBasedWhere('Month', $column, $operator, $this->padDatePart($value), $boolean);
    }

    public function whereDay(string $column, $operator, $value = null, string $boolean = 'and'): self
    {
        [$value, $operator] = $this->prepareValueAndOperator($value, $operator, func_num_args() === 2);

        return $this->addDateBasedWhere('Day', $column, $operator, $this->padDatePart($value), $boolean);
    }

    public function whereTime(string $column, $operator, $value = null, string $boolean = 'and'): self
    {
        [$value, $operator] = $this->prepareValueAndOperator($value, $operator, func_num_args() === 2);

        return $this->addDateBasedWhere('Time', $column, $operator, $value, $boolean);
    }

    protected function addDateBasedWhere(string $type, string $column, string $operator, $value, string $boolean): self
    {
        if ($value instanceof \DateTimeInterface) {
            $value = $value->format($type === 'Time' ? 'H:i:s' : 'Y-m-d');
        }

        $this->wheres[] = compact('type', 'column', 'operator', 'value', 'boolean');

        $this->addValueBinding($value instanceof Expression ? $value : (string) $value, 'where');

        return $this;
    }

    protected function padDatePart($value)
    {
        return is_numeric($value) ? sprintf('%02d', $value) : $value;
    }

    /* ------------------------------------------------------------------
     | Jalali (Shamsi) dates
     |
     | Every one of these compiles down to a half-open range over the plain
     | column, so an index on it is still used. Nothing is wrapped in a
     | function and no Jalali support is needed from the database.
     | ------------------------------------------------------------------ */

    /**
     * Declare how a column stores its dates, which decides what the builder
     * compares against.
     *
     *     ->dateStorage('created_at', Jalali::GREGORIAN)          // 2024-08-16 ...
     *     ->dateStorage('issued_at', Jalali::JALALI)              // 1403/05/26
     *     ->dateStorage('issued_at', Jalali::JALALI, 'Y-m-d')     // 1403-05-26
     *     ->dateStorage('logged_at', Jalali::UNIX)                // 1723800000
     */
    public function dateStorage(string $column, string $mode, ?string $format = null): self
    {
        $this->dateStorage[$column] = [$mode, $format ?: Jalali::defaultFormatFor($mode)];

        return $this;
    }

    /**
     * Work out the storage of the given columns by reading one stored value.
     * Costs one small query per column, cached for the rest of the process.
     */
    public function detectDateStorage(string ...$columns): self
    {
        if (! is_string($this->from) || $this->from === '') {
            throw new RuntimeException('detectDateStorage() needs a plain table name to sample.');
        }

        foreach ($columns as $column) {
            $key = spl_object_id($this->connection) . '|' . $this->from . '|' . $column;

            if (! array_key_exists($key, self::$detectedStorage)) {
                $sample = $this->newQuery()->from($this->from)->whereNotNull($column)->value($column);

                self::$detectedStorage[$key] = Jalali::detectStorage($sample);
            }

            if (self::$detectedStorage[$key] !== null) {
                [$mode, $format] = self::$detectedStorage[$key];

                $this->dateStorage($column, $mode, $format);
            }
        }

        return $this;
    }

    /**
     * Convert a column to a Jalali string on the way out of get()/first()/cursor().
     */
    public function castJalali(string $column, string $format = 'Y/m/d H:i:s'): self
    {
        $this->jalaliCasts[$column] = $format;

        return $this;
    }

    /**
     * Compare against a Jalali date at day granularity.
     *
     *     ->whereJalali('created_at', '1403/05/26')          // that whole day
     *     ->whereJalali('created_at', '>=', '1403/05/26')    // from that day on
     *
     * @param Jalali|string|array $value
     */
    public function whereJalali(string $column, $operator, $value = null, string $boolean = 'and'): self
    {
        [$value, $operator] = $this->prepareValueAndOperator($value, $operator, func_num_args() === 2);

        $date = Jalali::parse($value)->startOfDay();

        switch ($operator) {
            case '=':
                return $this->whereJalaliDate($column, $date, $boolean);
            case '!=':
            case '<>':
                return $this->whereJalaliDate($column, $date, $boolean, true);
            case '>':
                return $this->where($column, '>=', $this->jalaliBoundary($column, $date->addDays(1)), $boolean);
            case '>=':
                return $this->where($column, '>=', $this->jalaliBoundary($column, $date), $boolean);
            case '<':
                return $this->where($column, '<', $this->jalaliBoundary($column, $date), $boolean);
            case '<=':
                return $this->where($column, '<', $this->jalaliBoundary($column, $date->addDays(1)), $boolean);
            default:
                throw new InvalidArgumentException("Operator [{$operator}] is not supported for Jalali comparisons.");
        }
    }

    public function orWhereJalali(string $column, $operator, $value = null): self
    {
        [$value, $operator] = $this->prepareValueAndOperator($value, $operator, func_num_args() === 2);

        return $this->whereJalali($column, $operator, $value, 'or');
    }

    /**
     * A single Jalali day, from midnight to midnight.
     *
     * @param Jalali|string|array $date
     */
    public function whereJalaliDate(string $column, $date, string $boolean = 'and', bool $not = false): self
    {
        $date = Jalali::parse($date)->startOfDay();

        return $this->addJalaliRange($column, $date, $date->addDays(1), $boolean, $not);
    }

    public function orWhereJalaliDate(string $column, $date): self
    {
        return $this->whereJalaliDate($column, $date, 'or');
    }

    /**
     * Both ends are inclusive: the whole of the last day is covered.
     *
     * @param array{0: Jalali|string, 1: Jalali|string} $range
     */
    public function whereJalaliBetween(string $column, array $range, string $boolean = 'and', bool $not = false): self
    {
        if (count($range) < 2) {
            throw new InvalidArgumentException('whereJalaliBetween() expects exactly two dates.');
        }

        $range = array_values($range);

        $start = Jalali::parse($range[0])->startOfDay();
        $end = Jalali::parse($range[1])->startOfDay();

        if ($end->lessThan($start)) {
            [$start, $end] = [$end, $start];
        }

        return $this->addJalaliRange($column, $start, $end->addDays(1), $boolean, $not);
    }

    public function orWhereJalaliBetween(string $column, array $range): self
    {
        return $this->whereJalaliBetween($column, $range, 'or');
    }

    public function whereJalaliNotBetween(string $column, array $range, string $boolean = 'and'): self
    {
        return $this->whereJalaliBetween($column, $range, $boolean, true);
    }

    /**
     * A whole Jalali year, Farvardin 1 to the last day of Esfand.
     */
    public function whereJalaliYear(string $column, int $year, string $boolean = 'and', bool $not = false): self
    {
        $start = Jalali::create($year, 1, 1);

        return $this->addJalaliRange($column, $start, $start->addYears(1), $boolean, $not);
    }

    public function orWhereJalaliYear(string $column, int $year): self
    {
        return $this->whereJalaliYear($column, $year, 'or');
    }

    /**
     * A whole Jalali month. The year is required, since a month on its own is
     * not a contiguous range.
     */
    public function whereJalaliMonth(string $column, int $year, int $month, string $boolean = 'and', bool $not = false): self
    {
        $start = Jalali::create($year, $month, 1);

        return $this->addJalaliRange($column, $start, $start->addMonths(1), $boolean, $not);
    }

    public function orWhereJalaliMonth(string $column, int $year, int $month): self
    {
        return $this->whereJalaliMonth($column, $year, $month, 'or');
    }

    /**
     * The Persian week containing the given day, Saturday through Friday.
     *
     * @param Jalali|string|null $date defaults to today
     */
    public function whereJalaliWeek(string $column, $date = null, string $boolean = 'and'): self
    {
        $start = ($date === null ? Jalali::today() : Jalali::parse($date))->startOfWeek();

        return $this->addJalaliRange($column, $start, $start->addDays(7), $boolean, false);
    }

    public function whereJalaliToday(string $column, string $boolean = 'and'): self
    {
        return $this->whereJalaliDate($column, Jalali::today(), $boolean);
    }

    public function whereJalaliYesterday(string $column, string $boolean = 'and'): self
    {
        return $this->whereJalaliDate($column, Jalali::today()->subDays(1), $boolean);
    }

    public function whereJalaliTomorrow(string $column, string $boolean = 'and'): self
    {
        return $this->whereJalaliDate($column, Jalali::today()->addDays(1), $boolean);
    }

    public function whereJalaliThisWeek(string $column, string $boolean = 'and'): self
    {
        return $this->whereJalaliWeek($column, null, $boolean);
    }

    public function whereJalaliThisMonth(string $column, string $boolean = 'and'): self
    {
        $today = Jalali::today();

        return $this->whereJalaliMonth($column, $today->year(), $today->month(), $boolean);
    }

    public function whereJalaliLastMonth(string $column, string $boolean = 'and'): self
    {
        $previous = Jalali::today()->startOfMonth()->subMonths(1);

        return $this->whereJalaliMonth($column, $previous->year(), $previous->month(), $boolean);
    }

    public function whereJalaliThisYear(string $column, string $boolean = 'and'): self
    {
        return $this->whereJalaliYear($column, Jalali::today()->year(), $boolean);
    }

    /**
     * The last N days, today included.
     */
    public function whereJalaliLastDays(string $column, int $days, string $boolean = 'and'): self
    {
        if ($days < 1) {
            throw new InvalidArgumentException('The number of days must be at least 1.');
        }

        $today = Jalali::today();

        return $this->addJalaliRange($column, $today->subDays($days - 1), $today->addDays(1), $boolean, false);
    }

    /**
     * The half-open range [$start, $end) every Jalali clause is built from.
     */
    protected function addJalaliRange(string $column, Jalali $start, Jalali $end, string $boolean, bool $not): self
    {
        $from = $this->jalaliBoundary($column, $start);
        $to = $this->jalaliBoundary($column, $end);

        return $this->whereNested(function (Builder $query) use ($column, $from, $to, $not) {
            if ($not) {
                $query->where($column, '<', $from)->orWhere($column, '>=', $to);
            } else {
                $query->where($column, '>=', $from)->where($column, '<', $to);
            }
        }, $boolean);
    }

    /**
     * The value to compare the column against for the start of the given day.
     *
     * @return int|string
     */
    protected function jalaliBoundary(string $column, Jalali $date)
    {
        [$mode, $format] = $this->storageFor($column);

        if ($mode === Jalali::UNIX) {
            return $date->startOfDay()->toTimestamp();
        }

        if (! Jalali::isSortableFormat($format)) {
            throw new RuntimeException(
                "Column [{$column}] stores dates as [{$format}], which does not sort chronologically, "
                . 'so it cannot be range-queried. Store a zero-padded year-month-day value, or declare '
                . 'the real format with dateStorage().'
            );
        }

        return $mode === Jalali::JALALI
            ? $date->format($format)
            : $date->toGregorian()->format($format);
    }

    /**
     * @return array{0: string, 1: string|null}
     */
    protected function storageFor(string $column): array
    {
        foreach ([$column, $this->stripAlias($column)] as $key) {
            if (isset($this->dateStorage[$key])) {
                return $this->dateStorage[$key];
            }
        }

        return $this->defaultDateStorage;
    }

    /**
     * Turn a stored value into a Jalali date, whatever calendar it is in.
     */
    protected function toJalali($value, string $column): Jalali
    {
        [$mode] = $this->storageFor($column);

        if ($mode === Jalali::UNIX) {
            return Jalali::fromTimestamp((int) $value);
        }

        return $mode === Jalali::JALALI ? Jalali::parse($value) : Jalali::fromGregorian($value);
    }

    protected function applyJalaliCasts(array $row): array
    {
        foreach ($this->jalaliCasts as $column => $format) {
            $key = $this->stripAlias($column);

            if (! array_key_exists($key, $row) || $row[$key] === null || $row[$key] === '') {
                continue;
            }

            $row[$key] = $this->toJalali($row[$key], $column)->format($format);
        }

        return $row;
    }

    /* ------------------------------------------------------------------
     | Grouping & having
     | ------------------------------------------------------------------ */

    /**
     * @param array|string $groups
     */
    public function groupBy($groups): self
    {
        foreach (is_array($groups) ? $groups : func_get_args() as $group) {
            $this->groups[] = $group;
        }

        return $this;
    }

    public function groupByRaw(string $sql, array $bindings = []): self
    {
        $this->groups[] = new Expression($sql);

        $this->addBinding($bindings, 'groupBy');

        return $this;
    }

    public function having(string $column, $operator = null, $value = null, string $boolean = 'and'): self
    {
        [$value, $operator] = $this->prepareValueAndOperator($value, $operator, func_num_args() === 2);

        $type = 'Basic';

        $this->havings[] = compact('type', 'column', 'operator', 'value', 'boolean');

        $this->addValueBinding($value, 'having');

        return $this;
    }

    public function orHaving(string $column, $operator = null, $value = null): self
    {
        [$value, $operator] = $this->prepareValueAndOperator($value, $operator, func_num_args() === 2);

        return $this->having($column, $operator, $value, 'or');
    }

    public function havingRaw(string $sql, array $bindings = [], string $boolean = 'and'): self
    {
        $type = 'Raw';

        $this->havings[] = compact('type', 'sql', 'boolean');

        $this->addBinding($bindings, 'having');

        return $this;
    }

    public function havingBetween(string $column, array $values, string $boolean = 'and', bool $not = false): self
    {
        $type = 'Between';
        $values = array_slice(array_values($values), 0, 2);

        $this->havings[] = compact('type', 'column', 'values', 'boolean', 'not');

        foreach ($values as $value) {
            $this->addValueBinding($value, 'having');
        }

        return $this;
    }

    public function havingNull(string $column, string $boolean = 'and', bool $not = false): self
    {
        $type = $not ? 'NotNull' : 'Null';

        $this->havings[] = compact('type', 'column', 'boolean');

        return $this;
    }

    /* ------------------------------------------------------------------
     | Ordering, limit & offset
     | ------------------------------------------------------------------ */

    /**
     * @param Closure|Builder|Expression|string $column
     */
    public function orderBy($column, string $direction = 'asc'): self
    {
        $direction = strtolower($direction);

        if (! in_array($direction, ['asc', 'desc'], true)) {
            throw new InvalidArgumentException('Order direction must be "asc" or "desc".');
        }

        if ($this->isQueryable($column)) {
            [$sql, $bindings] = $this->parseSub($column);

            $column = new Expression('(' . $sql . ')');

            $this->addBinding($bindings, $this->unions ? 'union' : 'order');
        }

        $order = compact('column', 'direction');

        if ($this->unions) {
            $this->unionOrders[] = $order;
        } else {
            $this->orders[] = $order;
        }

        return $this;
    }

    public function orderByDesc($column): self
    {
        return $this->orderBy($column, 'desc');
    }

    public function orderByRaw(string $sql, array $bindings = []): self
    {
        if ($this->unions) {
            $this->unionOrders[] = compact('sql');
            $this->addBinding($bindings, 'union');
        } else {
            $this->orders[] = compact('sql');
            $this->addBinding($bindings, 'order');
        }

        return $this;
    }

    public function latest(string $column = 'created_at'): self
    {
        return $this->orderBy($column, 'desc');
    }

    public function oldest(string $column = 'created_at'): self
    {
        return $this->orderBy($column, 'asc');
    }

    public function inRandomOrder(): self
    {
        return $this->orderByRaw($this->grammar->compileRandom());
    }

    public function reorder($column = null, string $direction = 'asc'): self
    {
        $this->orders = [];
        $this->unionOrders = [];
        $this->bindings['order'] = [];

        return $column === null ? $this : $this->orderBy($column, $direction);
    }

    public function limit(?int $value): self
    {
        $property = $this->unions ? 'unionLimit' : 'limit';

        $this->$property = $value === null ? null : max(0, $value);

        return $this;
    }

    public function offset(?int $value): self
    {
        $property = $this->unions ? 'unionOffset' : 'offset';

        $this->$property = $value === null ? null : max(0, $value);

        return $this;
    }

    public function take(?int $value): self
    {
        return $this->limit($value);
    }

    public function skip(?int $value): self
    {
        return $this->offset($value);
    }

    public function forPage(int $page, int $perPage = 15): self
    {
        return $this->offset((max(1, $page) - 1) * $perPage)->limit($perPage);
    }

    public function lockForUpdate(): self
    {
        $this->lock = true;

        return $this;
    }

    public function sharedLock(): self
    {
        $this->lock = false;

        return $this;
    }

    /* ------------------------------------------------------------------
     | Unions
     | ------------------------------------------------------------------ */

    /**
     * @param Closure|Builder $query
     */
    public function union($query, bool $all = false): self
    {
        $query = $this->createSub($query);

        $this->unions[] = compact('query', 'all');

        $this->addBinding($query->getBindings(), 'union');

        return $this;
    }

    public function unionAll($query): self
    {
        return $this->union($query, true);
    }

    /* ------------------------------------------------------------------
     | Conditional clauses
     | ------------------------------------------------------------------ */

    public function when($value, callable $callback, ?callable $default = null): self
    {
        $value = $value instanceof Closure ? $value($this) : $value;

        if ($value) {
            return $callback($this, $value) ?: $this;
        }

        if ($default !== null) {
            return $default($this, $value) ?: $this;
        }

        return $this;
    }

    public function unless($value, callable $callback, ?callable $default = null): self
    {
        $value = $value instanceof Closure ? $value($this) : $value;

        return $this->when(! $value, $callback, $default);
    }

    public function tap(callable $callback): self
    {
        $callback($this);

        return $this;
    }

    /* ------------------------------------------------------------------
     | Reading results
     | ------------------------------------------------------------------ */

    /**
     * The raw rows, before a subclass gets a chance to turn them into
     * something else. Anything that needs plain arrays goes through here.
     */
    protected function runSelect(array $columns = ['*']): array
    {
        $rows = $this->onceWithColumns($columns, function () {
            return $this->connection->select($this->toSql(), $this->getBindings());
        });

        return $this->jalaliCasts === [] ? $rows : array_map([$this, 'applyJalaliCasts'], $rows);
    }

    /**
     * Turn one raw row into whatever this builder hands out. Overridden by
     * ModelBuilder so a model comes back instead.
     *
     * @return Row
     */
    protected function hydrateRow(array $row)
    {
        return new Row($row);
    }

    /**
     * Rows come back as Row objects, so columns are read as properties.
     *
     * @return Row[]
     */
    public function get(array $columns = ['*']): array
    {
        return array_map([$this, 'hydrateRow'], $this->runSelect($columns));
    }

    /**
     * The same rows as plain associative arrays.
     *
     * @return array[]
     */
    public function toBase(array $columns = ['*']): array
    {
        return $this->runSelect($columns);
    }

    /**
     * @return Generator<Row>
     */
    public function cursor(array $columns = ['*']): Generator
    {
        $rows = $this->onceWithColumns($columns, function () {
            return $this->connection->cursor($this->toSql(), $this->getBindings());
        });

        foreach ($rows as $row) {
            yield $this->hydrateRow($this->jalaliCasts === [] ? $row : $this->applyJalaliCasts($row));
        }
    }

    /**
     * @return Row|null the first matching row, or null
     */
    public function first(array $columns = ['*'])
    {
        $results = $this->limit(1)->get($columns);

        return $results[0] ?? null;
    }

    /**
     * @return Row the first matching row
     */
    public function firstOrFail(array $columns = ['*'])
    {
        $result = $this->first($columns);

        if ($result === null) {
            throw new RecordNotFoundException($this->describeTable());
        }

        return $result;
    }

    /**
     * The first matching row, or whatever the callback returns.
     *
     * @return Row|mixed
     */
    public function firstOr(callable $callback, array $columns = ['*'])
    {
        $result = $this->first($columns);

        return $result ?? $callback($this);
    }

    /**
     * The first row matching a single condition.
     *
     * @return Row|null
     */
    public function firstWhere($column, $operator = null, $value = null, string $boolean = 'and')
    {
        return $this->where(...func_get_args())->first();
    }

    /**
     * Exactly one row must match, or it is an error either way.
     *
     * @return Row
     */
    public function sole(array $columns = ['*'])
    {
        $results = $this->limit(2)->get($columns);

        if ($results === []) {
            throw new RecordNotFoundException($this->describeTable());
        }

        if (count($results) > 1) {
            throw new RuntimeException(
                'More than one record matched the query on [' . $this->describeTable() . '], but exactly one was expected.'
            );
        }

        return $results[0];
    }

    /**
     * The primary key column this query looks rows up by. 'id' unless told
     * otherwise, and taken from the model when there is one.
     */
    public function keyName(string $column): self
    {
        $this->keyName = $column;

        return $this;
    }

    public function getKeyName(): string
    {
        return $this->keyName;
    }

    /**
     * @return Row|null the matching row, or null
     */
    public function find($id, array $columns = ['*'], ?string $column = null)
    {
        return $this->where($this->qualifyColumn($column ?: $this->keyName), '=', $id)->first($columns);
    }

    /**
     * @return Row the matching row
     */
    public function findOrFail($id, array $columns = ['*'], ?string $column = null)
    {
        $result = $this->find($id, $columns, $column);

        if ($result === null) {
            throw new RecordNotFoundException($this->describeTable(), $id);
        }

        return $result;
    }

    /**
     * The matching row, or whatever the callback returns.
     *
     * @return Row|mixed
     */
    public function findOr($id, callable $callback, array $columns = ['*'])
    {
        $result = $this->find($id, $columns);

        return $result ?? $callback($this);
    }

    protected function describeTable(): string
    {
        return is_string($this->from) && $this->from !== '' ? $this->from : 'the query';
    }

    /**
     * The value of a single column of the first matching row.
     */
    public function value(string $column)
    {
        $rows = (clone $this)->limit(1)->runSelect([$column]);

        return $rows === [] ? null : reset($rows[0]);
    }

    /**
     * A flat list of one column, optionally keyed by another.
     */
    public function pluck(string $column, ?string $key = null): array
    {
        $rows = (clone $this)->runSelect($key === null ? [$column] : [$column, $key]);

        $columnKey = $this->stripAlias($column);
        $keyKey = $key === null ? null : $this->stripAlias($key);

        $results = [];

        foreach ($rows as $row) {
            if ($keyKey === null) {
                $results[] = $row[$columnKey] ?? null;
            } else {
                $results[$row[$keyKey]] = $row[$columnKey] ?? null;
            }
        }

        return $results;
    }

    /**
     * One column of every matching row, joined into a string.
     */
    public function implode(string $column, string $glue = ''): string
    {
        return implode($glue, $this->pluck($column));
    }

    public function exists(): bool
    {
        $result = $this->connection->selectOne($this->grammar->compileExists($this), $this->getBindings());

        return (bool) ($result['exists'] ?? false);
    }

    public function doesntExist(): bool
    {
        return ! $this->exists();
    }

    /**
     * @param array|string $columns
     */
    public function count($columns = '*'): int
    {
        return (int) $this->aggregate('count', is_array($columns) ? $columns : [$columns]);
    }

    public function min(string $column)
    {
        return $this->aggregate('min', [$column]);
    }

    public function max(string $column)
    {
        return $this->aggregate('max', [$column]);
    }

    public function sum(string $column)
    {
        return $this->aggregate('sum', [$column]) ?? 0;
    }

    public function avg(string $column)
    {
        return $this->aggregate('avg', [$column]);
    }

    public function average(string $column)
    {
        return $this->avg($column);
    }

    public function aggregate(string $function, array $columns = ['*'])
    {
        $query = clone $this;

        $query->aggregate = ['function' => $function, 'columns' => $columns];
        $query->orders = [];
        $query->bindings['order'] = [];

        $results = $query->runSelect();

        return $results[0]['aggregate'] ?? null;
    }

    /**
     * Walk the result set in pages. Return false from the callback to stop.
     */
    public function chunk(int $count, callable $callback): bool
    {
        if ($count < 1) {
            throw new InvalidArgumentException('The chunk size must be at least 1.');
        }

        $page = 1;

        do {
            $results = (clone $this)->forPage($page, $count)->get();
            $countResults = count($results);

            if ($countResults === 0) {
                break;
            }

            if ($callback($results, $page) === false) {
                return false;
            }

            $page++;
        } while ($countResults === $count);

        return true;
    }

    /**
     * Walk the table in key order rather than by page, so rows the callback
     * changes cannot shift the ones still to come. Return false to stop.
     */
    public function chunkById(int $count, callable $callback, string $column = 'id'): bool
    {
        if ($count < 1) {
            throw new InvalidArgumentException('The chunk size must be at least 1.');
        }

        $lastId = null;
        $page = 1;

        do {
            $query = clone $this;
            $query->orders = [];
            $query->bindings['order'] = [];

            if ($lastId !== null) {
                $query->where($column, '>', $lastId);
            }

            $results = $query->orderBy($column)->limit($count)->get();
            $countResults = count($results);

            if ($countResults === 0) {
                break;
            }

            if ($callback($results, $page) === false) {
                return false;
            }

            $lastId = $results[$countResults - 1]->{$this->stripAlias($column)};

            if ($lastId === null) {
                throw new RuntimeException("chunkById() needs [{$column}] in the result set to page through it.");
            }

            $page++;
        } while ($countResults === $count);

        return true;
    }

    public function each(callable $callback, int $count = 1000): bool
    {
        return $this->chunk($count, function (array $results) use ($callback) {
            foreach ($results as $key => $value) {
                if ($callback($value, $key) === false) {
                    return false;
                }
            }

            return true;
        });
    }

    /**
     * @return array{data: array, total: int, per_page: int, current_page: int, last_page: int, from: int|null, to: int|null}
     */
    public function paginate(int $perPage = 15, int $page = 1, array $columns = ['*']): array
    {
        $page = max(1, $page);
        $perPage = max(1, $perPage);

        $countQuery = clone $this;
        $countQuery->limit = null;
        $countQuery->offset = null;
        $countQuery->columns = [];

        $total = $countQuery->count();

        $results = $total > 0 ? $this->forPage($page, $perPage)->get($columns) : [];

        return [
            'data' => $results,
            'total' => $total,
            'per_page' => $perPage,
            'current_page' => $page,
            'last_page' => (int) max(1, ceil($total / $perPage)),
            'from' => $results ? ($page - 1) * $perPage + 1 : null,
            'to' => $results ? ($page - 1) * $perPage + count($results) : null,
        ];
    }

    /* ------------------------------------------------------------------
     | Writing
     | ------------------------------------------------------------------ */

    /**
     * Insert one row and read it back.
     *
     *     $user = NDB::table('users')->create(['name' => 'John']);
     *     $user->id;
     *
     * @return Row the stored row
     */
    public function create(array $values = [])
    {
        $id = (clone $this)->insertGetId($values);

        if ($id !== null && $id !== 0 && $id !== '') {
            $row = (clone $this)->where($this->qualifyColumn($this->keyName), '=', $id)->first();

            if ($row !== null) {
                return $row;
            }
        }

        // No usable key came back: hand over what was written.
        return new Row($values);
    }

    /**
     * Insert several rows and read them all back, in one transaction.
     *
     * @return Row[]
     */
    public function createMany(array $rows): array
    {
        return $this->connection->transaction(function () use ($rows) {
            $created = [];

            foreach ($rows as $values) {
                $created[] = $this->create($values);
            }

            return $created;
        });
    }

    /**
     * The first row matching $attributes, inserting it when there is none.
     *
     * @return Row
     */
    public function firstOrCreate(array $attributes, array $values = [])
    {
        $existing = (clone $this)->where($attributes)->first();

        return $existing ?? $this->create(array_merge($attributes, $values));
    }

    /**
     * Update the row matching $attributes, or insert it when there is none.
     * Either way the stored row comes back.
     *
     * @return Row
     */
    public function updateOrCreate(array $attributes, array $values = [])
    {
        $existing = (clone $this)->where($attributes)->first();

        if ($existing === null) {
            return $this->create(array_merge($attributes, $values));
        }

        if ($values !== []) {
            (clone $this)->where($attributes)->update($values);
        }

        return (clone $this)->where($attributes)->first() ?? $existing;
    }

    /**
     * The same thing, under the name people reach for first.
     *
     * @return Row
     */
    public function createOrUpdate(array $attributes, array $values = [])
    {
        return $this->updateOrCreate($attributes, $values);
    }

    /**
     * insert(['name' => 'John']) or insert([['name' => 'A'], ['name' => 'B']])
     */
    public function insert(array $values): bool
    {
        if ($values === []) {
            return true;
        }

        $values = $this->normalizeInsertValues($values);

        return $this->connection->insert(
            $this->grammar->compileInsert($this, $values),
            $this->insertBindings($values)
        );
    }

    /**
     * @return int|string the primary key of the inserted row
     */
    public function insertGetId(array $values, ?string $sequence = null)
    {
        $rows = $values === [] ? [] : [$values];

        $this->connection->insert(
            $this->grammar->compileInsert($this, $rows),
            $this->insertBindings($rows)
        );

        $id = $this->connection->lastInsertId($sequence);

        return is_numeric($id) ? (int) $id : $id;
    }

    /**
     * @return int the number of rows actually inserted
     */
    public function insertOrIgnore(array $values): int
    {
        if ($values === []) {
            return 0;
        }

        $values = $this->normalizeInsertValues($values);

        return $this->connection->affectingStatement(
            $this->grammar->compileInsert($this, $values, 'ignore'),
            $this->insertBindings($values)
        );
    }

    /**
     * Copy rows in from another query, without pulling them through PHP.
     *
     *     NDB::table('archived_orders')->insertUsing(
     *         ['code', 'total'],
     *         NDB::table('orders')->select('code', 'total')->where('status', 'done')
     *     );
     *
     * @param Closure|Builder|string $query
     * @return int the number of rows inserted
     */
    public function insertUsing(array $columns, $query): int
    {
        [$sql, $bindings] = $this->parseSub($query);

        return $this->connection->affectingStatement(
            $this->grammar->compileInsertUsing($this, $columns, $sql),
            $bindings
        );
    }

    /**
     * Insert rows, updating the given columns when a unique constraint collides.
     *
     * @param array|string $uniqueBy columns of the unique index (required by SQLite, ignored by MySQL)
     * @param array|null $update columns to update, defaults to every inserted column
     */
    public function upsert(array $values, $uniqueBy, ?array $update = null): int
    {
        if ($values === []) {
            return 0;
        }

        $values = $this->normalizeInsertValues($values);
        $uniqueBy = (array) $uniqueBy;

        if ($update === null) {
            $update = array_keys(reset($values));
        }

        $bindings = $this->insertBindings($values);

        foreach ($update as $key => $value) {
            if (is_numeric($key)) {
                continue;   // a bare column name: the driver reuses the inserted value
            }

            if ($value instanceof Expression) {
                foreach ($value->getBindings() as $binding) {
                    $bindings[] = $binding;
                }
            } else {
                $bindings[] = $value;
            }
        }

        return $this->connection->affectingStatement(
            $this->grammar->compileUpsert($this, $values, $uniqueBy, $update),
            $bindings
        );
    }

    /**
     * @return int the number of affected rows
     */
    public function update(array $values): int
    {
        if ($values === []) {
            return 0;
        }

        return $this->connection->update(
            $this->grammar->compileUpdate($this, $values),
            $this->grammar->prepareBindingsForUpdate($this, $values)
        );
    }

    public function updateOrInsert(array $attributes, array $values = []): bool
    {
        if (! (clone $this)->where($attributes)->exists()) {
            return $this->insert(array_merge($attributes, $values));
        }

        if ($values === []) {
            return true;
        }

        return (bool) $this->where($attributes)->update($values);
    }

    public function increment(string $column, $amount = 1, array $extra = []): int
    {
        if (! is_numeric($amount)) {
            throw new InvalidArgumentException('The increment amount must be numeric.');
        }

        $wrapped = $this->grammar->wrap($column);

        return $this->update(array_merge([$column => new Expression($wrapped . ' + ' . (0 + $amount))], $extra));
    }

    public function decrement(string $column, $amount = 1, array $extra = []): int
    {
        if (! is_numeric($amount)) {
            throw new InvalidArgumentException('The decrement amount must be numeric.');
        }

        $wrapped = $this->grammar->wrap($column);

        return $this->update(array_merge([$column => new Expression($wrapped . ' - ' . (0 + $amount))], $extra));
    }

    /**
     * @return int the number of deleted rows
     */
    public function delete($id = null): int
    {
        if ($id !== null) {
            $this->where($this->qualifyColumn($this->keyName), '=', $id);
        }

        return $this->connection->delete(
            $this->grammar->compileDelete($this),
            $this->grammar->prepareBindingsForDelete($this)
        );
    }

    public function truncate(): void
    {
        foreach ($this->grammar->compileTruncate($this) as $sql => $bindings) {
            try {
                $this->connection->statement($sql, $bindings);
            } catch (QueryException $e) {
                // SQLite only has a sqlite_sequence table once an AUTOINCREMENT column exists.
                if (strpos($sql, 'sqlite_sequence') === false) {
                    throw $e;
                }
            }
        }
    }

    /* ------------------------------------------------------------------
     | Debugging
     | ------------------------------------------------------------------ */

    public function toSql(): string
    {
        return $this->grammar->compileSelect($this);
    }

    /**
     * The SQL with its bindings interpolated. For reading, never for executing.
     */
    public function toRawSql(): string
    {
        $sql = $this->toSql();

        foreach ($this->getBindings() as $binding) {
            if ($binding === null) {
                $value = 'null';
            } elseif (is_bool($binding)) {
                $value = $binding ? '1' : '0';
            } elseif (is_int($binding) || is_float($binding)) {
                $value = (string) $binding;
            } else {
                $value = "'" . str_replace("'", "''", (string) $binding) . "'";
            }

            $position = strpos($sql, '?');

            if ($position === false) {
                break;
            }

            $sql = substr_replace($sql, $value, $position, 1);
        }

        return $sql;
    }

    public function dump(): self
    {
        echo $this->toSql() . PHP_EOL;
        print_r($this->getBindings());

        return $this;
    }

    public function dd(): void
    {
        $this->dump();

        exit(1);
    }

    public function getBindings(): array
    {
        $bindings = [];

        foreach ($this->bindings as $group) {
            foreach ($group as $binding) {
                $bindings[] = $binding;
            }
        }

        return $bindings;
    }

    public function getRawBindings(): array
    {
        return $this->bindings;
    }

    /**
     * Register the bindings a value needs: its own if it is a raw fragment,
     * otherwise the value itself.
     */
    protected function addValueBinding($value, string $type = 'where'): self
    {
        if ($value instanceof Expression) {
            return $value->hasBindings() ? $this->addBinding($value->getBindings(), $type) : $this;
        }

        return $this->addBinding($value, $type);
    }

    public function addBinding($value, string $type = 'where'): self
    {
        if (! array_key_exists($type, $this->bindings)) {
            throw new InvalidArgumentException("Invalid binding type: {$type}.");
        }

        if (is_array($value)) {
            $this->bindings[$type] = array_values(array_merge($this->bindings[$type], $value));
        } else {
            $this->bindings[$type][] = $value;
        }

        return $this;
    }

    /* ------------------------------------------------------------------
     | Internals
     | ------------------------------------------------------------------ */

    protected function forNestedWhere(): Builder
    {
        $query = $this->newQuery();
        $query->from = $this->from;

        return $query;
    }

    protected function addArrayOfWheres(array $wheres, string $boolean, string $method = 'where'): self
    {
        return $this->whereNested(function (Builder $query) use ($wheres, $method, $boolean) {
            foreach ($wheres as $key => $value) {
                if (is_int($key) && is_array($value)) {
                    $query->$method(...array_values($value));
                } else {
                    $query->$method($key, '=', $value, $boolean);
                }
            }
        }, $boolean);
    }

    protected function whereSub($column, string $operator, $callback, string $boolean): self
    {
        $type = 'Sub';
        $query = $this->createSub($callback);

        $this->wheres[] = compact('type', 'column', 'operator', 'query', 'boolean');
        $this->addBinding($query->getBindings(), 'where');

        return $this;
    }

    protected function prepareValueAndOperator($value, $operator, bool $useDefault = false): array
    {
        if ($useDefault) {
            return [$operator, '='];
        }

        if ($this->invalidOperatorAndValue($operator, $value)) {
            throw new InvalidArgumentException('Illegal operator and value combination.');
        }

        return [$value, $operator];
    }

    protected function invalidOperatorAndValue($operator, $value): bool
    {
        return $value === null
            && in_array($operator, $this->operators, true)
            && ! in_array($operator, ['=', '<>', '!='], true);
    }

    protected function isQueryable($value): bool
    {
        return $value instanceof Closure || $value instanceof Builder;
    }

    /**
     * @param Closure|Builder $query
     */
    protected function createSub($query): Builder
    {
        if ($query instanceof Closure) {
            $callback = $query;

            $callback($query = $this->newQuery());
        }

        if (! $query instanceof Builder) {
            throw new InvalidArgumentException('A sub query must be a Closure or a Builder instance.');
        }

        return $query;
    }

    /**
     * @return array{0: string, 1: array}
     */
    protected function parseSub($query): array
    {
        if ($query instanceof Closure || $query instanceof Builder) {
            $query = $this->createSub($query);

            return [$query->toSql(), $query->getBindings()];
        }

        if (is_string($query)) {
            return [$query, []];
        }

        throw new InvalidArgumentException('A sub query must be a Closure, a Builder instance or a string.');
    }

    /**
     * Run a callback with the given columns applied, then restore the originals.
     */
    protected function onceWithColumns(array $columns, Closure $callback)
    {
        $original = $this->columns;

        if (empty($this->columns)) {
            $this->columns = $columns;
        }

        try {
            return $callback();
        } finally {
            $this->columns = $original;
        }
    }

    protected function normalizeInsertValues(array $values): array
    {
        if (! is_array(reset($values))) {
            return [$values];
        }

        $columns = null;
        $index = 0;

        foreach ($values as $key => $row) {
            ksort($row);
            $values[$key] = $row;

            if ($columns === null) {
                $columns = array_keys($row);
            } elseif (array_keys($row) !== $columns) {
                // Left alone this compiles to a VALUES list with mismatched
                // widths, which the driver rejects with a far vaguer message.
                throw new InvalidArgumentException(
                    'Every row of a multi-row insert must have the same columns. Row ' . $index
                    . ' has [' . implode(', ', array_keys($row)) . '] but the first row has ['
                    . implode(', ', $columns) . '].'
                );
            }

            $index++;
        }

        return array_values($values);
    }

    protected function insertBindings(array $rows): array
    {
        $bindings = [];

        foreach ($rows as $record) {
            foreach ($record as $value) {
                if ($value instanceof Expression) {
                    foreach ($value->getBindings() as $binding) {
                        $bindings[] = $binding;
                    }
                } else {
                    $bindings[] = $value;
                }
            }
        }

        return $bindings;
    }

    /**
     * Prefix a bare column with the table (or its alias) so joins stay unambiguous.
     */
    protected function qualifyColumn(string $column): string
    {
        if (! is_string($this->from) || $this->from === '' || strpos($column, '.') !== false) {
            return $column;
        }

        $segments = preg_split('/\s+as\s+/i', $this->from);

        return trim(end($segments)) . '.' . $column;
    }

    protected function stripAlias(string $column): string
    {
        $segments = preg_split('/\s+as\s+/i', $column);
        $last = trim(end($segments));

        $position = strrpos($last, '.');

        return $position === false ? $last : substr($last, $position + 1);
    }
}
