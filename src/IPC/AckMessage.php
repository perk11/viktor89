<?php

namespace Perk11\Viktor89\IPC;

/**
 * Generic acknowledgement sent back to a worker in response to a request
 * message (e.g. MessageAboutToBeSentMessage), signalling that the main
 * process has finished reacting to it.
 */
class AckMessage extends ChannelMessage
{
}
