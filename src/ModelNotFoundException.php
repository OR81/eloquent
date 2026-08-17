<?php

namespace Or81\Eloquent;

use RuntimeException;

/**
 * Thrown by findOrFail() and firstOrFail() when nothing matched.
 */
class ModelNotFoundException extends RuntimeException
{
    protected string $model;

    /** @var mixed */
    protected $id;

    public function __construct(string $model, $id = null)
    {
        $this->model = $model;
        $this->id = $id;

        parent::__construct(
            $id === null
                ? "No query results for model [{$model}]."
                : "No query results for model [{$model}] with key [" . (is_scalar($id) ? $id : gettype($id)) . '].'
        );
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function getId()
    {
        return $this->id;
    }
}
