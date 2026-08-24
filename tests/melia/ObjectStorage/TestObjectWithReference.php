<?php

namespace Tests\melia\ObjectStorage;

use melia\ObjectStorage\LazyLoadReference;

#[\AllowDynamicProperties]
class TestObjectWithReference
{
    public TestObjectWithReference|LazyLoadReference|null $self;
}