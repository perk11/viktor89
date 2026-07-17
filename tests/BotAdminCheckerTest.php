<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\Database;
use Perk11\Viktor89\Test\Support\TelegramRecordingTrait;
use Perk11\Viktor89\Util\Telegram\BotAdminChecker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Support/IntegrationTestSupport.php';

#[CoversClass(BotAdminChecker::class)]
class BotAdminCheckerTest extends TestCase
{
    use TelegramRecordingTrait;

    private const TEMP_DB_NAME = '_botadmin_test.sqlite';

    protected function tearDown(): void
    {
        BotAdminChecker::setDatabase(null);
        foreach (glob(__DIR__ . '/../data/' . self::TEMP_DB_NAME . '*') as $file) {
            @unlink($file);
        }
    }

    public function testReturnsTrueWhenBotIsAdministrator(): void
    {
        $this->installRecordingTelegramClient();

        $this->assertTrue(BotAdminChecker::isBotAdminInChat(-100300));
    }

    public function testReturnsFalseWhenBotIsRegularMember(): void
    {
        $this->installRecordingTelegramClient();
        $this->telegramResponseOverride = static function (string $action): ?array {
            if ($action === 'getChatMember') {
                return ['ok' => true, 'result' => ['user' => ['id' => Support\TELEGRAM_TEST_BOT_ID], 'status' => 'member']];
            }

            return null;
        };

        $this->assertFalse(BotAdminChecker::isBotAdminInChat(-100301));
    }

    public function testReturnsTrueForChatOwner(): void
    {
        $this->installRecordingTelegramClient();
        $this->telegramResponseOverride = static function (string $action): ?array {
            if ($action === 'getChatMember') {
                return ['ok' => true, 'result' => ['user' => ['id' => Support\TELEGRAM_TEST_BOT_ID], 'status' => 'creator']];
            }

            return null;
        };

        $this->assertTrue(BotAdminChecker::isBotAdminInChat(-100302));
    }

    public function testReturnsFalseForPrivateChatWithoutApiCall(): void
    {
        $this->installRecordingTelegramClient();

        $this->assertFalse(BotAdminChecker::isBotAdminInChat(55555));
        $this->assertSame(
            [],
            array_filter(
                $this->recordedActions(),
                static fn(string $action): bool => $action === 'getChatMember',
            ),
            'private chats must short-circuit without querying membership',
        );
    }

    public function testCachesResultPerChat(): void
    {
        $this->installRecordingTelegramClient();
        $this->telegramResponseOverride = static function (string $action): ?array {
            if ($action === 'getChatMember') {
                return ['ok' => true, 'result' => ['user' => ['id' => Support\TELEGRAM_TEST_BOT_ID], 'status' => 'member']];
            }

            return null;
        };

        $this->assertFalse(BotAdminChecker::isBotAdminInChat(-100303));

        // Change what the API would return; the cached result must persist.
        $this->telegramResponseOverride = static function (string $action): ?array {
            if ($action === 'getChatMember') {
                return ['ok' => true, 'result' => ['user' => ['id' => Support\TELEGRAM_TEST_BOT_ID], 'status' => 'administrator']];
            }

            return null;
        };
        $this->assertFalse(BotAdminChecker::isBotAdminInChat(-100303), 'cached value must be reused');

        $membershipQueries = array_filter(
            $this->recordedActions(),
            static fn(string $action): bool => $action === 'getChatMember',
        );
        $this->assertCount(1, $membershipQueries, 'membership must be fetched only once per chat');
    }

    public function testReadsSharedStatusFromDatabaseWithoutCallingTelegram(): void
    {
        $database = new Database(Support\TELEGRAM_TEST_BOT_ID, self::TEMP_DB_NAME);
        BotAdminChecker::setDatabase($database);
        $database->sqlite3Database->exec(
            'INSERT INTO chat_admin_status (chat_id, is_admin, checked_at) VALUES (-100400, 1, ' . time() . ')'
        );
        $this->installRecordingTelegramClient();

        $this->assertTrue(BotAdminChecker::isBotAdminInChat(-100400));

        $apiCalls = array_filter(
            $this->recordedActions(),
            static fn(string $action): bool => in_array($action, ['getChatMember', 'getMe'], true),
        );
        $this->assertSame([], $apiCalls, 'a fresh DB cache hit must skip the Telegram API entirely');
    }

    public function testPersistsNewlyCheckedStatusToDatabase(): void
    {
        $database = new Database(Support\TELEGRAM_TEST_BOT_ID, self::TEMP_DB_NAME);
        BotAdminChecker::setDatabase($database);
        $this->installRecordingTelegramClient(); // getChatMember defaults to administrator

        $this->assertTrue(BotAdminChecker::isBotAdminInChat(-100401));

        $row = $database->sqlite3Database
            ->query('SELECT is_admin FROM chat_admin_status WHERE chat_id = -100401')
            ->fetchArray(SQLITE3_ASSOC);
        $this->assertSame(1, (int) $row['is_admin'], 'the API result must be persisted for other workers');
    }

    public function testRequeriesWhenDatabaseEntryIsStale(): void
    {
        $database = new Database(Support\TELEGRAM_TEST_BOT_ID, self::TEMP_DB_NAME);
        BotAdminChecker::setDatabase($database);
        // A stale "admin" entry must be ignored and re-checked.
        $database->sqlite3Database->exec(
            'INSERT INTO chat_admin_status (chat_id, is_admin, checked_at) VALUES (-100402, 1, ' . (time() - 100000) . ')'
        );
        $this->installRecordingTelegramClient();
        $this->telegramResponseOverride = static function (string $action): ?array {
            if ($action === 'getChatMember') {
                return ['ok' => true, 'result' => ['user' => ['id' => Support\TELEGRAM_TEST_BOT_ID], 'status' => 'member']];
            }

            return null;
        };

        $this->assertFalse(BotAdminChecker::isBotAdminInChat(-100402));

        $row = $database->sqlite3Database
            ->query('SELECT is_admin FROM chat_admin_status WHERE chat_id = -100402')
            ->fetchArray(SQLITE3_ASSOC);
        $this->assertSame(0, (int) $row['is_admin'], 'the stale entry must be refreshed with the new API result');
    }
}
