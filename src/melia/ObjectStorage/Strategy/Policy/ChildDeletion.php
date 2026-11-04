<?php

namespace melia\ObjectStorage\Strategy\Policy;

/**
 * Decides whether deleting an object also deletes the objects it references.
 */
interface ChildDeletion
{
    /** referenced objects are deleted along with the referencing object, recursively */
    public const int ALWAYS = 1;

    /** referenced objects are kept (default) */
    public const int NEVER = 2;

    /** referenced objects are deleted if shouldDeleteChild() of the strategy returns true */
    public const int CALLBACK = 3;
}
