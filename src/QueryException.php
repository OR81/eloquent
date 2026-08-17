<?php

namespace Or81\Eloquent;

use RuntimeException;
use Throwable;

/**
 * Thrown when a statement fails. Carries the SQL and bindings that caused it.
 */
class QueryException extends RuntimeException
{
    protected string $sql;
    protected array $bindings;

    public function __construct(string $sql, array $bindings, Throwable $previous)
    {
        parent::__construct($this->formatMessage($sql, $bindings, $previous), 0, $previous);

        $this->sql = $sql;
        $this->bindings = $bindings;
        $this->code = $previous->getCode();
    }

    public function getSql(): string
    {
        return $this->sql;
    }

    public function getBindings(): array
    {
        return $this->bindings;
    }

    protected function formatMessage(string $sql, array $bindings, Throwable $previous): string
    {
        $readable = $bindings === []
            ? $sql
            : $sql . ' - bindings: [' . implode(', ', array_map([$this, 'stringify'], $bindings)) . ']';

        return $previous->getMessage() . ' (SQL: ' . $readable . ')';
    }

    protected function stringify($value): string
    {
        if ($value === null) {
            return 'null';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return is_scalar($value) ? (string) $value : gettype($value);
    }
}
