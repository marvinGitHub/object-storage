<?php

namespace melia\ObjectStorage\Logger;

use Throwable;

interface LoggerInterface
{
    public function log(Throwable $error);
}