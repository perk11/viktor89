<?php

namespace Perk11\Viktor89;

use Amp\Cancellation;
use Amp\Parallel\Worker\Task;
use Amp\Sync\Channel;
use Dom\HTMLDocument;
use GuzzleHttp\Client;
use Longman\TelegramBot\Entities\Message;
use Longman\TelegramBot\Telegram;
use Perk11\Viktor89\Assistant\AssistantContext;
use Perk11\Viktor89\Repository\MessageRepository;
use Perk11\Viktor89\Repository\PatchRepository;
use Perk11\Viktor89\Util\Telegram\BotAdminChecker;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

class PatchesMonitorTask implements Task
{
    public function __construct(
        private readonly int $telegramBotId,
        private readonly string $telegramApiKey,
        private readonly string $telegramBotUsername,
        private readonly LoggerInterface $logger,
    ) {
    }

    const string PATCHES_URL = 'https://patches.kibab.com/utils/plast.php5';

    public function run(?Channel $channel = null, ?Cancellation $cancellation = null): bool
    {
        InternalMessage::setLogger($this->logger);
        AssistantContext::setLogger($this->logger);
        BotAdminChecker::setLogger($this->logger);
        $database = new Database($this->telegramBotId, 'siepatch-non-instruct5');
        $messageRepository = new MessageRepository($database);
        $patchRepository = new PatchRepository($database);

        $client = new Client();
        $response = $client->request('GET', self::PATCHES_URL);
        if ($response->getStatusCode() !== 200) {
            $this->logger->log(LogLevel::ERROR, 'Failed to get last added patches list: ' . $response->getReasonPhrase() . $response->getBody());

            return false;
        }

        $htmlDocument = @HTMLDocument::createFromString($response->getBody());
        $patchLinks = $htmlDocument->querySelectorAll('.cnt_td>a');

        $formattedPatches = [];
        $patchLinkStrings = [];
        foreach ($patchLinks as $patchLink) {
            $href = $patchLink->getAttribute('href');
            $patchLinkStrings[] = $href;
            $formattedPatches[$href] = $patchLink->previousSibling->textContent . $patchLink->textContent . ' - ' . $href;
        }
        $missingPatchLinks = $patchRepository->findMissingPatches($patchLinkStrings);
        if (count($missingPatchLinks) === 0) {
            return true;
        }
        $telegram = new Telegram($this->telegramApiKey, $this->telegramBotUsername);


        foreach ($missingPatchLinks as $missingPatchLink) {
            $this->logger->log(LogLevel::INFO, 'Found new patch! ' . $formattedPatches[$missingPatchLink]);

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
                $messageRepository->logInternalMessage($message);
                $patchRepository->insertPatch($missingPatchLink);
            } else {
                $this->logger->log(LogLevel::ERROR, 'Failed to send response: ' . print_r($telegramServerResponse->getRawData(), true));
            }
            sleep(1);
        }

        return true;
    }
}
