<?php

namespace melia\ObjectStorage\Util\Maintenance;

/**
 * The state of a running maintenance task, stored as an object in the internal storage of the maintenance tasks.
 */
final class TaskState
{
    /**
     * @param string $task The name of the task, e.g. "rebuild-stubs".
     * @param array<string, mixed> $values The values of the task, e.g. counters and the cursor.
     */
    public function __construct(
        public string $task,
        public array  $values = [],
    )
    {
    }
}
