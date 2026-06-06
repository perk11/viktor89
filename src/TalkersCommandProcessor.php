<?php

namespace Perk11\Viktor89;

use Perk11\Viktor89\IPC\ProgressUpdateCallback;
use Perk11\Viktor89\Util\TelegramHtml;

class TalkersCommandProcessor implements MessageChainProcessor, GetTriggeringCommandsInterface
{
    public function __construct(private readonly Database $database)
    {
    }

    public function processMessageChain(MessageChain $messageChain, ProgressUpdateCallback $progressUpdateCallback): ProcessingResult
    {
        $chatId = $messageChain->last()->chatId;
        $topTalkersDataRecords = $this->database->findTopTalkersInChat($chatId);

        $responseMessageToReturn = InternalMessage::asResponseTo($messageChain->last());
        $responseMessageToReturn->parseMode = 'HTML';

        if ($topTalkersDataRecords === []) {
            $responseMessageToReturn->messageText = 'За последние 30 дней в этом чате ещё никто не писал (или я ничего не запомнил).';
            return new ProcessingResult($responseMessageToReturn, true);
        }

        $maximumDisplayedNameLengthLimitForMobile = 12;
        $totalCalculatedRowWidthForDivider = 42;

        $responseMessageToReturn->messageText = "<b>Самые общительные за последние 30 дней:</b>\n\n";
        $responseMessageToReturn->messageText .= '<pre>';

        $headerRowUnescapedString = $this->formatLeaderboardRowWithPadding(
            rankColumnText: '№',
            nameColumnText: 'Имя',
            messageCountColumnText: 'Сооб',
            stickerCountColumnText: 'Стк',
            otherCountColumnText: 'Др',
            wordCountColumnText: 'Слов',
            averageWordsColumnText: 'Сл/Сб',
            maximumNameColumnLength: $maximumDisplayedNameLengthLimitForMobile
        );

        $responseMessageToReturn->messageText .= TelegramHtml::escape($headerRowUnescapedString);
        $responseMessageToReturn->messageText .= str_repeat('-', $totalCalculatedRowWidthForDivider) . "\n";

        $currentLeaderboardRankPosition = 1;
        foreach ($topTalkersDataRecords as $individualTalkerRecord) {
            $rawTalkerNameString = $individualTalkerRecord['username'] !== '' && $individualTalkerRecord['username'] !== null
                ? $individualTalkerRecord['username']
                : sprintf('без имени (%s)', $individualTalkerRecord['user_id']);

            $sanitizedTalkerNameString = $this->sanitizeUsernameForMonospaceGrid($rawTalkerNameString);

            if ($sanitizedTalkerNameString === '') {
                $sanitizedTalkerNameString = sprintf('ID: %s', $individualTalkerRecord['user_id']);
            }

            $averageWordsPerMessageFloat = $individualTalkerRecord['text_count'] > 0
                ? round($individualTalkerRecord['word_count'] / $individualTalkerRecord['text_count'], 1)
                : 0.0;

            $talkerDataRowUnescapedString = $this->formatLeaderboardRowWithPadding(
                rankColumnText: (string)$currentLeaderboardRankPosition,
                nameColumnText: $sanitizedTalkerNameString,
                messageCountColumnText: $this->formatLargeNumberWithSuffixToFitColumnBounds($individualTalkerRecord['message_count'], 5),
                stickerCountColumnText: $this->formatLargeNumberWithSuffixToFitColumnBounds($individualTalkerRecord['sticker_count'], 4),
                otherCountColumnText: $this->formatLargeNumberWithSuffixToFitColumnBounds($individualTalkerRecord['other_count'], 3),
                wordCountColumnText: $this->formatLargeNumberWithSuffixToFitColumnBounds($individualTalkerRecord['word_count'], 5),
                averageWordsColumnText: $this->formatLargeNumberWithSuffixToFitColumnBounds($averageWordsPerMessageFloat, 5),
                maximumNameColumnLength: $maximumDisplayedNameLengthLimitForMobile
            );

            $responseMessageToReturn->messageText .= TelegramHtml::escape($talkerDataRowUnescapedString);
            $currentLeaderboardRankPosition++;
        }

        $responseMessageToReturn->messageText .= '</pre>';
        return new ProcessingResult($responseMessageToReturn, true);
    }

    public function getTriggeringCommands(): array
    {
        return ['/talkers'];
    }

