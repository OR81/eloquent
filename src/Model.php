<?php

namespace Or81\Eloquent;

use ArrayAccess;
use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use JsonSerializable;
use LogicException;
use RuntimeException;

/**
 * An Eloquent-style base model. Everything about a table is declared on the
 * class, and the query builder is reached straight off it:
 *
 *     class Order extends Model
 *     {
 *         protected array $fillable = ['customer_id', 'total', 'status'];
 *
 *         protected array $casts = [
 *             'total'     => 'int',
 *             'meta'      => 'array',
 *             'is_paid'   => 'bool',
 *             'issued_at' => 'jalali',
 *         ];
 *
 *         protected array $dateStorage = ['issued_at' => [Jalali::JALALI, 'Y/m/d']];
 *
 *         public function scopePaid(ModelBuilder $query): void
 *         {
 *             $query->where('status', 'paid');
 *         }
 *     }
 *
 *     Order::paid()->whereJalaliThisMonth('issued_at')->get();
 *     $order = Order::create(['customer_id' => 1, 'total' => 250000]);
 *     $order->issued_at->format('l j F Y');
 *
 * @method static ModelBuilder where($column, $operator = null, $value = null)
 * @method static ModelBuilder whereIn(string $column, $values)
 * @method static ModelBuilder whereJalaliBetween(string $column, array $range)
 * @method static ModelBuilder orderBy($column, string $direction = 'asc')
 * @method static ModelBuilder limit(?int $value)
 * @method static ModelBuilder with(...$args)
 * @method static Model|null find($id, array $columns = ['*'])
 * @method static Model findOrFail($id, array $columns = ['*'])
 * @method static Model|null first(array $columns = ['*'])
 * @method static Model[] get(array $columns = ['*'])
 * @method static int count($columns = '*')
 * @method static bool exists()
 * @method static Model create(array $attributes = [])
 * @method static Model updateOrCreate(array $attributes, array $values = [])
 * @method static Model firstOrCreate(array $attributes, array $values = [])
 * @method static ModelBuilder withTrashed()
 * @method static ModelBuilder onlyTrashed()
 */
abstract class Model implements ArrayAccess, JsonSerializable
{
    /* ------------------------------------------------------------------
     | What the table looks like. Override these on the subclass.
     | ------------------------------------------------------------------ */

    /** Left empty, the table name is derived from the class name. */
    protected string $table = '';

    /** The named connection this model lives on, or null for the default. */
    protected ?string $connection = null;

    protected string $primaryKey = 'id';

    /** 'int' or 'string'. */
    protected string $keyType = 'int';

    /** False for a UUID or any other key the database does not generate. */
    protected bool $incrementing = true;

    /** Columns that may be mass assigned. Nothing is fillable until listed. */
    protected array $fillable = [];

    /** Columns that may never be mass assigned. Only consulted when $fillable is empty. */
    protected array $guarded = ['*'];

    /**
     * column => cast. One of: int, float, decimal:2, string, bool, array,
     * json, object, timestamp, date, datetime, jalali.
     */
    protected array $casts = [];

    /** Columns left out of toArray() and toJson(). */
    protected array $hidden = [];

    /** When set, the only columns kept by toArray() and toJson(). */
    protected array $visible = [];

    /** Accessor-backed values to add to toArray() and toJson(). */
    protected array $appends = [];

    /**
     * How date columns are stored, for the whereJalali*() clauses and the
     * 'jalali' cast. See the Jalali class for the modes.
     *
     * @var array<string, string|array{0: string, 1: string|null}>
     */
    protected array $dateStorage = [];

    /** Maintain created_at and updated_at automatically. */
    public bool $timestamps = true;

    /** Turn delete() into a deleted_at stamp. */
    protected bool $softDeletes = false;

    public const CREATED_AT = 'created_at';
    public const UPDATED_AT = 'updated_at';
    public const DELETED_AT = 'deleted_at';

    /* ------------------------------------------------------------------
     | State
     | ------------------------------------------------------------------ */

    /** The raw column values, exactly as the database holds them. */
    protected array $attributes = [];

    /** What they were when the row was loaded or last saved. */
    protected array $original = [];

    /** What the last save() wrote. */
    protected array $changes = [];

    /** Whether this instance corresponds to a row that is already stored. */
    public bool $exists = false;

