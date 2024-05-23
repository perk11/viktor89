<?php

namespace Perk11\Viktor89;

use Longman\TelegramBot\Entities\Message;
use Orhanerday\OpenAi\OpenAi;

class Siepatch2Responder implements TelegramResponderInterface
{
    private OpenAi $openAi;

    private array $chatsByUser = [];

    public function __construct()
    {
        $this->openAi = new OpenAi('');
        $this->openAi->setBaseURL($_ENV['OPENAI_SERVER']);
    }

    private function getCompletion(string $prompt): string
    {
        $opts = [
            'prompt'            => $prompt,
            'temperature'       => 0.6,
            'cache_prompt'      => true,
            'repeat_penalty'    => 1.18,
            'repeat_last_n'     => 256,
            "penalize_nl"       => false,
            "top_k"             => 10,
            "top_p"             => 0.7,
            "min_p"             => 0.05,
            "tfs_z"             => 1,
//        "max_tokens"        => 150,
            "frequency_penalty" => 0,
            "presence_penalty"  => 0,
            "stream"            => true,
            "stop"              => [
                "<human>",
                "<bot>",
            ],
        ];
        $fullContent = '';
        try {
            $this->openAi->completion($opts, function ($curl_info, $data) use (&$fullContent) {
                $parsedData = parse_completion_string($data);
                echo $parsedData['content'];
                $fullContent .= $parsedData['content'];
                if (mb_strlen($fullContent) > 1024) {
                    return 0;
                }
//                if (str_contains($fullContent, "\n<")) { //todo: check for >
//                    $fullContent = mb_substr($fullContent, 0, mb_strpos($fullContent, "\n<"));
//
//                    return 0;
//                }

                return strlen($data);
            });
        } catch (\Exception $e) {
        }

        return trim($fullContent);
    }

    public function getResponseByMessage(Message $message): string
    {
        $incomingMessageText = $message->getText();
        $toAddToPrompt = "<bot>: $incomingMessageText\n<human>:";
        echo $toAddToPrompt;
        if (!array_key_exists($message->getFrom()->getId(), $this->chatsByUser)) {
            $this->chatsByUser[$message->getFrom()->getId()] = "\n\n";
        }
        $this->chatsByUser[$message->getFrom()->getId()] .= $toAddToPrompt;

        $response = $this->getCompletion($this->chatsByUser[$message->getFrom()->getId()]);
        if (str_ends_with(']', $response)) {
            $response = $this->getCompletion($this->chatsByUser[$message->getFrom()->getId()]);
        }
        if (str_ends_with(']', $response)) {
            $response = $this->getCompletion($this->chatsByUser[$message->getFrom()->getId()]);
        }
        $addToChat = "$response\n";
        echo $addToChat;
        $this->chatsByUser[$message->getFrom()->getId()] .= $addToChat;

        $response =  str_replace('[Виктор 89]', '[Nanak0n]', $response);
        $response =  str_replace('[', '[отвечает ', $response);
        $youtube_pattern = '/(?:https?:\/\/)?(?:www\.)?(?:youtube\.com\/(?:[^\/\n\s]+\/\S+\/|(?:v|e(?:mbed)?)\/|\S*?[?&]v=)|youtu\.be\/)([a-zA-Z0-9_-]{11})/';
        $response = preg_replace($youtube_pattern, 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', $response);

        return $response;
    }
}
