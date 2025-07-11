<?php

namespace Perk11\Viktor89\JoinQuiz;

use Longman\TelegramBot\Entities\Message;
use Longman\TelegramBot\Entities\PollAnswer;
use Longman\TelegramBot\Entities\PollOption;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Telegram;
use Perk11\Viktor89\Database;
use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\PreResponseProcessor\PreResponseProcessor;
use Perk11\Viktor89\ProcessingResult;
use Perk11\Viktor89\TelegramUserHelper;

class PollResponseProcessor
{
    private array $tutors = [
        'https://cloud.nw-sys.ru/index.php/s/z97QnXmfcM8QKDn/download',
        'https://cloud.nw-sys.ru/index.php/s/xqpNxq6Akk6SbDX/download',
        'https://cloud.nw-sys.ru/index.php/s/eCkqzWGqGAFRjMQ/download',
        'https://cloud.nw-sys.ru/index.php/s/wXeDasYwe44FaBx/download',
        'https://cloud.nw-sys.ru/index.php/s/7cNH875Dq2HpFWH/download',
        'https://cloud.nw-sys.ru/index.php/s/QatfCjzHn7ae5t2/download',
    ];

    public function __construct(private readonly Database $database)
    {
    }

    public function process(PollAnswer $pollAnswer): ProcessingResult
    {
        echo "Received poll answer from user " . $pollAnswer->getUser()->getId() . "\n";
        $kickQueueItem = $this->database->findKickQueueItemByPollId($pollAnswer->getPollId());
        if ($kickQueueItem === null) {
            echo "This is not a join poll, not doing anything\n";

            return new ProcessingResult(null, false);
        }
        if ($kickQueueItem->userId !== $pollAnswer->getUser()->getId()) {
            echo "Different user is responding to join poll, not doing anything\n";

            return new ProcessingResult(null, false);
        }
        $selectedResponseIds = $pollAnswer->getOptionIds();
        if (count($selectedResponseIds) > 1) {
            echo "Multiple quiz responses detected, this shouldn't be possible. Ignoring...";

            return new ProcessingResult(null, false);
        }
        $this->database->nullKickTime($pollAnswer->getPollId());
        echo "Deleting messages " . json_encode($kickQueueItem->messagesToDelete, JSON_THROW_ON_ERROR) . "\n";
        $deleteMessagesResult = Request::execute('deleteMessages', [
            'chat_id' => $kickQueueItem->chatId,
            'message_ids' => json_encode($kickQueueItem->messagesToDelete, JSON_THROW_ON_ERROR),
        ]);
        print_r($deleteMessagesResult);
        echo "\n";
        $message = new InternalMessage();
        $message->chatId = $kickQueueItem->chatId;
        $message->replyToMessageId = $kickQueueItem->joinMessageId;

        if ($selectedResponseIds[0] !== JoinQuizProcessor::CORRECT_ANSWER_INDEX) {
            $banRequest = Request::banChatMember([
                                                     'chat_id' => $message->chatId,
                                                     'user_id' => $pollAnswer->getUser()->getId(),
                                                 ]);

            if (!$banRequest->isOk()) {
                echo "Failed to ban user\n";
                print_r($banRequest);
                $message->messageText =  TelegramUserHelper::fullNameWithIdAndUserName($pollAnswer->getUser()) . " ответил(-а) неправильно!";

                return new ProcessingResult($message, false);
            }
            $unbanRequest = Request::unbanChatMember([
                                                         'chat_id' => $message->chatId,
                                                         'user_id' => $pollAnswer->getUser()->getId(),
                                                     ]);
            if (!$unbanRequest->isOk()) {
                echo "Failed to unban user\n";
                print_r($unbanRequest);
                $message->messageText = TelegramUserHelper::fullNameWithIdAndUserName($pollAnswer->getUser()) . " ответил(-а) неправильно и был забанен!";

                return new ProcessingResult($message, false);
            }

            $message->messageText = TelegramUserHelper::fullNameWithIdAndUserName($pollAnswer->getUser()) .
                " ответил(-а) неправильно и был удалён из чата";

            return new ProcessingResult($message, false);
        }

        echo "Answer was correct\n";
        Request::sendVideo([
                               'chat_id'             => $message->chatId,
                               'reply_to_message_id' => $kickQueueItem->joinMessageId,
                               'video'               => $this->tutors[array_rand($this->tutors)],
                               'caption'             => TelegramUserHelper::fullNameWithIdAndUserName($pollAnswer->getUser()).
                                   ' прошёл(-ла) проверку. Добро пожаловать!',
                           ]);
        return new ProcessingResult(null, true);
    }
}
