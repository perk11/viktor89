<?php

namespace Perk11\Viktor89;

use Exception;
use Longman\TelegramBot\Entities\Message;
use Orhanerday\OpenAi\OpenAi;

class SiepatchNoInstructResponseGenerator implements TelegramResponderInterface
{
    private array $chatsByUser =  [];


    private function getCompletion(string $prompt): string
    {
        $prompt = mb_substr($prompt, 0, 1024);
//    echo $prompt;

        $openAi = new OpenAi('');
        $openAi->setBaseURL($_ENV['OPENAI_SERVER']);
        $opts = [
            'prompt'            => $prompt,
            'temperature'       => 0.6,
            'repeat_penalty'    => 1.18,
            "penalize_nl"       => false,
            "top_k"             => 40,
            "top_p"             => 0.95,
            "min_p"             => 0.05,
            "tfs_z"             => 1,
//        "max_tokens"        => 150,
            "frequency_penalty" => 0,
            "presence_penalty"  => 0,
            "stream"            => true,
        ];
        $fullContent = '';
        try {
            $openAi->completion($opts, function ($curl_info, $data) use (&$fullContent) {
                $parsedData = parse_completion_string($data);
                echo $parsedData['content'];
                $fullContent .= $parsedData['content'];
                if (str_contains($fullContent, "\n<")) { //todo: check for >
                    $fullContent = mb_substr($fullContent, 0, mb_strpos($fullContent, "\n<"));

                    return 0;
                }

                return strlen($data);
            });
        } catch (Exception $e) {
        }

        return trim($fullContent);
    }

    public function getResponseByMessage(Message $message): string
    {
        $incomingMessageText = $message->getText();
        $toAddToPrompt = "<" . $message->getFrom()->getUsername() . '>: ' . $incomingMessageText . "\n<";
        $random = random_int(0, 5);
        echo $random;
        if ($random === 0) {
            $response = "Виктор 89>: ";
        } elseif ($random === 1) {
            $response = "Моно>: ";
        } else {
            $response = '';
        }
        $toAddToPrompt .= $response;
        echo $toAddToPrompt;
        if (!array_key_exists($message->getFrom()->getId(), $this->chatsByUser)) {
            $this->chatsByUser[$message->getFrom()->getId()] = '';
        }
        $this->chatsByUser[$message->getFrom()->getId()] .= $toAddToPrompt;

        $response .= $this->getCompletion($this->chatsByUser[$message->getFrom()->getId()]);
        $addToChat = "$response\n";
        echo $addToChat;
        $this->chatsByUser[$message->getFrom()->getId()] .= $addToChat;

        return '<' . str_replace('Виктор 89>', 'Nanak0n>', $response);
    }
}
