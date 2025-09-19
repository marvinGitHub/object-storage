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

    public function getMethodCalls(): array
    {
        return $this->methodCalls;
    }

    public function getCallsForUuid(string $uuid): array
    {
        return array_filter($this->methodCalls, fn($call) => str_contains($call['filename'], $uuid));
    }

    public function clearMethodCalls(): void
    {
        $this->methodCalls = [];
    }

    public function getAtomicWriteCallCount(): int
    {
        return count(array_filter($this->methodCalls, fn($call) => $call['method'] === 'atomicWrite'));
    }
}