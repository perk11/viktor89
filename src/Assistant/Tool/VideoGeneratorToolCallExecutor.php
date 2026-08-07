<?php

namespace Perk11\Viktor89\Assistant\Tool;

use Perk11\Viktor89\IPC\EchoUpdateCallback;
use Perk11\Viktor89\MessageChain;
use Perk11\Viktor89\VideoGeneration\VideoProcessor;

/**
 * Generates a video from a prompt exactly like the /video command and sends it
 * to the user directly, then reports the outcome back to the model.
 *
 * The VideoProcessor is injected through setVideoProcessor() rather than the
 * constructor to break a dependency cycle: VideoProcessor depends on
 * AssistantFactory (via the prompt preprocessor factory), while AssistantFactory
 * depends on this tool. Wiring always calls the setter before any message is
 * processed, so the processor is available by the time the model invokes it.
 */
class VideoGeneratorToolCallExecutor implements MessageChainAwareToolCallExecutorInterface
{
    private ?VideoProcessor $videoProcessor = null;

    public function __construct(
        private readonly \Psr\Log\LoggerInterface $logger,
    ) {
    }

    public function setVideoProcessor(VideoProcessor $videoProcessor): void
    {
        $this->videoProcessor = $videoProcessor;
    }

    public function executeToolCall(array $arguments, MessageChain $messageChain): array
    {
        if (!isset($arguments['prompt'])) {
            throw new \InvalidArgumentException('Prompt is required');
        }
        if (!is_string($arguments['prompt'])) {
            throw new \InvalidArgumentException('Prompt must be a string');
        }
        foreach ($arguments as $key => $value) {
            if ($key !== 'prompt') {
                throw new \InvalidArgumentException("Unsupported argument: $key");
            }
        }

        if ($this->videoProcessor === null) {
            throw new \RuntimeException('VideoProcessor was not injected into VideoGeneratorToolCallExecutor');
        }

        $result = $this->videoProcessor->generateVideo(
            $messageChain,
            $arguments['prompt'],
            new EchoUpdateCallback(logger: $this->logger),
        );

        // Validation failures (unsupported references, too many references, no
        // last-frame model, ...) surface a user-facing reply — forward the text
        // so the model can recover. A null response means the video was
        // generated and sent (or generation failed, which /video signals with a
        // 🤔 reaction on the message).
        if ($result->response !== null) {
            return [
                'status'  => 'failed',
                'content' => $result->response->messageText,
            ];
        }

        return [
            'status'     => 'video_succesfully_generated_and_sent_to_user',
            'directions' => 'Do not attempt to send or describe the video. The user has the generated video and you cannot embed it again.',
        ];
    }
}