    protected static bool $unguarded = false;

    public function __construct(array $attributes = [])
    {
        $this->fill($attributes);
    }

    /* ------------------------------------------------------------------
     | Mass assignment
     | ------------------------------------------------------------------ */

    public function fill(array $attributes): self
    {
        $filled = 0;

        foreach ($attributes as $key => $value) {
            if ($this->isFillable($key)) {
                $this->setAttribute($key, $value);
                $filled++;
            }
        }

        if ($filled === 0 && $attributes !== [] && ! static::$unguarded && $this->nothingIsFillable()) {
            throw new LogicException(
                'No attribute of [' . static::class . '] may be mass assigned. Add the columns to its '
                . '$fillable list, or use forceFill() to bypass the check.'
            );
        }

        return $this;
    }

    /**
     * Fill without consulting $fillable or $guarded.
     */
    public function forceFill(array $attributes): self
    {
        foreach ($attributes as $key => $value) {
            $this->setAttribute($key, $value);
        }

        return $this;
    }

    public function isFillable(string $key): bool
    {
        if (static::$unguarded) {
            return true;
        }

        if (in_array($key, $this->fillable, true)) {
            return true;
        }

        if ($this->fillable !== []) {
            return false;
        }

        if (in_array('*', $this->guarded, true)) {
            return false;
        }

        return ! in_array($key, $this->guarded, true);
    }

    protected function nothingIsFillable(): bool
    {
        return $this->fillable === [] && in_array('*', $this->guarded, true);
    }

    /**
     * Run a callback with mass assignment protection turned off.
     */
    public static function unguarded(callable $callback)
    {
        $previous = static::$unguarded;
        static::$unguarded = true;

        try {
            return $callback();
        } finally {
            static::$unguarded = $previous;
        }
    }

    /* ------------------------------------------------------------------
     | Attributes
     | ------------------------------------------------------------------ */

    public function getAttribute(string $key)
    {
        if ($key === '') {
            return null;
        }

        if (array_key_exists($key, $this->attributes) || isset($this->casts[$key])) {
            return $this->transform($key, $this->attributes[$key] ?? null);
        }

        if ($this->hasAccessor($key)) {
            return $this->{'get' . Str::studly($key) . 'Attribute'}(null);
        }

        return null;
    }

    public function setAttribute(string $key, $value): self
    {
        if ($this->hasMutator($key)) {
            $this->{'set' . Str::studly($key) . 'Attribute'}($value);

            return $this;
        }

        $this->attributes[$key] = $this->serialize($key, $value);

        return $this;
    }

    /**
     * The raw values, exactly as they go into the database.
     */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    /**
     * The value a column had when the row was loaded.
     */
    public function getOriginal(?string $key = null, $default = null)
    {
        if ($key === null) {
            return $this->original;
        }

        return array_key_exists($key, $this->original) ? $this->original[$key] : $default;
    }

    /**
     * The columns whose raw value differs from the loaded one.
     */
    public function getDirty(): array
    {
        $dirty = [];

        foreach ($this->attributes as $key => $value) {
            if (! array_key_exists($key, $this->original) || $value !== $this->original[$key]) {
                $dirty[$key] = $value;
            }
        }

        return $dirty;
    }

    public function isDirty(?string $key = null): bool
    {
        $dirty = $this->getDirty();

        return $key === null ? $dirty !== [] : array_key_exists($key, $dirty);
    }

    public function isClean(?string $key = null): bool
    {
        return ! $this->isDirty($key);
    }

    public function syncOriginal(): self
    {
        $this->original = $this->attributes;
        $this->changes = [];

        return $this;
    }

    /**
     * What the last save() actually wrote.
     */
    public function getChanges(): array
    {
        return $this->changes;
    }

    /**
     * Whether the last save() wrote anything, or a given column.
     */
    public function wasChanged(?string $key = null): bool
    {
        return $key === null ? $this->changes !== [] : array_key_exists($key, $this->changes);
    }

    /**
     * A subset of the model's values, cast as usual.
     */
    public function only(array $keys): array
    {
        $output = [];

        foreach ($keys as $key) {
            $output[$key] = $this->getAttribute($key);
        }

        return $output;
    }

    /**
     * Everything but the given columns.
     */
    public function except(array $keys): array
    {
        return array_diff_key($this->toArray(), array_flip($keys));
    }

