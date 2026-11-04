<?php

namespace melia\ObjectStorage\Event\Context;

class ObjectPersistenceContext extends Context
{
    public function __construct(
        ?string           $uuid,
        protected object  $object,
        protected ?object $previousObject = null,
        protected ?int    $revision = null,
        protected ?int    $previousRevision = null
    )
    {
        parent::__construct($uuid);
    }

    public function getObject(): object
    {
        return $this->object;
    }

    public function getPreviousObject(): ?object
    {
        return $this->previousObject;
    }

    /**
     * The revision the data is written with (OBJECT_SAVED, OBJECT_WRITE_FAILED); null for other events.
     */
    public function getRevision(): ?int
    {
        return $this->revision;
    }

    /**
     * The revision stored before the data was written (OBJECT_SAVED, OBJECT_WRITE_FAILED);
     * null for new objects and for other events.
     */
    public function getPreviousRevision(): ?int
    {
        return $this->previousRevision;
    }
}
