<?php

namespace Or81\Eloquent;

/**
 * Thrown by a model's findOrFail(), firstOrFail() and sole() when nothing
 * matched. Catching RecordNotFoundException catches this too.
 */
class ModelNotFoundException extends RecordNotFoundException
{
    public function __construct(string $model, $id = null)
    {
        parent::__construct($model, $id, $id === null
            ? "No query results for model [{$model}]."
            : "No query results for model [{$model}] with key [" . (is_scalar($id) ? $id : gettype($id)) . '].');
    }

    /**
     * The model class the query was against.
     */
    public function getModel(): string
    {
        return $this->getSubject();
    }
}
