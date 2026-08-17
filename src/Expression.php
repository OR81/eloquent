<?php

namespace Or81\Eloquent;

use Stringable;

/**
 * A raw SQL fragment that is injected into the query as-is.
 *
 * Never build an Expression from user input, its value is not escaped.
 *
 * Values still belong in bindings, so a fragment may carry its own. Put a ?
 * everywhere a value goes and pass them in the same order:
 *
 *     NDB::raw("COALESCE(NULLIF(TRIM(city), ''), ?, city)", [$city])
 */
class Expression implements Stringable
{
    protected string $value;
    protected array $bindings;

    public function __construct(string $value, array $bindings = [])
    {
        $this->value = $value;
        $this->bindings = array_values($bindings);
    }

    public function getValue(): string
    {
        return $this->value;
    }

    /**
     * The values for the ? placeholders in this fragment, in order.
     */
    public function getBindings(): array
    {
        return $this->bindings;
    }

    public function hasBindings(): bool
    {
        return $this->bindings !== [];
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
