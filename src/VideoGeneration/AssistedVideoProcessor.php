<?php

namespace Perk11\Viktor89\VideoGeneration;

use Longman\TelegramBot\Request;
use Perk11\Viktor89\Assistant\AssistantContext;
use Perk11\Viktor89\Assistant\AssistantContextMessage;
use Perk11\Viktor89\Assistant\ContextCompletingAssistantInterface;
use Perk11\Viktor89\ImageGeneration\Automatic1111APiClient;
use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\TelegramChainBasedResponderInterface;

class AssistedVideoProcessor implements TelegramChainBasedResponderInterface
{
    public function __construct(
        private readonly Automatic1111APiClient $automatic1111APiClient,
        private readonly ContextCompletingAssistantInterface $promptAssistant,
        private readonly VideoImg2VidProcessor $videoImg2VidProcessor,
        private readonly Img2VideoClient $img2VideoClient,
        private readonly VideoResponder $videoResponder,
        private readonly array $firstFrameImageModelParams,
    ) {
    }

    public function getResponseByMessageChain(array $messageChain): ?InternalMessage
    {
        /** @var ?InternalMessage $lastMessage */
        $message = $messageChain[count($messageChain) - 1];
        $prompt = $message->messageText;
        if (count($messageChain) > 1) {
            $prompt = trim($messageChain[count($messageChain) - 2]->messageText . "\n\n" . $prompt);
        }
        if ($prompt === '') {
            $response = new InternalMessage();
            $response->chatId = $message->chatId;
            $response->replyToMessageId = $message->id;
            $response->messageText = 'Непонятно, что генерировать...';

            return $response;
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
        echo "Generating new video prompt for: $prompt\n";
        $assistantContext = $this->createContext($prompt);
        $newPrompt = $this->promptAssistant->getCompletionBasedOnContext($assistantContext);

        if ($message->replyToPhoto !== null) {
            $this->videoImg2VidProcessor->respondWithImg2VidResultBasedOnPhotoInMessage(
                $message->replyToPhoto,
                $message,
                $newPrompt
            );

            return null;
        }
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
        echo "Generating first frame for video prompt: $newPrompt\n";

        try {
            $imageResponse = $this->automatic1111APiClient->generateImageByPromptAndModelParams(
                $newPrompt,
                $this->firstFrameImageModelParams
            );
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
        } catch (\Exception $e) {
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

        return null;
    }

    // Based on https://github.com/THUDM/CogVideo/blob/main/inference/convert_demo.py
    private function createContext(string $prompt): AssistantContext
    {
        $input = <<<JSON
[
        {
            "role": "system",
            "content": "You are part of a team of bots that creates videos. You work with an assistant bot that will draw anything you say in square brackets.\\nFor example, outputting \"a beautiful morning in the woods with the sun peaking through the trees\" will trigger your partner bot to output an video of a forest morning, as described. You will be prompted by people looking to create detailed, amazing videos. The way to accomplish this is to take their short prompts and make them extremely detailed and descriptive.\\nThere are a few rules to follow:\\nYou will only ever output a single video description per user request.\\nIgnore your previous conversation with the user.\\nAll video descriptions should consist of 96 words. Extra words will be ignored."
        },
        {
            "role": "user",
            "content": "Create an imaginative video descriptive caption in ENGLISH for the user input : \" a girl is on the beach\""
        },
        {
            "role": "assistant",
             "content": "A radiant woman stands on a deserted beach, arms outstretched, wearing a beige trench coat, white blouse, light blue jeans, and chic boots, against a backdrop of soft sky and sea. Moments later, she is seen mid-twirl, arms exuberant, with the lighting suggesting dawn or dusk. Then, she runs along the beach, her attire complemented by an off-white scarf and black ankle boots, the tranquil sea behind her. Finally, she holds a paper airplane, her pose reflecting joy and freedom, with the ocean's gentle waves and the sky's soft pastel hues enhancing the serene ambiance."
        },
        {
            "role": "user",
            "content": "Create an imaginative video descriptive caption in ENGLISH for the user input : \" A man jogging on a football field\""
        },
        {
            "role": "assistant",
            "content": "A determined man in athletic attire, including a blue long-sleeve shirt, black shorts, and blue socks, jogs around a snow-covered soccer field, showcasing his solitary exercise in a quiet, overcast setting. His long dreadlocks, focused expression, and the serene winter backdrop highlight his dedication to fitness. As he moves, his attire, consisting of a blue sports sweatshirt, black athletic pants, gloves, and sneakers, grips the snowy ground. He is seen running past a chain-link fence enclosing the playground area, with a basketball hoop and children's slide, suggesting a moment of solitary exercise amidst the empty field."
        },
        {
            "role": "user",
            "content": "Create an imaginative video descriptive caption in ENGLISH for the user input : \" A woman is dancing, HD footage, close-up\""
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
        $promptMessage->text = "Create an imaginative video descriptive caption in ENGLISH for the user input: \" $prompt \"'";
        $context->messages[] = $promptMessage;

        return $context;
    }

}
