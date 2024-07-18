<?php

namespace Perk11\Viktor89;

use Orhanerday\OpenAi\OpenAi;
use Perk11\Viktor89\ImageGeneration\Automatic1111APiClient;
use Perk11\Viktor89\ImageGeneration\Automatic1111ImageApiResponse;
use Perk11\Viktor89\ImageGeneration\Prompt2ImgGenerator;
use Perk11\Viktor89\ImageGeneration\PromptAndImg2ImgGenerator;

class AssistedImageGenerator implements Prompt2ImgGenerator, PromptAndImg2ImgGenerator
{
    private OpenAi $openAi;

    public function __construct(
        private readonly Automatic1111APiClient $automatic1111APiClient,
        private readonly OpenAiCompletionStringParser $openAiCompletionStringParser,
    ) {
        $this->openAi = new OpenAi('');
        $this->openAi->setBaseURL($_ENV['OPENAI_ASSISTANT_SERVER']);
    }

    public function generateByPromptTxt2Img(string $prompt, int $userId): Automatic1111ImageApiResponse
    {
        $improvedPrompt = $this->processPrompt($prompt);
        return $this->automatic1111APiClient->generateByPromptTxt2Img($improvedPrompt, $userId);
    }

    public function generatePromptAndImageImg2Img(
        string $imageContent,
        string $prompt,
        int $userId
    ): Automatic1111ImageApiResponse {
        $improvedPrompt = $this->processPrompt($prompt);
        return $this->automatic1111APiClient->generatePromptAndImageImg2Img($imageContent, $improvedPrompt, $userId);
    }

    private function processPrompt(string $originalPrompt): string
    {
        $systemPrompt = "You are Gemma. Given a message from the user, add details, reword and expand on it in a way that describes an image illustrating user's message.  This text will be used to generate an image using automatic text to image generator that does not understand emotions, metaphors or negatives. Your output should contain only a literal description of the image in a single sentence. Only describe what an observer will see. Your output will be directly passed to an API, so don't output anything extra. Do not use any syntax or code formatting, just output raw text describing the image and nothing else. Translate the output to English.";
        $prompt = "$systemPrompt\n\nUser: $originalPrompt\nGemma: ";
        return $this->getCompletion($prompt);
    }

    private function getCompletion(string $prompt): string
    {
        $opts = [
            'prompt' => $prompt,
            'stream' => true,
            "stop"   => [
                "</s>",
                "Gemma:",
                "User:",
            ],
        ];
        $fullContent = '';
        $jsonPart = null;
        $this->openAi->completion($opts, function ($curl_info, $data) use (&$fullContent, &$jsonPart) {
            if ($jsonPart === null) {
                $dataToParse = $data;
            } else {
                $dataToParse = $jsonPart . $data;
            }
            try {
                $parsedData = $this->openAiCompletionStringParser->parse($dataToParse);
                $jsonPart = null;
            } catch (JSONException $e) {
                echo "\nIncomplete JSON received, postponing parsing until more is received\n";
                $jsonPart = $dataToParse;

                return strlen($data);
            }
            echo $parsedData['content'];
            $fullContent .= $parsedData['content'];
            if (mb_strlen($fullContent) > 8192) {
                echo "Max length reached, aborting response\n";

                return 0;
            }

            return strlen($data);
        });


        return trim($fullContent);
    }
}
