<?php

namespace Perk11\Viktor89;

use Orhanerday\OpenAi\OpenAi;

class OpenAISummaryProvider
{
    private readonly OpenAi $openAiClient;

    public function __construct(private Database $database)
    {
        $apiKey = $_ENV['SUMMARY_OPENAI_KEY'];
        $this->openAiClient = new OpenAi($apiKey);
        if (strlen($apiKey) === 0 && strlen($_ENV['SUMMARY_SERVER']) === 0) {
            throw new \Exception('SUMMARY_OPENAI_KEY and SUMMARY_SERVER are both  empty, at least one is required');
        }
        if (isset($_ENV['SUMMARY_SERVER'])) {
            $this->openAiClient->setBaseURL($_ENV['SUMMARY_SERVER']);
        }

    }

    private const MESSAGES_ANALYZED_PER_BATCH = 100;
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
            echo "Sending prompt of size " . mb_strlen($prompt) . " to OpenAI API...\n";
            $result = $this->openAiClient->chat([
                                               'messages' => [
                                                   [
                                                       "role"    => "system",
                                                       "content" => "Summarize messages from a group chat for someone who missed them. Respond in Russian. Provide at least 1 mention of every author. Mention author names in the summary. Do not add output other than the summary itself.",
                                                   ],
                                                   [
                                                       "role"    => "user",
                                                       "content" => $prompt,
                                                   ],
                                               ],
                                               'model'    => 'gpt-4',
//                                           'model'    => 'gpt-3.5-turbo',
//                                           'stream'   => false,
                                           ]);

            $parsedResult = json_decode($result, JSON_THROW_ON_ERROR);
            if (!array_key_exists('choices', $parsedResult)) {
                echo "Unexpected response from OpenAI: $result \n";
            }
            $summary .= "\n" . $parsedResult['choices'][0]['message']['content'];
            $offset += self::MESSAGES_ANALYZED_PER_BATCH;
//            sleep(30); //avoid gpt-4 rate limit
        }
        echo $summary;
        $this->database->recordChatSummary($chatId, $summary);

        return $summary;
    }
}
