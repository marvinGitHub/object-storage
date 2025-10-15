<?php

namespace melia\ObjectStorage\UUID\Generator;

interface GeneratorInterface
{
    public function generate(): string;
}