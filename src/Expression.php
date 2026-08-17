<?php

namespace Or81\Eloquent;

use Stringable;

/**
 * A raw SQL fragment that is injected into the query as-is.
 *
 * Never build an Expression from user input, its value is not escaped
 * and is not bound as a parameter.
 */
class Expression implements Stringable
{
    protected string $value;

    public function __construct(string $value)
    {
        $this->value = $value;
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
