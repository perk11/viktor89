<?php

namespace Perk11\Viktor89\VideoGeneration;

use Exception;
use Perk11\Viktor89\Util\Telegram\ReactionSetter;
use Perk11\Viktor89\Assistant\AltTextProvider;
use Perk11\Viktor89\Assistant\AssistantContext;
use Perk11\Viktor89\Assistant\AssistantContextMessage;
use Perk11\Viktor89\Assistant\AssistantInterface;
use Perk11\Viktor89\ImageGeneration\ImageGenerationPrompt;
use Perk11\Viktor89\ImageGeneration\ImgTagExtractor;
use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\IPC\ProgressUpdateCallback;
use Perk11\Viktor89\MessageChain;
use Perk11\Viktor89\MessageChainProcessor;
use Perk11\Viktor89\PreResponseProcessor\SavedImageNotFoundException;
use Perk11\Viktor89\ProcessingResult;
use Perk11\Viktor89\TelegramFileDownloader;
use Perk11\Viktor89\Util\Telegram\ChatAction;
use Perk11\Viktor89\Util\Telegram\ChatActionEnum;
use Perk11\Viktor89\VoiceGeneration\SingProcessor;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * Implements the `/mvid` (music video) command.
 *
 * Pipeline:
 *  1. Acquire a starting image (reply photo, <img> tag, or generate a first
 *     frame from the text like /vid when none is given).
 *  2. Have the "song" assistant write ~20s lyrics + genre tags from the image
 *     and/or text (image passed directly to vision-capable assistants, or
 *     described via alt text otherwise — same as the normal assistant path).
 *  3. Render the lyrics into song audio with the currently selected sing model.
 *  4. Generate a video that starts from the image and uses the song as audio
 *     (the same audio+image→video model /vo uses) and send it.
 *
 * Each step sets a distinct reaction on the command message; the final video
 * send removes it (via VideoResponder), mirroring /image.
 */
class MvidProcessor implements MessageChainProcessor
{
    private const SONG_DURATION_MS = 20 * 1000;