    /**
     * Whether this is the same row as another model.
     */
    public function is(?self $other): bool
    {
        return $other !== null
            && static::class === get_class($other)
            && $this->getKey() !== null
            && $this->getKey() === $other->getKey()
            && $this->getTable() === $other->getTable();
    }

    public function isNot(?self $other): bool
    {
        return ! $this->is($other);
    }

    /**
     * An unsaved copy, without the key or the timestamps.
     */
    public function replicate(array $except = []): self
    {
        $skip = array_merge(
            [$this->primaryKey, $this->getCreatedAtColumn(), $this->getUpdatedAtColumn(), $this->getDeletedAtColumn()],
            $except
        );

        $copy = new static();

        $copy->attributes = array_diff_key($this->attributes, array_flip($skip));

        return $copy;
    }

    public function hasCast(string $key): bool
    {
        return isset($this->casts[$key]);
    }

    public function getCasts(): array
    {
        return $this->casts;
    }

    protected function hasAccessor(string $key): bool
    {
        return method_exists($this, 'get' . Str::studly($key) . 'Attribute');
    }

    protected function hasMutator(string $key): bool
    {
        return method_exists($this, 'set' . Str::studly($key) . 'Attribute');
    }

    protected function transform(string $key, $value)
    {
        $value = $this->castAttribute($key, $value);

        if ($this->hasAccessor($key)) {
            return $this->{'get' . Str::studly($key) . 'Attribute'}($value);
        }

        return $value;
    }

    /**
     * Turn a stored value into the PHP value the cast asks for.
     */
    protected function castAttribute(string $key, $value)
    {
        if ($value === null || ! isset($this->casts[$key])) {
            return $value;
        }

        [$type, $parameter] = array_pad(explode(':', $this->casts[$key], 2), 2, null);

        switch ($type) {
            case 'int':
            case 'integer':
                return (int) $value;
            case 'float':
            case 'double':
            case 'real':
                return (float) $value;
            case 'decimal':
                return number_format((float) $value, (int) $parameter, '.', '');
            case 'string':
                return (string) $value;
            case 'bool':
            case 'boolean':
                return (bool) $value;
            case 'array':
            case 'json':
                return is_array($value) ? $value : (json_decode((string) $value, true) ?? []);
            case 'object':
                return is_object($value) ? $value : json_decode((string) $value);
            case 'timestamp':
                return (int) $value;
            case 'date':
                return $this->asDateTime($value)->setTime(0, 0);
            case 'datetime':
                return $this->asDateTime($value);
            case 'jalali':
                return $this->asJalali($key, $value);
            default:
                throw new InvalidArgumentException("Unknown cast [{$this->casts[$key]}] on [" . static::class . "::\${$key}].");
        }
    }

    /**
     * Turn a PHP value into what the column should hold.
     */
    protected function serialize(string $key, $value)
    {
        if ($value === null) {
            return null;
        }

        $type = isset($this->casts[$key]) ? explode(':', $this->casts[$key], 2)[0] : null;

        if ($type === 'array' || $type === 'json' || $type === 'object') {
            return is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        if ($type === 'jalali' || $value instanceof Jalali) {
            return $this->jalaliToStorage($key, $value);
        }

        if ($type === 'timestamp') {
            return $value instanceof DateTimeInterface ? $value->getTimestamp() : (int) $value;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format($type === 'date' ? 'Y-m-d' : 'Y-m-d H:i:s');
        }

        if (is_bool($value)) {
            return (int) $value;
        }

        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        return $value;
    }

    protected function asDateTime($value): DateTimeImmutable
    {
        if ($value instanceof DateTimeImmutable) {
            return $value;
        }

        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromFormat('U', (string) $value->getTimestamp());
        }

        if (is_numeric($value)) {
            return (new DateTimeImmutable('@' . (int) $value))->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        }

        return new DateTimeImmutable((string) $value);
    }

    protected function asJalali(string $key, $value): Jalali
    {
        [$mode] = $this->storageFor($key);

        if ($mode === Jalali::UNIX) {
            return Jalali::fromTimestamp((int) $value);
        }

        return $mode === Jalali::JALALI ? Jalali::parse($value) : Jalali::fromGregorian($value);
    }

