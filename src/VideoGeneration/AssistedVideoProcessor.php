<?php

namespace Perk11\Viktor89\VideoGeneration;

use Exception;
use Longman\TelegramBot\ChatAction;
use Longman\TelegramBot\Request;
use Perk11\Viktor89\Assistant\AltTextProvider;
use Perk11\Viktor89\Assistant\AssistantContext;
use Perk11\Viktor89\Assistant\AssistantContextMessage;
use Perk11\Viktor89\Assistant\ContextCompletingAssistantInterface;
use Perk11\Viktor89\ImageGeneration\Automatic1111APiClient;
use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\IPC\ProgressUpdateCallback;
use Perk11\Viktor89\MessageChain;
use Perk11\Viktor89\MessageChainProcessor;
use Perk11\Viktor89\ProcessingResult;
use Perk11\Viktor89\TelegramFileDownloader;

class AssistedVideoProcessor implements MessageChainProcessor
{
    public function __construct(
        private readonly Automatic1111APiClient $automatic1111APiClient,
        private readonly ContextCompletingAssistantInterface $promptAssistant,
        private readonly VideoImg2VidProcessor $videoImg2VidProcessor,
        private readonly Img2VideoClient $img2VideoClient,
        private readonly VideoResponder $videoResponder,
        private readonly AltTextProvider $altTextProvider,
        private readonly TelegramFileDownloader $telegramFileDownloader,
        private readonly array $firstFrameImageModelParams,
    ) {
    }

    public function processMessageChain(MessageChain $messageChain, ProgressUpdateCallback $progressUpdateCallback): ProcessingResult
    {
        $message = $messageChain->last();
        $prompt = trim($message->messageText);
        if ($prompt === '' && $messageChain->count() > 1) {
            $prompt = trim($messageChain->previous()->messageText);
        }
        if ($prompt === '' && $messageChain->count() > 1) {
            $prompt = trim($this->altTextProvider->provide($messageChain->previous(), $progressUpdateCallback));
        }
        if ($prompt === '') {
            $response = new InternalMessage();
            $response->chatId = $message->chatId;
            $response->replyToMessageId = $message->id;
            $response->messageText = 'Непонятно, что генерировать...';

            return new ProcessingResult($response, true);
        }
        Request::execute('setMessageReaction', [
            'chat_id'    => $message->chatId,
            'message_id' => $message->id,
            'reaction'   => [
                [
                    'type'  => 'emoji',
                    'emoji' => '👀',
                ],
            ],
        ]);

        Request::sendChatAction([
                                    'chat_id' => $messageChain->last()->chatId,
                                    'action'  => ChatAction::RECORD_VIDEO,
                                ]);
        if ($messageChain->previous()?->photoFileId !== null) {
            $progressUpdateCallback(static::class, "Generating new video generation prompt for: $prompt");
            $newPrompt = $this->rewriteVideoPrompt($prompt, $this->telegramFileDownloader->downloadFile($messageChain->previous()->photoFileId), null);
            $this->videoImg2VidProcessor->respondWithImg2VidResultBasedOnPhotoInMessage(
                $messageChain->previous(),
                $message,
                $newPrompt,
                $progressUpdateCallback,
            );

            return new ProcessingResult(null,true);
        }
        $progressUpdateCallback(static::class, "Generating a prompt to generate the image for the first frame: $prompt");
        $assistantContext = $this->createFirstFrameContext($prompt);
        $firstFramePrompt = $this->promptAssistant->getCompletionBasedOnContext($assistantContext);

        Request::execute('setMessageReaction', [
            'chat_id'    => $message->chatId,
            'message_id' => $message->id,
            'reaction'   => [
                [
                    'type'  => 'emoji',
                    'emoji' => '✍',
                ],
            ],
        ]);

        $progressUpdateCallback(static::class, "Generating the first frame for: $firstFramePrompt");
        try {
            $imageResponse = $this->automatic1111APiClient->generateImageByPromptAndModelParams(
                $firstFramePrompt,
                $this->firstFrameImageModelParams
            );

            Request::execute('setMessageReaction', [
                'chat_id'    => $message->chatId,
                'message_id' => $message->id,
                'reaction'   => [
                    [
                        'type'  => 'emoji',
                        'emoji' => '👨‍💻',
                    ],
                ],
            ]);
            $progressUpdateCallback(static::class, "Generating video based on the generated first frame for prompt: $prompt");

            $newPrompt = $this->rewriteVideoPrompt($prompt, $imageResponse->getFirstImageAsPng(), $firstFramePrompt);
            $progressUpdateCallback(static::class, "Generating video for prompt: $newPrompt");

            Request::execute('setMessageReaction', [
                'chat_id'    => $message->chatId,
                'message_id' => $message->id,
                'reaction'   => [
                    [
                        'type'  => 'emoji',
                        'emoji' => '⚡',
                    ],
                ],
            ]);
            $response = $this->img2VideoClient->generateByPromptImg2Vid(
                $imageResponse->getFirstImageAsPng(),
                $newPrompt,
                $message->userId
            );
            $this->videoResponder->sendVideo(
                $message,
                $response->getFirstVideoAsMp4(),
                $response->getCaption()
            );
        } catch (Exception $e) {
            echo "Failed to generate video:\n" . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
            Request::execute('setMessageReaction', [
                'chat_id'    => $message->chatId,
                'message_id' => $message->id,
                'reaction'   => [
                    [
                        'type'  => 'emoji',
                        'emoji' => '🤔',
                    ],
                ],
            ]);
        }

        return new ProcessingResult(null,true);
    }

