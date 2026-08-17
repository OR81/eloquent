<?php

namespace Or81\Eloquent;

use Generator;
use RuntimeException;

/**
 * The query builder a Model hands out. It is the ordinary Builder with two
 * additions: rows come back as model instances, and scope methods declared on
 * the model become methods on the query.
 *
 *     User::where('active', 1)->orderBy('name')->get();   // User[]
 *     User::active()->get();                              // scopeActive()
 */
class ModelBuilder extends Builder
{
    protected Model $model;

    /** Turned off while running the aggregate and pluck helpers. */
    protected bool $hydrate = true;

    public function setModel(Model $model): self
    {
        $this->model = $model;

        return $this;
    }

    public function getModel(): Model
    {
        return $this->model;
    }

    /* ------------------------------------------------------------------
     | Results become models
     | ------------------------------------------------------------------ */

    /**
     * @return Model[]
     */
    public function get(array $columns = ['*']): array
    {
        $rows = $this->runSelect($columns);

        if (! $this->hydrate) {
            return $rows;
        }

        return array_map(fn (array $row) => $this->model->newFromBuilder($row), $rows);
    }

    /**
     * The plain arrays, with no model in the way.
     */
    public function toBase(array $columns = ['*']): array
    {
        return $this->runSelect($columns);
    }

    /**
     * @return Generator<Model>
     */
    public function cursor(array $columns = ['*']): Generator
    {
        foreach (parent::cursor($columns) as $row) {
            yield $this->hydrate ? $this->model->newFromBuilder($row) : $row;
        }
    }

    /**
     * @return Model|null
     */
    public function first(array $columns = ['*'])
    {
        $results = $this->limit(1)->get($columns);

        return $results[0] ?? null;
    }

    /**
     * @return Model
     */
    public function firstOrFail(array $columns = ['*'])
    {
        $result = $this->first($columns);

        if ($result === null) {
            throw new ModelNotFoundException(get_class($this->model));
        }

        return $result;
    }

    /**
     * @return Model|null
     */
    public function find($id, array $columns = ['*'], ?string $column = null)
    {
        return $this->where($this->qualifyColumn($column ?: $this->model->getKeyName()), '=', $id)->first($columns);
    }

    /**
     * @return Model
     */
    public function findOrFail($id, array $columns = ['*'])
    {
        $result = $this->find($id, $columns);

        if ($result === null) {
            throw new ModelNotFoundException(get_class($this->model), $id);
        }

        return $result;
    }

    /**
     * @return array{data: Model[], total: int, per_page: int, current_page: int, last_page: int, from: int|null, to: int|null}
     */
    public function paginate(int $perPage = 15, int $page = 1, array $columns = ['*']): array
    {
        return parent::paginate($perPage, $page, $columns);
    }

    /* ------------------------------------------------------------------
     | Writing through the model
     | ------------------------------------------------------------------ */

    /**
     * Fill a new model, save it, and hand it back.
     */
    public function create(array $attributes = []): Model
    {
        $model = $this->model->newInstance($attributes);

        $model->save();

        return $model;
    }

    /**
     * Like create(), but skipping the fillable check.
     */
    public function forceCreate(array $attributes = []): Model
    {
        $model = $this->model->newInstance()->forceFill($attributes);

        $model->save();

        return $model;
    }

    public function firstOrNew(array $attributes, array $values = []): Model
    {
        $existing = (clone $this)->where($attributes)->first();

        return $existing ?? $this->model->newInstance(array_merge($attributes, $values));
    }

    public function firstOrCreate(array $attributes, array $values = []): Model
    {
        $existing = (clone $this)->where($attributes)->first();

        return $existing ?? $this->create(array_merge($attributes, $values));
    }

    public function updateOrCreate(array $attributes, array $values = []): Model
    {
        $model = $this->firstOrNew($attributes, $values);

        if ($model->exists) {
            $model->fill($values);
        }

        $model->save();

        return $model;
    }

    /**
     * A mass update. The model's updated_at is refreshed unless it is already
     * part of the change.
     */
    public function update(array $values): int
    {
        if ($this->model->usesTimestamps() && ! array_key_exists($this->model->getUpdatedAtColumn(), $values)) {
            $values[$this->model->getUpdatedAtColumn()] = $this->model->freshTimestampFor($this->model->getUpdatedAtColumn());
        }

        return parent::update($values);
    }

    /* ------------------------------------------------------------------
     | Soft deletes
     | ------------------------------------------------------------------ */

    /**
     * Include the rows that have been soft deleted.
     */
    public function withTrashed(): self
    {
        $column = $this->model->getQualifiedDeletedAtColumn();

        foreach ($this->wheres as $index => $where) {
            if (($where['type'] ?? null) === 'Null' && ($where['column'] ?? null) === $column) {
                unset($this->wheres[$index]);

                $this->wheres = array_values($this->wheres);

                break;
            }
        }

        return $this;
    }

    /**
     * Only the rows that have been soft deleted.
     */
    public function onlyTrashed(): self
    {
        return $this->withTrashed()->whereNotNull($this->model->getQualifiedDeletedAtColumn());
    }

    /**
     * Bring soft-deleted rows back.
     */
    public function restore(): int
    {
        return $this->withTrashed()->update([$this->model->getDeletedAtColumn() => null]);
    }

    /**
     * Delete for real, soft deletes or not.
     */
    public function forceDelete(): int
    {
        return $this->withTrashed()->delete();
    }

    /* ------------------------------------------------------------------
     | Internals
     | ------------------------------------------------------------------ */

    /**
     * Aggregates and pluck() want plain rows, never models.
     */
    public function aggregate(string $function, array $columns = ['*'])
    {
        $query = clone $this;
        $query->hydrate = false;

        return $query->baseAggregate($function, $columns);
    }

    protected function baseAggregate(string $function, array $columns)
    {
        return parent::aggregate($function, $columns);
    }

    public function pluck(string $column, ?string $key = null): array
    {
        $query = clone $this;
        $query->hydrate = false;

        return $query->basePluck($column, $key);
    }

    protected function basePluck(string $column, ?string $key): array
    {
        return parent::pluck($column, $key);
    }

    /**
     * Nested wheres and sub-queries stay plain: only the outer query hydrates.
     */
    public function newQuery(): Builder
    {
        return new Builder($this->getConnection(), $this->getGrammar());
    }

    /**
     * Route unknown calls to the model's scopes, so scopeActive() on the model
     * becomes ->active() on the query.
     */
    public function __call(string $method, array $parameters)
    {
        $scope = 'scope' . Str::studly($method);

        if (method_exists($this->model, $scope)) {
            $result = $this->model->$scope($this, ...$parameters);

            return $result instanceof Builder ? $result : $this;
        }

        throw new RuntimeException(
            'Call to undefined method ' . static::class . "::{$method}(). "
            . 'Add a scope' . Str::studly($method) . '() method to ' . get_class($this->model) . ' to define it.'
        );
    }
}
