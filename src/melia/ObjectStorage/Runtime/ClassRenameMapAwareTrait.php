<?php

namespace melia\ObjectStorage\Runtime;

trait ClassRenameMapAwareTrait
{
    protected ?ClassRenameMap $classRenameMap = null;

    public function getClassRenameMap(): ?ClassRenameMap
    {
        return $this->classRenameMap;
    }

    public function setClassRenameMap(ClassRenameMap $classRenameMap): void
    {
        $this->classRenameMap = $classRenameMap;
    }
}
