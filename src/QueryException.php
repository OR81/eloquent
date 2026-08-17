<?php

namespace Or81\Eloquent;

use PDOException;
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

    /**
     * The driver's [SQLSTATE, code, message], as PDO reports it.
     */
    public function getErrorInfo(): array
    {
        $previous = $this->getPrevious();

        return $previous instanceof PDOException && is_array($previous->errorInfo)
            ? $previous->errorInfo
            : [];
    }

    /**
     * The five-character SQLSTATE, e.g. '23000' for a constraint violation.
     */
    public function getSqlState(): ?string
    {
        return $this->getErrorInfo()[0] ?? null;
    }

    /**
     * The driver's own error number: 1062 on MySQL for a duplicate entry,
     * 19 / 1555 / 2067 on SQLite for a failed constraint.
     */
    public function getDriverCode()
    {
        return $this->getErrorInfo()[1] ?? null;
    }

    /**
     * Whether the statement failed because a row already exists with the same
     * value in a unique index or primary key. Works on both drivers, so
     * nothing has to test for 1062 by hand.
     */
    public function isUniqueViolation(): bool
    {
        $code = $this->getDriverCode();

        // MySQL: 1062 duplicate entry, 1586 duplicate for a partitioned table.
        if ($code === 1062 || $code === 1586) {
            return true;
        }

        // SQLite: 19 is any constraint, 1555 and 2067 are the unique ones.
        if ($code === 1555 || $code === 2067) {
            return true;
        }

        if ($code === 19) {
            return stripos($this->getErrorInfo()[2] ?? '', 'unique') !== false;
        }

        return false;
    }

    /**
     * Whether a foreign key stopped the statement.
     */
    public function isForeignKeyViolation(): bool
    {
        $code = $this->getDriverCode();

        if (in_array($code, [1216, 1217, 1451, 1452], true)) {
            return true;
        }

        return $code === 19 && stripos($this->getErrorInfo()[2] ?? '', 'foreign key') !== false;
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
