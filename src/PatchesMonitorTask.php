<?php

namespace Perk11\Viktor89;

use Amp\Cancellation;
use Amp\Parallel\Worker\Task;
use Amp\Sync\Channel;
use GuzzleHttp\Client;
use Longman\TelegramBot\Entities\Message;
use Longman\TelegramBot\Telegram;

class PatchesMonitorTask implements Task
{
    public function __construct(
        private readonly int $telegramBotId,
        private readonly string $telegramApiKey,
        private readonly string $telegramBotUsername,
    ) {
    }

    const string PATCHES_URL = 'https://patches.kibab.com/utils/plast.php5';

    public function run(?Channel $channel = null, ?Cancellation $cancellation = null): bool
    {
        $database = new Database($this->telegramBotId, 'siepatch-non-instruct5');

        $client = new Client();
        $response = $client->request('GET', self::PATCHES_URL);
        if ($response->getStatusCode() !== 200) {
            echo "Failed to get last added patches list: " . $response->getReasonPhrase() . $response->getBody() . "\n";

            return false;
        }

        $htmlDocument = @\Dom\HTMLDocument::createFromString($response->getBody());
        $patchLinks = $htmlDocument->querySelectorAll('.cnt_td>a');

        $formattedPatches = [];
        $patchLinkStrings = [];
        foreach ($patchLinks as $patchLink) {
            $href = $patchLink->getAttribute('href');
            $patchLinkStrings[] = $href;
            $formattedPatches[$href] = $patchLink->previousSibling->textContent . $patchLink->textContent . ' - ' . $href;
        }
        $missingPatchLinks = $database->findMissingPatches($patchLinkStrings);
        if (count($missingPatchLinks) === 0) {
            return true;
        }
        $telegram = new Telegram($this->telegramApiKey, $this->telegramBotUsername);


        foreach ($missingPatchLinks as $missingPatchLink) {
            echo "Found new patch! " . $formattedPatches[$missingPatchLink];

            $message = new InternalMessage();
            $message->messageText = "Новый патч в базе! \n" . $formattedPatches[$missingPatchLink];
            $message->chatId = -1001804789551;

            $telegramServerResponse = $message->send();
            if ($telegramServerResponse->isOk() && $telegramServerResponse->getResult() instanceof Message) {
                $message->id = $telegramServerResponse->getResult()->getMessageId();
                $message->userId = $telegramServerResponse->getResult()->getFrom()->getId();
                $message->userName = $this->telegramBotUsername;
                $message->date = time();
                $message->type = 'text';
                $database->logInternalMessage($message);
                $database->insertPatch($missingPatchLink);
            } else {
                echo "Failed to send response: " . print_r($telegramServerResponse->getRawData(), true) . "\n";
            }
            sleep(1);
        }

        return true;
    }
}
