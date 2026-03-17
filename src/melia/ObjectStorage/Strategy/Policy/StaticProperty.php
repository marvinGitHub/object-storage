<?php

namespace melia\ObjectStorage\Strategy\Policy;

interface StaticProperty
{
    public const int NEVER = 0;
    public const int CALLBACK = 1;
    public const int ALWAYS = 2;
}