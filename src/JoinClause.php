<?php

namespace Or81\Eloquent;

use Closure;

/**
 * The ON clause of a join. It is a Builder, so every where*() method
 * is available inside a join closure alongside on() / orOn().
 */
class JoinClause extends Builder
{
    public string $type;

    /** @var Expression|string */
    public $table;

    protected Builder $parentQuery;

    /**
     * @param Expression|string $table
     */
    public function __construct(Builder $parentQuery, string $type, $table)
    {
        parent::__construct($parentQuery->getConnection(), $parentQuery->getGrammar());

        $this->type = $type;
        $this->table = $table;
        $this->parentQuery = $parentQuery;
        $this->from = $table;
    }

    /**
     * on('users.id', '=', 'posts.user_id') or on('users.id', 'posts.user_id')
     *
     * @param Closure|string $first
     */
    public function on($first, ?string $operator = null, ?string $second = null, string $boolean = 'and'): self
    {
        if ($first instanceof Closure) {
            return $this->whereNested($first, $boolean);
        }

        return $this->whereColumn($first, $operator, $second, $boolean);
    }

    /**
     * @param Closure|string $first
     */
    public function orOn($first, ?string $operator = null, ?string $second = null): self
    {
        return $this->on($first, $operator, $second, 'or');
    }
}
