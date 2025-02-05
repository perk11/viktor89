<?php
namespace Perk11\Viktor89;

class AllowedChatProcessor implements MessageChainProcessor
{
    public function __construct(private readonly array $allowedChatIds)
    {
    }

    public function processMessageChain(MessageChain $messageChain): ProcessingResult
    {
        $lastMessage = $messageChain->last();
        if ($lastMessage->isCommand()) { //This would need to be reworked to make this processor work for commands
            return new ProcessingResult(null, false);
        }
        if (!in_array($lastMessage->chatId, $this->allowedChatIds, false)) {
            $message = InternalMessage::asResponseTo(
                $lastMessage,
                'Эта функция отключена в вашем чате 🤣🤣🤣'
            );

            return new ProcessingResult($message, true);
        }

        return new ProcessingResult(null, false);

    }
}
