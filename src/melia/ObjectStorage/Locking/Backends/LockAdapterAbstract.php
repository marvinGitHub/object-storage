<?php

namespace melia\ObjectStorage\Locking\Backends;

use melia\ObjectStorage\Locking\LockAdapterInterface;
use melia\ObjectStorage\State\StateHandlerAwareTrait;

abstract class LockAdapterAbstract implements LockAdapterInterface
{
    use StateHandlerAwareTrait;
}