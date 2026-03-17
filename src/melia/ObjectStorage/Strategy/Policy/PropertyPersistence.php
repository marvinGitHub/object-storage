<?php

namespace melia\ObjectStorage\Strategy\Policy;

interface PropertyPersistence
{
    public const int ALWAYS = 1;
    public const int CALLBACK = 2;
}