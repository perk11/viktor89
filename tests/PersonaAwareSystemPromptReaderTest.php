<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\Database;
use Perk11\Viktor89\PersonaAwareSystemPromptReader;
use Perk11\Viktor89\PersonaHelper;
use Perk11\Viktor89\UserPreferenceReaderInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PersonaAwareSystemPromptReader::class)]
class PersonaAwareSystemPromptReaderTest extends TestCase
{
    private string $dbName = 'test_persona_reader.db';
    private Database $database;

    protected function setUp(): void
    {
        $fullPath = __DIR__ . '/../data/' . $this->dbName;
        if (file_exists($fullPath)) {
            unlink($fullPath);
        }
        $this->database = new Database(123, $this->dbName);
    }

    protected function tearDown(): void
    {
        $this->database->sqlite3Database->close();
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

    public function testNoPersonaNoSystemPromptReturnsNull(): void
    {
        $this->assertNull($this->createReader(null)->getCurrentPreferenceValue(111));
    }

    public function testPersonaOnly(): void
    {
        $this->database->addPersona('pirate', 'You are a pirate.', 999, 'Bob');
        $this->database->writeUserPreference(111, PersonaHelper::PERSONA_PREFERENCE, 'pirate');

        $this->assertSame('You are a pirate.', $this->createReader(null)->getCurrentPreferenceValue(111));
    }

    public function testSystemPromptOnly(): void
    {
        $this->assertSame('Be concise.', $this->createReader('Be concise.')->getCurrentPreferenceValue(111));
    }

    public function testPersonaPromptIsPrependedBeforeSystemPrompt(): void
    {
        $this->database->addPersona('pirate', 'You are a pirate.', 999, 'Bob');
        $this->database->writeUserPreference(111, PersonaHelper::PERSONA_PREFERENCE, 'pirate');

        $value = $this->createReader('Be concise.')->getCurrentPreferenceValue(111);

        $this->assertStringContainsString('You are a pirate.', $value);
        $this->assertStringContainsString('Be concise.', $value);
        $this->assertLessThan(
            strpos($value, 'Be concise.'),
            strpos($value, 'You are a pirate.'),
            'Persona prompt must come before the /systemprompt value'
        );
    }

    public function testDefaultPersonaIsIgnored(): void
    {
        $this->database->writeUserPreference(111, PersonaHelper::PERSONA_PREFERENCE, 'Default');

        $this->assertSame('Be concise.', $this->createReader('Be concise.')->getCurrentPreferenceValue(111));
    }

    public function testDeletedPersonaIsIgnored(): void
    {
        $this->database->addPersona('pirate', 'You are a pirate.', 999, 'Bob');
        $this->database->writeUserPreference(111, PersonaHelper::PERSONA_PREFERENCE, 'pirate');
        $this->database->deletePersonaByName('pirate');

        $this->assertNull($this->createReader(null)->getCurrentPreferenceValue(111));
    }

    private function createReader(?string $systemPromptValue): PersonaAwareSystemPromptReader
    {
        $systemPromptProcessor = $this->createMock(UserPreferenceReaderInterface::class);
        $systemPromptProcessor->method('getCurrentPreferenceValue')->willReturn($systemPromptValue);

        return new PersonaAwareSystemPromptReader($this->database, $systemPromptProcessor);
    }
}