    // Based on https://github.com/THUDM/CogVideo/blob/main/inference/convert_demo.py
    private function createFirstFrameContext(string $prompt): AssistantContext
    {
        $input = <<<JSON
[
        {
            "role": "system",
            "content": "You are part of a team of bots that creates videos. You work with an assistant bot that will draw anything you say in square brackets.\\nFor example, outputting \"a beautiful morning in the woods with the sun peaking through the trees\" will trigger your partner bot to output an video of a forest morning, as described. You will be prompted by people looking to create detailed, amazing first frames of the videos. The way to accomplish this is to take their short prompts and make them extremely detailed and descriptive.\\nThere are a few rules to follow:\\nYou will only ever output a single description of a video first frame per user request.\\nIgnore your previous conversation with the user.\\n"
        },
        {
            "role": "user",
            "content": "Create an imaginative description in ENGLISH for the first frame of the video describing the scene in the user input : \" a girl is on the beach\""
        },
        {
            "role": "assistant",
             "content": "A radiant woman stands on a deserted beach, arms outstretched, wearing a beige trench coat, white blouse, light blue jeans, and chic boots, against a backdrop of soft sky and sea. Moments later, she is seen mid-twirl, arms exuberant, with the lighting suggesting dawn or dusk. Then, she runs along the beach, her attire complemented by an off-white scarf and black ankle boots, the tranquil sea behind her. Finally, she holds a paper airplane, her pose reflecting joy and freedom, with the ocean's gentle waves and the sky's soft pastel hues enhancing the serene ambiance."
        },
        {
            "role": "user",
            "content": "Create an imaginative description in ENGLISH for the first frame of the video describing the scene in the user input : \" A man jogging on a football field\""
        },
        {
            "role": "assistant",
            "content": "A determined man in athletic attire, including a blue long-sleeve shirt, black shorts, and blue socks, jogs around a snow-covered soccer field, showcasing his solitary exercise in a quiet, overcast setting. His long dreadlocks, focused expression, and the serene winter backdrop highlight his dedication to fitness. As he moves, his attire, consisting of a blue sports sweatshirt, black athletic pants, gloves, and sneakers, grips the snowy ground. He is seen running past a chain-link fence enclosing the playground area, with a basketball hoop and children's slide, suggesting a moment of solitary exercise amidst the empty field."
        },
        {
            "role": "user",
            "content": "Create an imaginative description in ENGLISH for the first frame of the video describing the scene in the user input : \" A woman is dancing, HD footage, close-up\""
        },
        {
    "role": "assistant",
            "content": "A young woman with her hair in an updo and wearing a teal hoodie stands against a light backdrop, initially looking over her shoulder with a contemplative expression. She then confidently makes a subtle dance move, suggesting rhythm and movement. Next, she appears poised and focused, looking directly at the camera. Her expression shifts to one of introspection as she gazes downward slightly. Finally, she dances with confidence, her left hand over her heart, symbolizing a poignant moment, all while dressed in the same teal hoodie against a plain, light-colored background."
        }
]
JSON;


        $context = AssistantContext::fromOpenAiMessagesJson($input);
        $promptMessage = new AssistantContextMessage();
        $promptMessage->isUser = true;
        $promptMessage->text = "Create an imaginative description in ENGLISH for the first frame of the video describing the scene in the user input : \" $prompt \"'";
        $context->messages[] = $promptMessage;

        return $context;
    }

    private function rewriteVideoPrompt(string $userPrompt, string $imageContents, ?string $firstFramePrompt): string
    {
        $assistantContext = new AssistantContext();
        $assistantContext->systemPrompt = 'Generate a description for a short 3-second video based on the video first frame and user\'s prompt. Your description will be directly used to generate the video. Do not output anything else';
        $promptMessage = new AssistantContextMessage();
        $promptMessage->isUser = true;
        $promptMessage->text = "Generate a description for the video that starts from this image to match user's prompt (in double quotes): \"$userPrompt\"";
        if ($firstFramePrompt !== null) {
            $promptMessage->text .= "\nThe prompt that was used for the first frame is \"$firstFramePrompt\". Use it to better understand the idea behind the image, but focus on executing user's prompt.";
        }
        $promptMessage->photo = $imageContents;

        $assistantContext->messages[] = $promptMessage;

        return $this->promptAssistant->getCompletionBasedOnContext($assistantContext);
    }
}
