<?php

namespace Tests\melia\ObjectStorage;

use melia\ObjectStorage\File\WriterInterface;

/**
 * Spy wrapper that tracks method calls while executing real operations
 */
class WriterSpy implements WriterInterface
{
    private ?WriterInterface $realWriter;
    private array $methodCalls = [];

    public function __construct(?WriterInterface $realWriter = null)
    {
        $this->realWriter = $realWriter;
    }

    public function atomicWrite(string $filename, ?string $data = null): void
    {
        $this->methodCalls[] = [
            'method' => 'atomicWrite',
            'filename' => $filename,
            'data_length' => strlen($data),
            'timestamp' => microtime(true)
        ];

        $this->realWriter?->atomicWrite($filename, $data);
    }

    /**
     * Records one call per file of the group, so a group counts like the single writes it replaces.
     */
    public function atomicWriteAll(array $files): void
    {
        foreach ($files as $filename => $data) {
            $this->methodCalls[] = [
                'method' => 'atomicWriteAll',
                'filename' => $filename,
                'data_length' => strlen((string)$data),
                'timestamp' => microtime(true)
            ];
        }

        $this->realWriter?->atomicWriteAll($files);
    }

    public function syncEnabled(): bool
    {
        return $this->realWriter?->syncEnabled() ?? false;
    }

    public function setSyncEnabled(bool $enabled): void
    {
        $this->realWriter?->setSyncEnabled($enabled);
    }

    public function recover(array $filenames): bool
    {
        $this->methodCalls[] = [
            'method' => 'recover',
            'filename' => implode(',', $filenames),
            'timestamp' => microtime(true)
        ];

        return $this->realWriter?->recover($filenames) ?? false;
    }

    public function createEmptyFile(string $filename): void
    {
        $this->methodCalls[] = [
            'method' => 'createEmptyFile',
            'filename' => $filename,
            'timestamp' => microtime(true)
        ];

        $this->realWriter?->createEmptyFile($filename);
    }

    public function getMethodCalls(): array
    {
        return $this->methodCalls;
    }

    /**
     * Calls writing file contents, i.e. atomicWrite() and each file of atomicWriteAll().
     */
    public function getAtomicWriteCalls(): array
    {
        return array_values(array_filter($this->methodCalls, static fn($call) => in_array($call['method'], ['atomicWrite', 'atomicWriteAll'], true)));
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

    public function getAtomicWriteCallCount(): int
    {
        return count($this->getAtomicWriteCalls());
    }
}