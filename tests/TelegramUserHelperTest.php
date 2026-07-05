<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Longman\TelegramBot\Entities\User;
use Perk11\Viktor89\TelegramUserHelper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TelegramUserHelper::class)]
class TelegramUserHelperTest extends TestCase
{
    private function createUser(array $data): User
    {
        return new User($data);
    }

    public function testFullNameWithUsername(): void
    {
        $user = $this->createUser([
            'id' => 123,
            'first_name' => 'John',
            'last_name' => 'Doe',
            'username' => 'johndoe',
        ]);

        $result = TelegramUserHelper::fullNameWithIdAndUserName($user);

        $this->assertSame('John Doe (@johndoe, id123)', $result);
    }

    public function testFullNameWithoutUsername(): void
    {
        $user = $this->createUser([
            'id' => 456,
            'first_name' => 'Jane',
            'last_name' => 'Smith',
        ]);

        $result = TelegramUserHelper::fullNameWithIdAndUserName($user);

        $this->assertSame('Jane Smith (id456)', $result);
    }

    public function testFullNameWithEmptyUsername(): void
    {
        $user = $this->createUser([
            'id' => 789,
            'first_name' => 'Bob',
            'username' => '',
        ]);

        $result = TelegramUserHelper::fullNameWithIdAndUserName($user);

        $this->assertSame('Bob (id789)', $result);
    }

    public function testFullNameWithOnlyFirstName(): void
    {
        $user = $this->createUser([
            'id' => 111,
            'first_name' => 'Alice',
        ]);

        $result = TelegramUserHelper::fullNameWithIdAndUserName($user);

        $this->assertSame('Alice (id111)', $result);
    }

    public function testFullNameWithTrailingSpaces(): void
    {
        $user = $this->createUser([
            'id' => 1,
            'first_name' => '  John  ',
            'last_name' => '  Doe  ',
            'username' => 'user',
        ]);

        $result = TelegramUserHelper::fullNameWithIdAndUserName($user);

        // trim() only removes leading/trailing whitespace, not internal spaces
        $this->assertStringContainsString('John', $result);
        $this->assertStringContainsString('Doe', $result);
        $this->assertStringContainsString('@user', $result);
        $this->assertStringContainsString('id1', $result);
        // Leading/trailing spaces are trimmed
        $this->assertStringStartsWith('John', $result);
        $this->assertStringEndsWith(')', $result);
    }

    public function testFullNameWithLargeUserId(): void
    {
        $user = $this->createUser([
            'id' => 999999999,
            'first_name' => 'Test',
            'last_name' => 'User',
            'username' => 'testuser',
        ]);

        $result = TelegramUserHelper::fullNameWithIdAndUserName($user);

        $this->assertSame('Test User (@testuser, id999999999)', $result);
    }
}
