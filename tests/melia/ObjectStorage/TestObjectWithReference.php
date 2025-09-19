<?php

namespace Tests\melia\ObjectStorage;

class TestObjectWithReference
{
    public TestObjectWithReference|\melia\ObjectStorage\LazyLoadReference|null $self;
}