    public function __construct(
        private readonly AssistedVideoProcessor $assistedVideoProcessor,
        private readonly AssistantInterface $lyricsAssistant,
        private readonly SingProcessor $singProcessor,
        private readonly AudioImgTxt2VidClient $audioImgTxt2VidClient,
        private readonly VideoResponder $videoResponder,
        private readonly TelegramFileDownloader $telegramFileDownloader,
        private readonly ImgTagExtractor $imgTagExtractor,
        private readonly AltTextProvider $altTextProvider,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function processMessageChain(
        MessageChain $messageChain,
        ProgressUpdateCallback $progressUpdateCallback
    ): ProcessingResult {
        $message = $messageChain->last();
        $userText = trim($message->messageText);

        $prompt = new ImageGenerationPrompt($userText);
        try {
            $prompt = $this->imgTagExtractor->extractImageTags($prompt, 'not implemented', $messageChain, true);
        } catch (SavedImageNotFoundException $e) {
            return new ProcessingResult(
                InternalMessage::asResponseTo(
                    $message,
                    sprintf(
                        'Изображение с именем "%s" не найдено, создайте его используя команду /saveas',
                        $e->getMessage()
                    ),
                ),
                true,
            );
        }
        if (count($prompt->sourceImagesContents) > 1) {
            return new ProcessingResult(
                InternalMessage::asResponseTo($message, 'Укажите не более одного исходного изображения.'),
                true,
            );
        }
        $imageContents = $prompt->sourceImagesContents[0] ?? null;
        $userText = trim($prompt->text);

        if ($imageContents === null && $messageChain->previous()?->photoFileId !== null) {
            try {
                $imageContents = $this->telegramFileDownloader->downloadPhotoFromInternalMessage(
                    $messageChain->previous()
                );
            } catch (Exception $e) {
                $this->logger->log(LogLevel::ERROR, 'Failed to download source image for /mvid: ' . $e->getMessage());

                return new ProcessingResult(
                    InternalMessage::asResponseTo($message, 'Не удалось скачать изображение.'),
                    true,
                    '🤔',
                    $message,
                );
            }
        }

        if ($userText === '' && $messageChain->count() > 1) {
            $userText = trim($messageChain->previous()->messageText);
        }

        if ($imageContents === null && $userText === '') {
            return new ProcessingResult(
                InternalMessage::asResponseTo(
                    $message,
                    'Используйте эту команду с текстом (идеей для клипа), например: /mvid закат над городом. '
                    . 'Можно ответить командой на изображение или вставить сохранённое через /saveas в теге <img>имя</img>. '
                    . 'По тексту и/или картинке будет написана короткая песня и сгенерирован видеоклип.',
                ),
                true,
            );
        }

        ReactionSetter::setMessageReaction($message, '👀');

        try {
            if ($imageContents === null) {
                $progressUpdateCallback(
                    static::class,
                    "Generating a starting frame for: $userText",
                    new ChatAction($message->chatId, ChatActionEnum::upload_photo),
                );
                $imageContents = $this->assistedVideoProcessor->generateFirstFrameImage(
                    $message->chatId,
                    $userText,
                    $progressUpdateCallback,
                );
            }

            ReactionSetter::setMessageReaction($message, '✍');
            $progressUpdateCallback(
                static::class,
                'Writing lyrics for the song...',
                new ChatAction($message->chatId, ChatActionEnum::typing),
            );
            $song = $this->generateLyricsAndTags($userText, $imageContents, $progressUpdateCallback);
            if ($song === null) {
                ReactionSetter::setMessageReaction($message, '🤔');

                return new ProcessingResult(
                    InternalMessage::asResponseTo($message, 'Не удалось написать текст песни, попробуйте ещё раз.'),
                    true,
                );
            }
            [$tags, $lyrics] = $song;

            ReactionSetter::setMessageReaction($message, '👨‍💻');
            $audio = $this->singProcessor->generateSongAudio(
                $message,
                $tags,
                $lyrics,
                self::SONG_DURATION_MS,
                $progressUpdateCallback,
            );

            ReactionSetter::setMessageReaction($message, '⚡');
            $videoPrompt = $userText;
            if ($videoPrompt === '') {
                $videoPrompt = $this->altTextProvider->generateAltTextForImageString(
                    $imageContents,
                    $progressUpdateCallback
                );
            }
            $progressUpdateCallback(
                static::class,
                'Generating the music video...',
                new ChatAction($message->chatId, ChatActionEnum::record_video),
            );
            $videoResponse = $this->audioImgTxt2VidClient->generateByPromptImageAndAudio(
                $audio,
                $imageContents,
                $videoPrompt,
                $message->userId,
            );

            // VideoResponder removes the reaction on success (like /image).
            $this->videoResponder->sendVideo(
                $message,
                $videoResponse->getFirstVideoAsMp4(),
                $videoResponse->getCaption(),
            );
        } catch (Exception $e) {
            $this->logger->log(
                LogLevel::ERROR,
                "Failed to generate music video:\n" . $e->getMessage() . "\n" . $e->getTraceAsString(),
            );
            ReactionSetter::setMessageReaction($message, '🤔');
        }

        return new ProcessingResult(null, true);
    }

    /**
     * Has the lyrics assistant write genre tags + lyrics for a ~20s song from
     * the text and/or image. The image is passed directly when the assistant
     * supports images; otherwise it is described via alt text (same as the
     * normal assistant message path).
     *
     * @return array{0:string,1:string}|null  [$tags, $lyrics], or null on parse failure
     */
    private function generateLyricsAndTags(
        string $theme,
        string $imageContents,
        ProgressUpdateCallback $progressUpdateCallback
    ): ?array {
        $context = new AssistantContext();
        $context->systemPrompt = <<<PROMPT
You are a songwriter and music producer. Given a theme, idea, or description (and optionally an image), write original song lyrics and choose musical genres that fit it. The song must be short — about 20 seconds long (roughly 4 to 8 lines of lyrics).

Respond in EXACTLY this format and nothing else:
- The FIRST line must be a comma-separated list of genre/style tags in English (e.g. "synthpop, upbeat, female vocals, electronic").
- Every following line is the lyrics, structured with section markers in square brackets: [Verse 1], [Chorus], [Verse 2], [Bridge], [Outro], etc.
- Write the lyrics in the same language as the user's message.
- Do NOT output any explanations, introductions, titles, or markdown/code formatting. Output only the tags line followed by the lyrics.
PROMPT;

        $userMessage = new AssistantContextMessage();
        $userMessage->isUser = true;

        $lyricsAssistantSupportsImages = property_exists($this->lyricsAssistant, 'supportsImages')
            && $this->lyricsAssistant->supportsImages === true;

        if ($lyricsAssistantSupportsImages) {
            $userMessage->photo = $imageContents;
            $userMessage->text = $theme !== ''
                ? $theme
                : 'Write a short song inspired by this image.';
        } else {
            $themeParts = [];
            if ($theme !== '') {
                $themeParts[] = $theme;
            }
            $themeParts[] = "Image description:\n" . $this->altTextProvider->generateAltTextForImageString(
                    $imageContents,
                    $progressUpdateCallback,
                );
            $userMessage->text = implode("\n\n", $themeParts);
        }
        $context->messages[] = $userMessage;

        $formatted = trim($this->lyricsAssistant->getCompletionBasedOnContext($context)->content);
        $this->logger->log(LogLevel::INFO, 'Generated song for /mvid: ' . $formatted);

        $lines = explode("\n", $formatted);
        while ($lines !== [] && trim($lines[0]) === '') {
            array_shift($lines);
        }
        if (count($lines) < 2) {
            return null;
        }
        $tags = trim($lines[0]);
        $lyrics = trim(implode("\n", array_slice($lines, 1)));

        return [$tags, $lyrics];
    }

}
