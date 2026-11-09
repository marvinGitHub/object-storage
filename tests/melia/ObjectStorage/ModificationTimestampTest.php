<?php

namespace Tests\melia\ObjectStorage;

use DateTimeImmutable;
use melia\ObjectStorage\Cli\Application;
use melia\ObjectStorage\Event\Dispatcher;
use melia\ObjectStorage\Event\Events;
use melia\ObjectStorage\Exception\MetadataNotFoundException;
use melia\ObjectStorage\Metadata\Metadata;
use melia\ObjectStorage\Query\Exception\QueryException;
use melia\ObjectStorage\Query\Oql;
use stdClass;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * When objects were created and modified: the metadata, touch(), OQL (CREATED(), MODIFIED()) and the CLI.
 */
class ModificationTimestampTest extends TestCase
{
    private function note(string $text): stdClass
    {
        $note = new stdClass();
        $note->text = $text;
        return $note;
    }

    private function metadata(string $uuid): Metadata
    {
        $this->storage->clearCache();
        return $this->storage->loadMetadata($uuid);
    }

    public function testStoringSetsBothAndWritingTheDataSetsModification(): void
    {
        $before = microtime(true);
        $note = $this->note('first');
        $uuid = $this->storage->store($note);
        $created = $this->metadata($uuid)->getTimestampCreation();
        $this->assertGreaterThanOrEqual($before, $created);
        $this->assertSame($created, $this->metadata($uuid)->getTimestampModification());

        // stored again unchanged: nothing is written
        usleep(20000);
        $this->storage->store($note, $uuid);
        $this->assertSame($created, $this->metadata($uuid)->getTimestampModification());

        // changed: modified, created stays
        $note->text = 'second';
        $this->storage->store($note, $uuid);
        $metadata = $this->metadata($uuid);
        $this->assertSame($created, $metadata->getTimestampCreation(), 'created when first stored');
        $this->assertGreaterThan($created, $metadata->getTimestampModification());
        $this->assertSame(2, $metadata->getRevision());

        // a change of the lifetime isn't a modification
        $modified = $metadata->getTimestampModification();
        usleep(20000);
        $this->storage->setLifetime($uuid, 3600);
        $this->assertSame($modified, $this->metadata($uuid)->getTimestampModification());
        $this->assertSame($created, $this->metadata($uuid)->getTimestampCreation());

        $this->assertEqualsWithDelta($created, (float)$this->storage->getCreatedAt($uuid)->format('U.u'), 0.000001);
        $this->assertEqualsWithDelta($modified, (float)$this->storage->getModifiedAt($uuid)->format('U.u'), 0.000001);
        $this->assertSame('UTC', $this->storage->getModifiedAt($uuid)->getTimezone()->getName());
        $this->assertNull($this->storage->getModifiedAt('00000000-0000-4000-8000-000000000000'));
    }

    public function testTouch(): void
    {
        $uuid = $this->storage->store($this->note('a'));
        $before = $this->metadata($uuid);
        $data = file_get_contents($this->storage->getFilePathData($uuid));

        $dispatcher = new Dispatcher();
        $touched = [];
        $dispatcher->addListener(Events::TOUCHED, function ($context) use (&$touched): void {
            $touched[] = $context->getUuid();
        });
        $this->storage->setEventDispatcher($dispatcher);

        usleep(20000);
        $this->storage->touch($uuid);
        $after = $this->metadata($uuid);
        $this->assertGreaterThan($before->getTimestampModification(), $after->getTimestampModification());
        $this->assertSame($before->getTimestampCreation(), $after->getTimestampCreation());
        $this->assertSame($before->getRevision(), $after->getRevision(), 'the data is unchanged');
        $this->assertSame($before->getChecksum(), $after->getChecksum());
        $this->assertSame($data, file_get_contents($this->storage->getFilePathData($uuid)));
        $this->assertSame('a', $this->storage->load($uuid)->text);
        $this->assertSame([$uuid], $touched);

        // to a point in time
        $this->storage->touch($uuid, new DateTimeImmutable('2026-01-02T03:04:05.5+01:00'));
        $this->assertSame('2026-01-02T02:04:05.500000Z', $this->storage->getModifiedAt($uuid)->format('Y-m-d\TH:i:s.u\Z'));

        // expired objects too, without extending the lifetime
        $this->storage->setLifetime($uuid, -1);
        $this->storage->touch($uuid);
        $this->assertTrue($this->storage->expired($uuid));
    }

