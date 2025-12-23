<?php

namespace melia\ObjectStorage\UUID\Generator;

interface AwareInterface
{
    public function setGenerator(?GeneratorInterface $generator = null): void;

    public function getGenerator(): ?GeneratorInterface;
}