<?php

namespace melia\ObjectStorage\Journal\Exception;

/**
 * A journal holds a damaged entry before its end: not an interrupted append (which only ever damages the last
 * entry), but data changed or lost afterwards. The entries after it can't be trusted to be complete.
 */
class CorruptedJournalException extends JournalException
{
}