    public function testTouchingAnObjectThatDoesntExist(): void
    {
        $this->expectException(MetadataNotFoundException::class);
        $this->storage->touch('00000000-0000-4000-8000-000000000000');
    }

    public function testMetadataWrittenBeforeHasTheCreationTimestamp(): void
    {
        $metadata = Metadata::createFromArray(['className' => 'X', 'uuid' => '00000000-0000-4000-8000-000000000001', 'timestampCreation' => 1000.5, 'checksum' => str_repeat('a', 64)]);
        $this->assertSame(1000.5, $metadata->getTimestampModification());
        $this->assertSame(1000.5, $metadata->jsonSerialize()['timestampModification']);
        $metadata->setTimestampModification(2000);
        $this->assertSame(2000.0, Metadata::createFromArray($metadata->jsonSerialize())->getTimestampModification());
    }

    public function testOql(): void
    {
        $old = $this->storage->store($this->note('old'));
        $new = $this->storage->store($this->note('new'));
        $this->storage->touch($old, new DateTimeImmutable('2020-01-01T00:00:00Z'));
        $oql = new Oql($this->storage);

        $this->assertSame([['old', '2020-01-01T00:00:00.000000Z']], $oql->query("SELECT text, MODIFIED() FROM stdClass WHERE text = 'old'")->rows);
        $this->assertSame([['new']], $oql->query("SELECT text FROM stdClass WHERE MODIFIED() > DATE_SUB(NOW(), 1, 'DAY')")->rows);
        $this->assertSame([['old'], ['new']], $oql->query('SELECT n.text FROM stdClass n ORDER BY MODIFIED(n)')->rows);
        $this->assertSame([[2]], $oql->query("SELECT COUNT(*) FROM stdClass WHERE CREATED() > '2025-01-01'")->rows, 'created stays');
        $this->assertSame([['old', 2020]], $oql->query("SELECT text, YEAR(MODIFIED()) FROM stdClass WHERE YEAR(MODIFIED()) < YEAR(CREATED())")->rows);

        // UPDATE writes the data: modified; INSERT: both
        $oql->query("UPDATE stdClass SET text = 'older' WHERE UUID = ?", [$old]);
        $this->assertGreaterThan(time() - 60, $this->storage->getModifiedAt($old)->getTimestamp());
        $this->assertSame($this->metadata($new)->getTimestampCreation(), $this->metadata($new)->getTimestampCreation());

        // in joins, by alias
        $rows = $oql->query("SELECT a.text, b.text FROM stdClass a JOIN stdClass b ON MODIFIED(b) >= MODIFIED(a) AND a != b ORDER BY a.text")->rows;
        $this->assertCount(1, $rows);

        foreach ([
                     'SELECT MODIFIED(a.text) FROM stdClass a' => 'takes the alias of a source',
                     'SELECT MODIFIED(x) FROM stdClass a' => 'takes the alias of a source',
                     'SELECT MODIFIED() FROM stdClass a JOIN stdClass b ON a = b' => 'needs the alias of its source',
                     "INSERT INTO stdClass (text) VALUES (CREATED())" => "isn't possible here",
                 ] as $query => $message) {
            try {
                $oql->query($query);
                $this->fail($query);
            } catch (QueryException $e) {
                $this->assertStringContainsString($message, $e->getMessage(), $query);
            }
        }
    }

    public function testCli(): void
    {
        $uuid = $this->storage->store($this->note('cli'));
        $tester = new CommandTester((new Application())->find('touch'));
        $tester->execute(['uuid' => $uuid, '--at' => '2026-03-01 12:00', '--json' => true, '--dir' => $this->storage->getStorageDir(), '--configurations' => $this->storageDirs[] = $this->createTemporaryDirectory()]);
        $this->assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        $json = json_decode($tester->getDisplay(), true);
        $this->assertSame('2026-03-01T12:00:00.000+00:00', $json['modifiedAt']);
        $this->storage->clearCache();
        $this->assertSame('2026-03-01 12:00:00', $this->storage->getModifiedAt($uuid)->format('Y-m-d H:i:s'));
    }
}
