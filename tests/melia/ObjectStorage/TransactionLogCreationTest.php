<?php

namespace Tests\melia\ObjectStorage;

use melia\ObjectStorage\Transaction;
use melia\ObjectStorage\ObjectStorage;
use melia\ObjectStorage\Exception\TransactionException;

class TransactionLogCreationTest extends TestCase
{
    public function testCreatesTransactionLogOnBegin(): void
    {
        // Arrange: minimal ObjectStorage double
        $storage = $this->createStorage();

        $txn = new Transaction($storage);

        // Act
        $txn->begin();

        // Assert: find created .txn file and check basic contents
        $txnId = $txn->getTransactionId();
        $logPath = $txn->getTransactionLogPath();

        $this->assertFileExists($logPath, 'Transaction log file should be created on begin');

        $raw = file_get_contents($logPath);
        $this->assertNotFalse($raw, 'Transaction log file should be readable');

        $data = @unserialize($raw);
        $this->assertIsArray($data, 'Transaction log should be serialized array');
        $this->assertArrayHasKey('transaction_id', $data);
        $this->assertArrayHasKey('start_time', $data);
        $this->assertArrayHasKey('status', $data);
        $this->assertArrayHasKey('operations', $data);

        $this->assertSame($txnId, $data['transaction_id']);
        $this->assertSame('active', $data['status']);
        $this->assertIsArray($data['operations']);
        $this->assertEmpty($data['operations']);

        // Cleanup by committing or rolling back to remove log
        $this->assertTrue($txn->commit());
        $this->assertFileDoesNotExist($logPath, 'Transaction log should be removed on cleanup');
    }
}