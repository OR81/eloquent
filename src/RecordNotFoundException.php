<?php

namespace Or81\Eloquent;

use RuntimeException;

/**
 * Thrown by the query builder's findOrFail(), firstOrFail() and sole() when
 * nothing matched. Models throw ModelNotFoundException, which extends this.
 */
class RecordNotFoundException extends RuntimeException
{
    protected string $subject;

    /** @var mixed */
    protected $id;

    public function __construct(string $subject, $id = null, ?string $message = null)
    {
        $this->subject = $subject;
        $this->id = $id;

        parent::__construct($message ?? (
            $id === null
                ? "No records found for [{$subject}]."
                : "No record found for [{$subject}] with key [" . (is_scalar($id) ? $id : gettype($id)) . '].'
        ));
    }

    /**
     * The table or model the query was against.
     */
    public function getSubject(): string
    {
        return $this->subject;
    }

    public function getId()
    {
        return $this->id;
    }
}
