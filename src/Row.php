<?php

namespace Or81\Eloquent;

use ArrayAccess;
use ArrayIterator;
use Countable;
use IteratorAggregate;
use JsonSerializable;
use Traversable;

/**
 * One row of a result set. Columns are read as properties:
 *
 *     $user = NDB::table('users')->first();
 *     $user->name;
 *
 * Array access still works, so code written against the old array results
 * keeps running:
 *
 *     $user['name'];
 *     foreach ($user as $column => $value) { ... }
 *
 * A column that is not in the result reads as null. Use has() to tell an
 * absent column from one that is really null.
 */
class Row implements ArrayAccess, IteratorAggregate, JsonSerializable, Countable
{
    protected array $attributes;

    public function __construct(array $attributes = [])
    {
        $this->attributes = $attributes;
    }

    public function get(string $key, $default = null)
    {
        return array_key_exists($key, $this->attributes) ? $this->attributes[$key] : $default;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->attributes);
    }

    public function set(string $key, $value): self
    {
        $this->attributes[$key] = $value;

        return $this;
    }

    public function toArray(): array
    {
        return $this->attributes;
    }

    public function keys(): array
    {
        return array_keys($this->attributes);
    }

    public function values(): array
    {
        return array_values($this->attributes);
    }

    public function only(array $keys): array
    {
        return array_intersect_key($this->attributes, array_flip($keys));
    }

    public function except(array $keys): array
    {
        return array_diff_key($this->attributes, array_flip($keys));
    }

    public function toJson(int $flags = JSON_UNESCAPED_UNICODE): string
    {
        return json_encode($this->attributes, $flags);
    }

    public function jsonSerialize(): array
    {
        return $this->attributes;
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->attributes);
    }

    public function count(): int
    {
        return count($this->attributes);
    }

    public function __get(string $key)
    {
        return $this->attributes[$key] ?? null;
    }

    public function __set(string $key, $value): void
    {
        $this->attributes[$key] = $value;
    }

    public function __isset(string $key): bool
    {
        return isset($this->attributes[$key]);
    }

    public function __unset(string $key): void
    {
        unset($this->attributes[$key]);
    }

    public function offsetExists($offset): bool
    {
        return isset($this->attributes[$offset]);
    }

    #[\ReturnTypeWillChange]
    public function offsetGet($offset)
    {
        return $this->attributes[$offset] ?? null;
    }

    public function offsetSet($offset, $value): void
    {
        if ($offset === null) {
            $this->attributes[] = $value;

            return;
        }

        $this->attributes[$offset] = $value;
    }

    public function offsetUnset($offset): void
    {
        unset($this->attributes[$offset]);
    }
}
