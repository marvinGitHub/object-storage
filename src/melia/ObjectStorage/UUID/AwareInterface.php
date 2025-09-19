<?php

namespace melia\ObjectStorage\UUID;

interface AwareInterface
{

    public function getUUID(): ?string;

    public function setUUID(?string $uuid): void;
}