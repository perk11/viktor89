<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\Database;
use Perk11\Viktor89\ImageGeneration\ImageRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ImageRepository::class)]
class ImageRepositorySoftDeleteTest extends TestCase
{
    private string $dbName = 'test_image_soft_delete.db';
    private Database $database;
    private ImageRepository $repository;

    protected function setUp(): void
    {
        $this->removeDatabaseFile();
        $this->database = new Database(123, $this->dbName);
        $this->repository = new ImageRepository($this->database->sqlite3Database);
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
        $this->insertImage('котик', 111);

        $this->assertFalse($this->repository->markDeletedByName('котик', 222));
        $this->assertNotNull($this->repository->findByName('котик'));

        $this->assertTrue($this->repository->markDeletedByName('котик', 111));
        $this->assertNull($this->repository->findByName('котик'));
        $deleted = $this->repository->findDeletedByName('котик');
        $this->assertNotNull($deleted);
        $this->assertSame(111, $deleted->userId);
    }

    public function testRestoreDeletedByNameRestores(): void
    {
        $this->insertImage('котик', 111);
        $this->repository->markDeletedByName('котик', 111);

        $this->assertTrue($this->repository->restoreDeletedByName('котик', 111));
        $this->assertNotNull($this->repository->findByName('котик'));
        $this->assertNull($this->repository->findDeletedByName('котик'));

        $this->assertFalse($this->repository->restoreDeletedByName('котик', 111));
    }

    public function testRestoreDeletedByNameRejectedForOtherUser(): void
    {
        $this->insertImage('котик', 111);
        $this->repository->markDeletedByName('котик', 111);

        $this->assertFalse($this->repository->restoreDeletedByName('котик', 222));
        $this->assertNotNull($this->repository->findDeletedByName('котик'));
    }

    public function testRetrieveReturnsNullForDeleted(): void
    {
        $this->insertImage('котик', 111);
        $this->repository->markDeletedByName('котик', 111);

        $this->assertNull($this->repository->retrieve('котик'));
    }

    public function testFindAllPublicImagesExcludesDeleted(): void
    {
        $this->insertImage('котик', 111);
        $this->insertImage('пёсик', 111);
        $this->repository->markDeletedByName('котик', 111);

        $names = array_map(
            static fn($image) => $image->name,
            $this->repository->findAllPublicImages(),
        );
        $this->assertSame(['пёсик'], $names);
    }

    public function testSaveReusesNameOfSoftDeletedEntry(): void
    {
        $this->insertImage('котик', 111);
        $this->repository->markDeletedByName('котик', 111);

        $this->assertTrue($this->repository->save('котик', 222, 'new-contents'));

        $live = $this->repository->findByName('котик');
        $this->assertNotNull($live);
        $this->assertSame(222, $live->userId);
        $this->assertNull($this->repository->findDeletedByName('котик'));
        $this->assertFalse($this->repository->restoreDeletedByName('котик', 111));

        $filePath = __DIR__ . '/../data/images/' . $live->filename;
        $this->assertFileExists($filePath);
        unlink($filePath);
    }

    private function insertImage(string $name, int $userId): void
    {
        $stmt = $this->database->sqlite3Database->prepare(
            'INSERT INTO saved_image (name, filename, user_id, created_at) VALUES (:name, :filename, :user_id, CURRENT_TIMESTAMP)'
        );
        $stmt->bindValue(':name', $name);
        $stmt->bindValue(':filename', 'soft-delete-test.jpg');
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
