<?php

namespace Perk11\Viktor89;

use Orhanerday\OpenAi\OpenAi;

class ChatGptSummaryProvider
{
    private readonly OpenAi $chatGpt;

    public function __construct(private Database $database)
    {
        $apiKey = $_ENV['SUMMARY_OPENAI_KEY'];
        if (strlen($apiKey) === 0) {
            throw new \Exception('Summary OpenAI api key is empty');
        }
        $this->chatGpt = new OpenAi($apiKey);
    }

    private const MESSAGES_ANALYZED_PER_BATCH = 200;
    public function provideSummaryIf24HoursPassedSinceLastOneA(int $chatId): ?string
    {
        $lastSummaryDate = $this->database->getLastChatSummaryDate($chatId);
        if ($lastSummaryDate !== false) {
            if (time() - $lastSummaryDate < 24*60*60) {
                return null;
            }
        }
        return $this->provideSummary($chatId);
    }
    public function provideSummary(int $chatId): ?string
    {
        $allMessages = $this->database->findMessagesSentInLast24HoursInChat($chatId);
        if (count($allMessages) < 10) {
            echo count($allMessages) . " messages found in chat $chatId in last 24 hours, no summary to provide\n";
            return null;
        }
        $summary = "Сообщений проанализировано: ";
        if (count($allMessages) > 1000) {
            $summary .= '1000';
            $allMessages = array_slice($allMessages, 0, 1000);
        } else {
            $summary .= count($allMessages);
        }
        echo "Generating summary of " . count($allMessages) . " messages found in chat $chatId in last 24 hours\n";

        $offset = 0;
        while ($offset < count($allMessages)) {
            $messages = array_slice($allMessages, $offset, self::MESSAGES_ANALYZED_PER_BATCH);
            $prompt = '';
            foreach ($messages as $message) {
                $prompt .= $message->userName . ': ' . $message->messageText . "\n";
            }
            $result = $this->chatGpt->chat([
                                               'messages' => [
                                                   [
                                                       "role"    => "system",
                                                       "content" => "You summarize messages from a group chat for someone who missed them. You respond in Russian. Provide at least 1 mention of every author. Mention author names in the summary.",
                                                   ],
                                                   [
                                                       "role"    => "user",
                                                       "content" => $prompt,
                                                   ],
                                               ],
//                                               'model'    => 'gpt-4o',
                                           'model'    => 'gpt-3.5-turbo',
//                                           'stream'   => false,
                                           ]);

            $parsedResult = json_decode($result, JSON_THROW_ON_ERROR);
            $summary .= "\n" . $parsedResult['choices'][0]['message']['content'];
            $offset += self::MESSAGES_ANALYZED_PER_BATCH;
        }
        echo $summary;
        $this->database->recordChatSummary($chatId, $result);

        return $summary;
    }
}
