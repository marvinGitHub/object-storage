<?php

namespace melia\ObjectStorage\Strategy\Policy;

interface ChildWrite
{
    public const int ALWAYS = 1;
    public const int NEVER = 2;
    public const int IF_NOT_EXIST = 3;
    public const int CALLBACK = 4;
}