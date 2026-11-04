<?php

namespace melia\ObjectStorage\Search\Exception;

use melia\ObjectStorage\Search\Index\Plan;

/**
 * No search index of a class can answer a search, e.g. a filter uses a field no index can filter by.
 */
class NoSuitableIndexException extends SearchException
{
    private ?Plan $plan = null;

    public static function forPlan(Plan $plan): self
    {
        $exception = new self($plan->describe());
        $exception->plan = $plan;
        return $exception;
    }

    /**
     * The considered indexes and why they can't answer the search.
     */
    public function getPlan(): ?Plan
    {
        return $this->plan;
    }
}
