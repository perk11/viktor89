PRAGMA journal_mode = wal;
CREATE TABLE IF NOT EXISTS `message` (
    `chat_id`           bigint,                       -- 'Unique chat identifier',
    `id`                bigint UNSIGNED,              -- 'Unique message identifier',
    `type`              varchar NOT NULL,             --  Message type as returned by Telegram
    `message_thread_id` bigint(20)      DEFAULT NULL, -- 'Unique identifier of a message thread to which the message belongs; for supergroups only',
    `user_id`           bigint    NULL,               -- 'Unique user identifier',
    `date`              timestamp NULL  DEFAULT NULL, -- 'Date the message was sent in timestamp format',
    `reply_to_message`  bigint UNSIGNED DEFAULT NULL, -- 'Message that this message is reply to',
    `username`          varchar,                      -- 'Message that this message is reply to',
    `message_text`      varchar,                           -- Message text,
    `photo_file_id`     varchar DEFAULT NULL               -- id of telegram file that can be used to download photo
);
CREATE INDEX IF NOT EXISTS idx_chat_id_id ON message (chat_id, id);
CREATE INDEX IF NOT EXISTS idx_date ON message (date);
CREATE INDEX IF NOT EXISTS idx_user ON message (user_id);
CREATE TABLE IF NOT EXISTS `user_preferences`
(
    `user_id` bigint PRIMARY KEY,
    `preferences` text --stored as JSON
);


CREATE TABLE IF NOT EXISTS `chat_summary`
(
    `chat_id` bigint,
    `date` timestamp,
    `summary` varchar
);
CREATE TABLE IF NOT EXISTS `quiz_question`
(
    `id` integer PRIMARY KEY AUTOINCREMENT,
    `namespace` text,
    `added_at` timestamp,
    `added_by_user_id` bigint,
    `added_by_user_name` text,
    `text` text,
    `explanation` text
);
CREATE TABLE IF NOT EXISTS `quiz_question_answer`
(
    `id` integer PRIMARY KEY AUTOINCREMENT,
    `question_id` bigint REFERENCES quiz_question(id),
    `text` text,
    `correct` boolean
);

CREATE TABLE IF NOT EXISTS `system_variable`
(
    `name` text UNIQUE,
    `value` text,
    `updated_at` timestamp
);

CREATE TABLE IF NOT EXISTS `kick_queue`
(
    `chat_id`  bigint NOT NULL,
    `user_id` bigint NOT NULL,
    `poll_id` bigint NOT NULL UNIQUE,
    `join_message_id` bigint NOT NULL,
    `messages_to_delete` string,
    `kick_time` timestamp
);
CREATE TABLE IF NOT EXISTS `saved_image`
(
    `id` integer PRIMARY KEY AUTOINCREMENT,
    `name` text NOT NULL UNIQUE,
    `filename` NOT NULL,
    `user_id` bigint NOT NULL,
    `created_at` timestamp NOT NULL
);