    /**
     * @return int|string
     */
    protected function jalaliToStorage(string $key, $value)
    {
        $date = Jalali::parse($value);

        [$mode, $format] = $this->storageFor($key);

        if ($mode === Jalali::UNIX) {
            return $date->toTimestamp();
        }

        if ($mode === Jalali::JALALI) {
            return $date->format($format);
        }

        return $date->hour() === 0 && $date->minute() === 0 && $date->second() === 0
            ? $date->toGregorianDateString()
            : $date->toGregorianDateTimeString();
    }

    /**
     * @return array{0: string, 1: string|null}
     */
    protected function storageFor(string $key): array
    {
        if (! isset($this->dateStorage[$key])) {
            return [Jalali::GREGORIAN, 'Y-m-d'];
        }

        $storage = (array) $this->dateStorage[$key];
        $mode = $storage[0];

        return [$mode, $storage[1] ?? Jalali::defaultFormatFor($mode)];
    }

    public function getDateStorage(): array
    {
        return $this->dateStorage;
    }

    /* ------------------------------------------------------------------
     | Table and key
     | ------------------------------------------------------------------ */

    public function getTable(): string
    {
        if ($this->table !== '') {
            return $this->table;
        }

        return Str::plural(Str::snake(Str::classBasename(static::class)));
    }

    public function setTable(string $table): self
    {
        $this->table = $table;

        return $this;
    }

    public function getKeyName(): string
    {
        return $this->primaryKey;
    }

    public function getQualifiedKeyName(): string
    {
        return $this->getTable() . '.' . $this->primaryKey;
    }

    public function getKey()
    {
        return $this->getAttribute($this->primaryKey);
    }

    public function getKeyType(): string
    {
        return $this->keyType;
    }

    public function getIncrementing(): bool
    {
        return $this->incrementing;
    }

    public function getConnectionName(): ?string
    {
        return $this->connection;
    }

    public function getConnection(): Connection
    {
        return NDB::connection($this->connection);
    }

    /* ------------------------------------------------------------------
     | Timestamps and soft deletes
     | ------------------------------------------------------------------ */

    public function usesTimestamps(): bool
    {
        return $this->timestamps;
    }

    public function usesSoftDeletes(): bool
    {
        return $this->softDeletes;
    }

    public function getCreatedAtColumn(): string
    {
        return static::CREATED_AT;
    }

    public function getUpdatedAtColumn(): string
    {
        return static::UPDATED_AT;
    }

    public function getDeletedAtColumn(): string
    {
        return static::DELETED_AT;
    }

    public function getQualifiedDeletedAtColumn(): string
    {
        return $this->getTable() . '.' . $this->getDeletedAtColumn();
    }

    public function trashed(): bool
    {
        return $this->softDeletes && ($this->attributes[$this->getDeletedAtColumn()] ?? null) !== null;
    }

    /**
     * The value to write into a timestamp column, in whatever the column stores.
     *
     * @return int|string
     */
    public function freshTimestampFor(string $column)
    {
        if (isset($this->dateStorage[$column])) {
            return $this->jalaliToStorage($column, Jalali::now());
        }

        return date('Y-m-d H:i:s');
    }

    protected function touchTimestamps(bool $creating): void
    {
        if (! $this->timestamps) {
            return;
        }

        $updatedAt = $this->getUpdatedAtColumn();

        if (! $this->isDirty($updatedAt)) {
            $this->attributes[$updatedAt] = $this->freshTimestampFor($updatedAt);
        }

        $createdAt = $this->getCreatedAtColumn();

        if ($creating && ! isset($this->attributes[$createdAt])) {
            $this->attributes[$createdAt] = $this->freshTimestampFor($createdAt);
        }
    }

    /* ------------------------------------------------------------------
     | Queries
     | ------------------------------------------------------------------ */

    public function newQuery(): ModelBuilder
    {
        $query = $this->newQueryWithoutScopes();

        if ($this->softDeletes) {
            $query->whereNull($this->getQualifiedDeletedAtColumn());
        }

        return $query;
    }

    /**
     * The same query, without the soft-delete filter.
     */
    public function newQueryWithoutScopes(): ModelBuilder
    {
        $connection = $this->getConnection();

        $query = new ModelBuilder($connection, $connection->getGrammar());

        $query->setModel($this)->from($this->getTable());

        foreach ($this->dateStorage as $column => $storage) {
            $query->dateStorage($column, ...array_values((array) $storage));
        }

        return $query;
    }

