<?php

namespace Tests\melia\ObjectStorage\Path;

class Member
{
    /**
     * @param list<Member> $friends
     * @param array<string, Member> $friendsByName
     * @param list<string> $emails
     */
    public function __construct(
        public string $name,
        public array  $friends = [],
        public array  $friendsByName = [],
        public array  $emails = [],
    )
    {
    }
}
