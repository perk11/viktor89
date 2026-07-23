<?php

namespace Perk11\Viktor89\JoinQuiz;

use Longman\TelegramBot\Entities\Message;
use Longman\TelegramBot\Entities\PollAnswer;
use Longman\TelegramBot\Entities\PollOption;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Telegram;
use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\PreResponseProcessor\PreResponseProcessor;
use Perk11\Viktor89\ProcessingResult;
use Perk11\Viktor89\Repository\KickQueueRepository;
use Perk11\Viktor89\TelegramUserHelper;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

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

    public function __construct(
        private readonly KickQueueRepository $kickQueueRepository,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function process(PollAnswer $pollAnswer): ProcessingResult
    {
        $this->logger->log(LogLevel::INFO, 'Received poll answer from user ' . $pollAnswer->getUser()->getId());
        $kickQueueItem = $this->kickQueueRepository->findKickQueueItemByPollId($pollAnswer->getPollId());
        if ($kickQueueItem === null) {
            $this->logger->log(LogLevel::INFO, 'This is not a join poll, not doing anything');

            return new ProcessingResult(null, false);
        }
        if ($kickQueueItem->userId !== $pollAnswer->getUser()->getId()) {
            $this->logger->log(LogLevel::INFO, 'Different user is responding to join poll, not doing anything');

            return new ProcessingResult(null, false);
        }
        $selectedResponseIds = $pollAnswer->getOptionIds();
        if (count($selectedResponseIds) > 1) {
            $this->logger->log(LogLevel::WARNING, "Multiple quiz responses detected, this shouldn't be possible. Ignoring...");

            return new ProcessingResult(null, false);
        }
        $this->kickQueueRepository->nullKickTime($pollAnswer->getPollId());
        $this->logger->log(LogLevel::INFO, 'Deleting messages ' . json_encode($kickQueueItem->messagesToDelete, JSON_THROW_ON_ERROR));
        $deleteMessagesResult = Request::execute('deleteMessages', [
            'chat_id' => $kickQueueItem->chatId,
            'message_ids' => json_encode($kickQueueItem->messagesToDelete, JSON_THROW_ON_ERROR),
        ]);
        $this->logger->log(LogLevel::DEBUG, 'deleteMessages result: ' . print_r($deleteMessagesResult, true));
        $message = new InternalMessage();
        $message->chatId = $kickQueueItem->chatId;
        $message->replyToMessageId = $kickQueueItem->joinMessageId;

        if ($selectedResponseIds[0] !== JoinQuizProcessor::CORRECT_ANSWER_INDEX) {
            $banRequest = Request::banChatMember([
                                                     'chat_id' => $message->chatId,
                                                     'user_id' => $pollAnswer->getUser()->getId(),
                                                 ]);

            if (!$banRequest->isOk()) {
                $this->logger->log(LogLevel::ERROR, 'Failed to ban user');
                $this->logger->log(LogLevel::DEBUG, print_r($banRequest, true));
                $message->messageText =  TelegramUserHelper::fullNameWithIdAndUserName($pollAnswer->getUser()) . " ответил(-а) неправильно!";

                return new ProcessingResult($message, false);
            }
            $unbanRequest = Request::unbanChatMember([
                                                         'chat_id' => $message->chatId,
                                                         'user_id' => $pollAnswer->getUser()->getId(),
                                                     ]);
            if (!$unbanRequest->isOk()) {
                $this->logger->log(LogLevel::ERROR, 'Failed to unban user');
                $this->logger->log(LogLevel::DEBUG, print_r($unbanRequest, true));
                $message->messageText = TelegramUserHelper::fullNameWithIdAndUserName($pollAnswer->getUser()) . " ответил(-а) неправильно и был забанен!";

                return new ProcessingResult($message, false);
            }

            $message->messageText = TelegramUserHelper::fullNameWithIdAndUserName($pollAnswer->getUser()) .
                " ответил(-а) неправильно и был удалён из чата";

            return new ProcessingResult($message, false);
        }

        $this->logger->log(LogLevel::INFO, 'Answer was correct');
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