    public static function query(): ModelBuilder
    {
        return (new static())->newQuery();
    }

    /**
     * @return Model[]
     */
    public static function all(array $columns = ['*']): array
    {
        return static::query()->get($columns);
    }

    public function newInstance(array $attributes = []): self
    {
        return new static($attributes);
    }

    /**
     * Build a model from a row that is already in the database.
     */
    public function newFromBuilder(array $attributes): self
    {
        $model = new static();

        $model->attributes = $attributes;
        $model->exists = true;

        return $model->syncOriginal();
    }

    /* ------------------------------------------------------------------
     | Persistence
     | ------------------------------------------------------------------ */

    public function save(): bool
    {
        $this->changes = [];

        $query = $this->newQueryWithoutScopes();

        if ($this->exists) {
            $this->touchTimestamps(false);

            $dirty = $this->getDirty();

            if ($dirty === []) {
                return true;
            }

            $query->where($this->primaryKey, '=', $this->getKeyForQuery())->update($dirty);

            $this->syncOriginal();
            $this->changes = $dirty;

            return true;
        }

        $this->touchTimestamps(true);

        if ($this->incrementing) {
            $id = $query->insertGetId($this->attributes);

            $this->attributes[$this->primaryKey] = $this->keyType === 'int' ? (int) $id : $id;
        } else {
            if (! isset($this->attributes[$this->primaryKey])) {
                throw new RuntimeException(
                    '[' . static::class . '] does not use an auto-incrementing key, so ' . $this->primaryKey
                    . ' must be set before saving.'
                );
            }

            $query->insert($this->attributes);
        }

        $this->exists = true;

        $inserted = $this->attributes;

        $this->syncOriginal();
        $this->changes = $inserted;

        return true;
    }

    /**
     * Bump updated_at without changing anything else.
     */
    public function touch(): bool
    {
        if (! $this->exists || ! $this->timestamps) {
            return false;
        }

        $column = $this->getUpdatedAtColumn();

        $this->attributes[$column] = $this->freshTimestampFor($column);

        return $this->save();
    }

