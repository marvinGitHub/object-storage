<?php

namespace melia\ObjectStorage\Strategy\Policy;

interface PropertyHydration
{
    public const int ALWAYS = 1;
    public const int CALLBACK = 2;
}