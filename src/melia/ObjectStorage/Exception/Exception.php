<?php

namespace melia\ObjectStorage\Exception;

class Exception extends \Exception
{
    const CODE_FAILURE_CRITERIA_MATCH = 1;
    const CODE_FAILURE_OBJECT_LOADING = 2;
    const CODE_FAILURE_OBJECT_MATCH = 3;
    const CODE_FAILURE_OBJECT_UNLOCK = 4;
    const CODE_FAILURE_OBJECT_ASSUMPTION = 5;
}