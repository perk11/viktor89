<?php

namespace Perk11\Viktor89\Assistant;

use GuzzleHttp\Client;
use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\IPC\ProgressUpdateCallback;
use Perk11\Viktor89\MessageChain;
use Perk11\Viktor89\ProcessingResult;
use Perk11\Viktor89\UserPreferenceReaderInterface;

class PerplexicaAssistant implements AssistantInterface
{

    private Client $client;

    public function __construct(string $url)
    {
        $this->client = new Client([
                                       'base_uri' => $url,
                                       'timeout'  => 6000,
                                   ]);
    }

    public function getCompletionBasedOnContext(AssistantContext $assistantContext, ?callable $streamFunction = null): string
    {
        $lastMessage = $assistantContext->messages[count($assistantContext->messages) - 1];
        $history = [];
        $maxHistoryMessages = 4;
        $minMessageIndex = max(0, count($assistantContext->messages) - $maxHistoryMessages);
        for ($i = $minMessageIndex; $i < count($assistantContext->messages) - 1; $i++) {
            $history[] = [
                $assistantContext->messages[$i]->isUser ? 'human' : 'assistant',
                mb_substr($assistantContext->messages[$i]->text, 0, 500),
            ];
        }
        $payload = [
            'optimizationMode' => 'balanced',
            'focusMode'        => 'webSearch',
            'query'            => $lastMessage->text,
            'history'          => $history,
        ];

        $response = $this->client->post('/api/search', [
            'json' => $payload,
        ]);

        $responseBody = $response->getBody()->getContents();
        $data = json_decode($responseBody, true, 512, JSON_THROW_ON_ERROR);

        $message = $data['message'];
        echo "Response from Perplexica:\n$message\n";
        echo "Sources:\n";
        var_dump($data['sources']);
        if (isset($data['sources']) && count($data['sources']) > 0) {
            $message .= "\nSources:\n";
            foreach ($data['sources'] as $index => $source) {
                $message .= sprintf(
                    "%d. %s\n",
                    $index + 1,
                    $source['metadata']['url']
                );
            }
        }
        if ($streamFunction !== null) {
            $streamFunction($message);
        }

        return $message;
    }

    private function convertMessageChainToAssistantContext(
        MessageChain $messageChain
    ): AssistantContext {
        $isUser = $messageChain->count() % 2 === 1;
        $assistantContext = new AssistantContext();
        foreach ($messageChain->getMessages() as $message) {
            $assistantContextMessage = new AssistantContextMessage();
            $assistantContextMessage->isUser = $isUser;
            $assistantContextMessage->text = $message->messageText;
            $assistantContext->messages[] = $assistantContextMessage;
            $isUser = !$isUser;
        }

        return $assistantContext;
    }

    public function processMessageChain(MessageChain $messageChain, ProgressUpdateCallback $progressUpdateCallback): ProcessingResult
    {
        $userId = $messageChain->last()->userId;

        $assistantContext = $this->convertMessageChainToAssistantContext($messageChain);
        $progressUpdateCallback(static::class, "Generating Perplexica response");
        $lastMessage = $messageChain->last();
        $message = new InternalMessage();
        $message->replyToMessageId = $lastMessage->id;
        $message->chatId = $lastMessage->chatId;
        $message->parseMode = 'MarkdownV2';
        $message->messageText = trim($this->getCompletionBasedOnContext($assistantContext));
        for ($i = 0; $i < 5; $i++) {
            if (trim($message->messageText) === '') {
                echo "Bad response detected, trying again\n";
                $message->messageText =  $this->getCompletionBasedOnContext($assistantContext);
            } else {
                break;
            }
        }

        return new ProcessingResult($message, true);
    }
}