    /**
     * Delete rows by key, without loading them first.
     *
     * @param mixed ...$ids one key, several keys, or an array of them
     * @return int the number of rows deleted
     */
    public static function destroy(...$ids): int
    {
        $ids = count($ids) === 1 && is_array($ids[0]) ? $ids[0] : $ids;

        if ($ids === []) {
            return 0;
        }

        $instance = new static();
        $deleted = 0;

        foreach ($instance->newQuery()->whereIn($instance->getKeyName(), $ids)->get() as $model) {
            if ($model->delete()) {
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * Fill and save in one step.
     */
    public function update(array $attributes = []): bool
    {
        if (! $this->exists) {
            return false;
        }

        return $this->fill($attributes)->save();
    }

    public function delete(): bool
    {
        if (! $this->exists) {
            return false;
        }

        $query = $this->newQueryWithoutScopes()->where($this->primaryKey, '=', $this->getKeyForQuery());

        if ($this->softDeletes) {
            $column = $this->getDeletedAtColumn();

            $this->attributes[$column] = $this->freshTimestampFor($column);

            $query->update([$column => $this->attributes[$column]]);

            $this->syncOriginal();

            return true;
        }

        $query->delete();

        $this->exists = false;

        return true;
    }

    /**
     * Delete for real, even when the model soft deletes.
     */
    public function forceDelete(): bool
    {
        if (! $this->exists) {
            return false;
        }

        $this->newQueryWithoutScopes()->where($this->primaryKey, '=', $this->getKeyForQuery())->delete();

        $this->exists = false;

        return true;
    }

    /**
     * Undo a soft delete.
     */
    public function restore(): bool
    {
        if (! $this->softDeletes) {
            throw new LogicException('[' . static::class . '] does not use soft deletes.');
        }

        $column = $this->getDeletedAtColumn();

        $this->attributes[$column] = null;

        $this->newQueryWithoutScopes()
            ->where($this->primaryKey, '=', $this->getKeyForQuery())
            ->update([$column => null]);

        $this->syncOriginal();

        return true;
    }

    /**
     * Re-read this row into the current instance.
     */
    public function refresh(): self
    {
        if (! $this->exists) {
            return $this;
        }

        $fresh = $this->fresh();

        if ($fresh !== null) {
            $this->attributes = $fresh->getAttributes();
            $this->syncOriginal();
        }

        return $this;
    }

    /**
     * Re-read this row into a new instance, leaving this one alone.
     */
    public function fresh(): ?self
    {
        if (! $this->exists) {
            return null;
        }

        return $this->newQueryWithoutScopes()
            ->where($this->primaryKey, '=', $this->getKeyForQuery())
            ->first();
    }

    protected function getKeyForQuery()
    {
        return $this->attributes[$this->primaryKey] ?? null;
    }

    /* ------------------------------------------------------------------
     | Relations. Each returns a query, so call ->get() or ->first() on it.
     | ------------------------------------------------------------------ */

    public function hasMany(string $related, ?string $foreignKey = null, ?string $localKey = null): ModelBuilder
    {
        $foreignKey = $foreignKey ?: Str::snake(Str::classBasename(static::class)) . '_id';
        $localKey = $localKey ?: $this->primaryKey;

        return $this->relatedQuery($related)->where($foreignKey, '=', $this->getAttribute($localKey));
    }

    public function hasOne(string $related, ?string $foreignKey = null, ?string $localKey = null): ModelBuilder
    {
        return $this->hasMany($related, $foreignKey, $localKey)->limit(1);
    }

    public function belongsTo(string $related, ?string $foreignKey = null, ?string $ownerKey = null): ModelBuilder
    {
        $instance = $this->relatedModel($related);

        $foreignKey = $foreignKey ?: Str::snake(Str::classBasename($related)) . '_id';
        $ownerKey = $ownerKey ?: $instance->getKeyName();

        return $instance->newQuery()->where($ownerKey, '=', $this->getAttribute($foreignKey))->limit(1);
    }

    protected function relatedQuery(string $related): ModelBuilder
    {
        return $this->relatedModel($related)->newQuery();
    }

    protected function relatedModel(string $related): self
    {
        if (! is_subclass_of($related, self::class)) {
            throw new InvalidArgumentException("[{$related}] is not a " . self::class . '.');
        }

        return new $related();
    }

    /* ------------------------------------------------------------------
     | Output
     | ------------------------------------------------------------------ */

    public function toArray(): array
    {
        $output = [];

        foreach (array_keys($this->attributes) as $key) {
            $output[$key] = $this->presentable($this->getAttribute($key));
        }

        foreach ($this->appends as $key) {
            $output[$key] = $this->presentable($this->getAttribute($key));
        }

        if ($this->visible !== []) {
            $output = array_intersect_key($output, array_flip($this->visible));
        }

        return array_diff_key($output, array_flip($this->hidden));
    }

    public function toJson(int $flags = JSON_UNESCAPED_UNICODE): string
    {
        return json_encode($this->toArray(), $flags);
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Objects produced by the casts become strings on the way out.
     */
    protected function presentable($value)
    {
        if ($value instanceof Jalali) {
            return $value->toDateTimeString();
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        return $value;
    }

    /* ------------------------------------------------------------------
     | Magic
     | ------------------------------------------------------------------ */

    public function __get(string $key)
    {
        return $this->getAttribute($key);
    }

    public function __set(string $key, $value): void
    {
        $this->setAttribute($key, $value);
    }

    public function __isset(string $key): bool
    {
        return $this->getAttribute($key) !== null;
    }

    public function __unset(string $key): void
    {
        unset($this->attributes[$key]);
    }

    public function offsetExists($offset): bool
    {
        return $this->__isset((string) $offset);
    }

    #[\ReturnTypeWillChange]
    public function offsetGet($offset)
    {
        return $this->getAttribute((string) $offset);
    }

    public function offsetSet($offset, $value): void
    {
        $this->setAttribute((string) $offset, $value);
    }

    public function offsetUnset($offset): void
    {
        $this->__unset((string) $offset);
    }

    public function __call(string $method, array $parameters)
    {
        return $this->newQuery()->$method(...$parameters);
    }

    public static function __callStatic(string $method, array $parameters)
    {
        return (new static())->newQuery()->$method(...$parameters);
    }

    public function __toString(): string
    {
        return $this->toJson();
    }
}
