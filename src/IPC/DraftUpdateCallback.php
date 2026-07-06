<?php

namespace Perk11\Viktor89\IPC;

/**
 * Used by streaming assistants to forward the latest draft content out of the
 * worker process (typically to the main process via an IPC channel) so that the
 * draft can be sent to Telegram and kept alive there.
 */
interface DraftUpdateCallback
{
    public function updateDraft(DraftState $draft): void;
}
