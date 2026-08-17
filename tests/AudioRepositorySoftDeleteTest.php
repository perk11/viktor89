<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\Audio\AudioRepository;
use Perk11\Viktor89\Database;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AudioRepository::class)]
class AudioRepositorySoftDeleteTest extends TestCase
{
    private string $dbName = 'test_audio_soft_delete.db';
    private Database $database;
    private AudioRepository $repository;

    protected function setUp(): void
    {
        $this->removeDatabaseFile();
        $this->database = new Database(123, $this->dbName);
        $this->repository = new AudioRepository($this->database->sqlite3Database);
    }

    protected function tearDown(): void
    {
        $this->database->sqlite3Database->close();
        $this->removeDatabaseFile();
    }

    public function testMarkDeletedByNameReturnsFalseForUnknownName(): void
    {
        $this->assertFalse($this->repository->markDeletedByName('ghost', 111));
    }

    public function testMarkDeletedByNameOnlyDeletesForOwner(): void
    {
        $this->insertAudio('песня', 111);

        $this->assertFalse($this->repository->markDeletedByName('песня', 222));
        $this->assertNotNull($this->repository->findByName('песня'));

        $this->assertTrue($this->repository->markDeletedByName('песня', 111));
        $this->assertNull($this->repository->findByName('песня'));
        $deleted = $this->repository->findDeletedByName('песня');
        $this->assertNotNull($deleted);
        $this->assertSame(111, $deleted->userId);
    }

    public function testRestoreDeletedByNameRestores(): void
    {
        $this->insertAudio('песня', 111);
        $this->repository->markDeletedByName('песня', 111);

        $this->assertTrue($this->repository->restoreDeletedByName('песня', 111));
        $this->assertNotNull($this->repository->findByName('песня'));
        $this->assertNull($this->repository->findDeletedByName('песня'));

        $this->assertFalse($this->repository->restoreDeletedByName('песня', 111));
    }

    public function testRestoreDeletedByNameRejectedForOtherUser(): void
    {
        $this->insertAudio('песня', 111);
        $this->repository->markDeletedByName('песня', 111);

        $this->assertFalse($this->repository->restoreDeletedByName('песня', 222));
        $this->assertNotNull($this->repository->findDeletedByName('песня'));
    }

    public function testRetrieveReturnsNullForDeleted(): void
    {
        $this->insertAudio('песня', 111);
        $this->repository->markDeletedByName('песня', 111);

        $this->assertNull($this->repository->retrieve('песня'));
    }

    public function testFindAllPublicAudiosExcludesDeleted(): void
    {
        $this->insertAudio('песня', 111);
        $this->insertAudio('хит', 111);
        $this->repository->markDeletedByName('песня', 111);

        $names = array_map(
            static fn($audio) => $audio->name,
            $this->repository->findAllPublicAudios(),
        );
        $this->assertSame(['хит'], $names);
    }

    public function testSaveReusesNameOfSoftDeletedEntry(): void
    {
        $this->insertAudio('песня', 111);
        $this->repository->markDeletedByName('песня', 111);

        $this->assertTrue($this->repository->save('песня', 222, 'new-contents', 'ogg'));

        $live = $this->repository->findByName('песня');
        $this->assertNotNull($live);
        $this->assertSame(222, $live->userId);
        $this->assertNull($this->repository->findDeletedByName('песня'));
        $this->assertFalse($this->repository->restoreDeletedByName('песня', 111));

        $filePath = __DIR__ . '/../data/audios/' . $live->filename;
        $this->assertFileExists($filePath);
        unlink($filePath);
    }

    private function insertAudio(string $name, int $userId): void
    {
        $stmt = $this->database->sqlite3Database->prepare(
            'INSERT INTO saved_audio (name, filename, user_id, created_at) VALUES (:name, :filename, :user_id, CURRENT_TIMESTAMP)'
        );
        $stmt->bindValue(':name', $name);
        $stmt->bindValue(':filename', 'soft-delete-test.ogg');
        $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        $stmt->execute();
    }

    private function removeDatabaseFile(): void
    {
        $fullPath = __DIR__ . '/../data/' . $this->dbName;
        if (file_exists($fullPath)) {
            unlink($fullPath);
        }
        foreach (['-wal', '-shm'] as $suffix) {
            if (file_exists($fullPath . $suffix)) {
                unlink($fullPath . $suffix);
            }
        }
    }
}