    private function formatLeaderboardRowWithPadding(
        string $rankColumnText,
        string $nameColumnText,
        string $messageCountColumnText,
        string $stickerCountColumnText,
        string $otherCountColumnText,
        string $wordCountColumnText,
        string $averageWordsColumnText,
        int $maximumNameColumnLength
    ): string {
        $rankPaddedColumnString = $this->applyMultibytePaddingToEnsureVisualWidth($rankColumnText, 2, STR_PAD_RIGHT);
        $namePaddedColumnString = $this->applyMultibytePaddingToEnsureVisualWidth($nameColumnText, $maximumNameColumnLength, STR_PAD_RIGHT);
        $messagesPaddedColumnString = $this->applyMultibytePaddingToEnsureVisualWidth($messageCountColumnText, 5, STR_PAD_LEFT);
        $stickersPaddedColumnString = $this->applyMultibytePaddingToEnsureVisualWidth($stickerCountColumnText, 4, STR_PAD_LEFT);
        $otherPaddedColumnString = $this->applyMultibytePaddingToEnsureVisualWidth($otherCountColumnText, 3, STR_PAD_LEFT);
        $wordsPaddedColumnString = $this->applyMultibytePaddingToEnsureVisualWidth($wordCountColumnText, 5, STR_PAD_LEFT);
        $averagePaddedColumnString = $this->applyMultibytePaddingToEnsureVisualWidth($averageWordsColumnText, 5, STR_PAD_LEFT);

        return sprintf(
            "%s %s %s %s %s %s %s\n",
            $rankPaddedColumnString,
            $namePaddedColumnString,
            $messagesPaddedColumnString,
            $stickersPaddedColumnString,
            $otherPaddedColumnString,
            $wordsPaddedColumnString,
            $averagePaddedColumnString
        );
    }

    private function applyMultibytePaddingToEnsureVisualWidth(string $textStringToPad, int $targetVisualWidthLength, int $paddingDirectionConstraint): string
    {
        $currentVisibleStringLength = mb_strwidth($textStringToPad, 'UTF-8');
        $amountOfPaddingSpaceCharactersNeeded = $targetVisualWidthLength - $currentVisibleStringLength;

        if ($amountOfPaddingSpaceCharactersNeeded <= 0) {
            return mb_strimwidth($textStringToPad, 0, $targetVisualWidthLength, '', 'UTF-8');
        }

        $paddingSpacesStringVariable = str_repeat(' ', $amountOfPaddingSpaceCharactersNeeded);

        if ($paddingDirectionConstraint === STR_PAD_LEFT) {
            return $paddingSpacesStringVariable . $textStringToPad;
        }

        return $textStringToPad . $paddingSpacesStringVariable;
    }

    private function formatLargeNumberWithSuffixToFitColumnBounds(int|float $numericValueToFormat, int $maximumAllowedCharacterLength): string
    {
        if (is_float($numericValueToFormat)) {
            $formattedFloatString = sprintf('%.1f', $numericValueToFormat);
            if (mb_strwidth($formattedFloatString, 'UTF-8') <= $maximumAllowedCharacterLength) {
                return $formattedFloatString;
            }
            return mb_strimwidth((string)round($numericValueToFormat), 0, $maximumAllowedCharacterLength, '', 'UTF-8');
        }

        if ($numericValueToFormat >= 1_000_000) {
            $formattedMillionStringWithDecimal = sprintf('%.1fM', $numericValueToFormat / 1_000_000);
            if (mb_strwidth($formattedMillionStringWithDecimal, 'UTF-8') <= $maximumAllowedCharacterLength) {
                return $formattedMillionStringWithDecimal;
            }
            return sprintf('%dM', (int)round($numericValueToFormat / 1_000_000));
        }

        if ($numericValueToFormat >= 1_000) {
            $formattedThousandStringWithDecimal = sprintf('%.1fk', $numericValueToFormat / 1_000);
            if (mb_strwidth($formattedThousandStringWithDecimal, 'UTF-8') <= $maximumAllowedCharacterLength) {
                return $formattedThousandStringWithDecimal;
            }

            $formattedThousandStringAsInteger = sprintf('%dk', (int)round($numericValueToFormat / 1_000));
            if (mb_strwidth($formattedThousandStringAsInteger, 'UTF-8') <= $maximumAllowedCharacterLength) {
                return $formattedThousandStringAsInteger;
            }
        }

        return mb_strimwidth((string)$numericValueToFormat, 0, $maximumAllowedCharacterLength, '', 'UTF-8');
    }

    private function sanitizeUsernameForMonospaceGrid(string $rawUsernameString): string
    {
        // Emojis are NOT so they will appear in the output.
        // We ONLY strip known invisible filler characters (e.g., Hangul Filler U+3164, Braille blank U+2800, zero-width spaces)
        // because they provide no visual element to the user and completely destroy the grid padding.
        $sanitizedString = preg_replace('/[\x{3164}\x{2800}\x{FFA0}\x{200B}-\x{200F}\x{2028}-\x{202F}\x{2060}-\x{206F}]/u', '', $rawUsernameString);

        return trim($sanitizedString);
    }
}
