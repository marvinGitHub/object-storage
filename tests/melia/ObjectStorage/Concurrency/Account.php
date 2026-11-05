<?php

namespace Tests\melia\ObjectStorage\Concurrency;

/**
 * An account with an email that has to be unique, see the scenario register-unique.
 */
class Account
{
    public function __construct(public string $email, public string $createdBy = '')
    {
    }
}
