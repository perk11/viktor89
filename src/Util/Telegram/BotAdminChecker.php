<?php

namespace Perk11\Viktor89\Util\Telegram;

use Longman\TelegramBot\Entities\User;
use Longman\TelegramBot\Request;
use Perk11\Viktor89\Database;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * Tells whether the bot itself is an administrator in a chat, used to gate
 * Telegram ephemeral messages (which are accepted only when the bot is an admin).
 *
 * Three-tier cache, cheapest first:
 *  1. per-process memory (fast path within a worker),
 *  2. the shared SQLite `chat_admin_status` table, so every worker process reuses
 *     the result and a chat is queried via getChatMember at most once per TTL,
 *  3. the Telegram API.
 *
 * Call setDatabase() once per worker to enable the shared (tier 2) cache.
 */
class BotAdminChecker
{
    /** Re-verify admin status via the API at most once per this many seconds. */
    private const CACHE_TTL_SECONDS = 86400;

    /** @var array<int, bool> chatId => is admin (per-process memory cache) */
    private static array $cache = [];
    private static ?int $botUserId = null;
    private static ?Database $database = null;
    private static ?LoggerInterface $logger = null;

    public static function setLogger(?LoggerInterface $logger): void
    {
        self::$logger = $logger;
    }

    public static function setDatabase(?Database $database): void
    {
        self::$database = $database;
    }

    public static function isBotAdminInChat(int $chatId): bool
    {
        if ($chatId >= 0) {
            return false;
        }
        if (array_key_exists($chatId, self::$cache)) {
            return self::$cache[$chatId];
        }

        $fromDatabase = self::readFromDatabase($chatId);
        if ($fromDatabase !== null) {
            return self::$cache[$chatId] = $fromDatabase;
        }

        return self::$cache[$chatId] = self::refreshFromTelegram($chatId);
    }

    private static function readFromDatabase(int $chatId): ?bool
    {
        if (self::$database === null) {
            return null;
        }
        $statement = self::$database->sqlite3Database->prepare(
            'SELECT is_admin FROM chat_admin_status WHERE chat_id = :chat_id AND checked_at >= :cutoff'
        );
        $statement->bindValue(':chat_id', $chatId, SQLITE3_INTEGER);
        $statement->bindValue(':cutoff', time() - self::CACHE_TTL_SECONDS, SQLITE3_INTEGER);
        $row = $statement->execute()->fetchArray(SQLITE3_ASSOC);

        return $row === false ? null : (bool) $row['is_admin'];
    }

    private static function refreshFromTelegram(int $chatId): bool
    {
        $isAdmin = self::fetchFromTelegram($chatId);
        if (self::$database !== null) {
            $statement = self::$database->sqlite3Database->prepare(
                'INSERT OR REPLACE INTO chat_admin_status (chat_id, is_admin, checked_at) VALUES (:chat_id, :is_admin, :checked_at)'
            );
            $statement->bindValue(':chat_id', $chatId, SQLITE3_INTEGER);
            $statement->bindValue(':is_admin', $isAdmin ? 1 : 0, SQLITE3_INTEGER);
            $statement->bindValue(':checked_at', time(), SQLITE3_INTEGER);
            $statement->execute();
        }

        return $isAdmin;
    }

    private static function fetchFromTelegram(int $chatId): bool
    {
        if (self::$botUserId === null) {
            self::$botUserId = self::$database?->botUserId ?? self::botUserIdViaGetMe();
        }
        if (self::$botUserId === 0) {
            return false;
        }

        try {
            $member = Request::getChatMember(['chat_id' => $chatId, 'user_id' => self::$botUserId]);
            $memberResult = $member->getResult();

            return $member->isOk()
                && $memberResult !== null
                && in_array($memberResult->getStatus(), ['administrator', 'creator'], true);
        } catch (\Throwable $e) {
            self::$logger?->log(LogLevel::ERROR, "Failed to check bot admin status in chat {$chatId}: {$e->getMessage()}");

            return false;
        }
    }

    private static function botUserIdViaGetMe(): int
    {
        $me = Request::getMe();
        $meResult = $me->getResult();

        return ($me->isOk() && $meResult instanceof User) ? $meResult->getId() : 0;
    }
}
