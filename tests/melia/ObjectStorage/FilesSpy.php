<?php

namespace Tests\melia\ObjectStorage;

use melia\ObjectStorage\File\FilesInterface;

/**
 * Spy wrapper that tracks the writes while executing the real operations.
 */
class FilesSpy implements FilesInterface
{
    private ?FilesInterface $realFiles;
    private array $methodCalls = [];

    public function __construct(?FilesInterface $realFiles = null)
    {
        $this->realFiles = $realFiles;
    }

    public function read(string $path): ?string
    {
        return $this->realFiles?->read($path);
    }

    public function write(string $path, string $contents): void
    {
        $this->methodCalls[] = [
            'method' => 'write',
            'filename' => $path,
            'data_length' => strlen($contents),
            'timestamp' => microtime(true)
        ];

        $this->realFiles?->write($path, $contents);
    }

    /**
     * Records one call per file of the group, so a group counts like the single writes it replaces.
     */
    public function writeGroup(array $files): void
    {
        foreach ($files as $filename => $data) {
            $this->methodCalls[] = [
                'method' => 'writeGroup',
                'filename' => $filename,
                'data_length' => strlen((string)$data),
                'timestamp' => microtime(true)
            ];
        }

        $this->realFiles?->writeGroup($files);
    }

    public function syncEnabled(): bool
    {
        return $this->realFiles?->syncEnabled() ?? false;
    }

    public function setSyncEnabled(bool $enabled): void
    {
        $this->realFiles?->setSyncEnabled($enabled);
    }

    public function recover(array $paths): bool
    {
        $this->methodCalls[] = [
            'method' => 'recover',
            'filename' => implode(',', $paths),
            'timestamp' => microtime(true)
        ];

        return $this->realFiles?->recover($paths) ?? false;
    }

    public function getMethodCalls(): array
    {
        return $this->methodCalls;
    }

    /**
     * Calls writing file contents, i.e. write() and each file of writeGroup().
     */
    public function getWriteCalls(): array
    {
        return array_values(array_filter($this->methodCalls, static fn($call) => in_array($call['method'], ['write', 'writeGroup'], true)));
    }

    public function getCallsForUuid(?string $uuid): array
    {
        if (null === $uuid) {
            return [];
        }
        return array_filter($this->methodCalls, fn($call) => str_contains($call['filename'], $uuid));
    }

    public function clearMethodCalls(): void
    {
        $this->methodCalls = [];
    }

    public function getWriteCallCount(): int
    {
        return count($this->getWriteCalls());
    }
}
