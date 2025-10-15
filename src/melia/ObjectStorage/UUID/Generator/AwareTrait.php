<?php

namespace melia\ObjectStorage\UUID\Generator;

trait AwareTrait
{
    protected ?GeneratorInterface $generator = null;

    public function getGenerator(): ?GeneratorInterface
    {
        return $this->generator;
    }

    public function setGenerator(?GeneratorInterface $generator): void
    {
        $this->generator = $generator;
    }
}