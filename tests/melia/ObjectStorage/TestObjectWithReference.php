<?php

namespace Tests\melia\ObjectStorage;

use melia\ObjectStorage\LazyLoadReference;

class TestObjectWithReference
{
    public TestObjectWithReference|LazyLoadReference|null $self;
}