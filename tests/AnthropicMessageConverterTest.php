<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\Assistant\AnthropicMessageConverter;
use Perk11\Viktor89\Assistant\AssistantContext;
use Perk11\Viktor89\Assistant\AssistantContextMessage;
use Perk11\Viktor89\Assistant\Tool\ToolCall;
use PHPUnit\Framework\Attributes\CoversClass;
use Perk11\Viktor89\Test\Support\TestImages;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Support/AssistantTestFixtures.php';

#[CoversClass(AnthropicMessageConverter::class)]
class AnthropicMessageConverterTest extends TestCase
{
    public function testSystemPromptIsMovedToTopLevel(): void
    {
        $context = self::context(self::user('Hi'));
        $context->systemPrompt = 'Be nice';

        $result = (new AnthropicMessageConverter())->convert($context, true);

        $this->assertSame('Be nice', $result['system']);
        $this->assertSame([
            ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Hi']]],
        ], $result['messages']);
    }

    public function testToolCallsBecomeToolUseAndToolResultBlocksBeforeUserText(): void
    {
        $toolCallMessage = self::bot('Searching');
        $toolCallMessage->toolCalls = [new ToolCall('call.1', 'web_search', '{"query":"cats"}', '{"results":[]}')];

        $messages = (new AnthropicMessageConverter())->convert(
            self::context(self::user('Search for cats'), $toolCallMessage, self::user('Thanks')),
            true,
        )['messages'];

        $this->assertSame([
            ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Search for cats']]],
            ['role' => 'assistant', 'content' => [
                ['type' => 'text', 'text' => 'Searching'],
                ['type' => 'tool_use', 'id' => 'call_1', 'name' => 'web_search', 'input' => ['query' => 'cats']],
            ]],
            ['role' => 'user', 'content' => [
                ['type' => 'tool_result', 'toolUseID' => 'call_1', 'content' => '{"results":[]}'],
                ['type' => 'text', 'text' => 'Thanks'],
            ]],
        ], $messages);
    }

    public function testToolCallsAreDroppedWhenNotIncluded(): void
    {
        $toolCallMessage = self::bot('Done');
        $toolCallMessage->toolCalls = [new ToolCall('call_1', 'web_search', '{}', '{}')];

        $messages = (new AnthropicMessageConverter())->convert(
            self::context(self::user('Go'), $toolCallMessage),
            false,
        )['messages'];

        $this->assertSame([
            ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Go']]],
            ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'Done']]],
            ['role' => 'user', 'content' => [['type' => 'text', 'text' => '[Message without text content]']]],
        ], $messages);
    }

    public function testToolCallWithoutStoredResultGetsEmptyToolResult(): void
    {
        $toolCallMessage = self::bot('');
        $toolCallMessage->toolCalls = [new ToolCall('call_1', 'react', 'not json')];

        $messages = (new AnthropicMessageConverter())->convert(
            self::context(self::user('Hi'), $toolCallMessage),
            true,
        )['messages'];

        $this->assertSame(['arguments' => 'not json'], $messages[1]['content'][0]['input']);
        $this->assertSame([['type' => 'tool_result', 'toolUseID' => 'call_1']], $messages[2]['content']);
    }

    public function testUserPhotoBecomesBase64ImageBlock(): void
    {
        $png = TestImages::png(4, 4);
        $message = self::user('What is this?');
        $message->photo = $png;

        $result = (new AnthropicMessageConverter())->convert(self::context($message), true);

        $this->assertSame([
            ['type' => 'text', 'text' => 'What is this?'],
            ['type' => 'image', 'source' => ['type' => 'base64', 'mediaType' => 'image/png', 'data' => base64_encode($png)]],
        ], $result['messages'][0]['content']);
    }

    /**
     * Anthropic rejects images in assistant turns, so a photo the bot sent
     * (e.g. one it generated) must reach the model through the next user turn,
     * after the tool results it follows.
     */
    public function testImageSentByBotIsMovedToNextUserTurnAfterToolResults(): void
    {
        $toolCallMessage = self::bot('Here it is');
        $toolCallMessage->toolCalls = [new ToolCall('call_1', 'image_gen_tool', '{"prompt":"cat"}', '{"status":"ok"}')];
        $photo = self::bot('');
        $photo->photo = TestImages::png(4, 4);

        $messages = (new AnthropicMessageConverter())->convert(
            self::context(self::user('Draw a cat'), $toolCallMessage, $photo, self::user('Make it blue')),
            true,
        )['messages'];

        $this->assertSame(['user', 'assistant', 'user'], array_column($messages, 'role'));
        foreach ($messages[1]['content'] as $block) {
            $this->assertNotSame('image', $block['type'], 'assistant turns must not carry images');
        }
        $this->assertSame(
            ['tool_result', 'text', 'image', 'text'],
            array_column($messages[2]['content'], 'type'),
        );
        $this->assertSame('Make it blue', $messages[2]['content'][3]['text']);
    }

    public function testConsecutiveMessagesWithTheSameRoleAreMerged(): void
    {
        $messages = (new AnthropicMessageConverter())->convert(
            self::context(self::user('Hi'), self::user('Anyone?'), self::bot('Yes'), self::bot('Hello')),
            true,
        )['messages'];

        $this->assertSame([
            ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Hi'], ['type' => 'text', 'text' => 'Anyone?']]],
            ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'Yes'], ['type' => 'text', 'text' => 'Hello']]],
            ['role' => 'user', 'content' => [['type' => 'text', 'text' => '[Message without text content]']]],
        ], $messages);
    }

    public function testConversationStartingWithAssistantGetsUserPlaceholder(): void
    {
        $messages = (new AnthropicMessageConverter())->convert(
            self::context(self::bot('Daily summary'), self::user('Tell me more')),
            true,
        )['messages'];

        $this->assertSame(['user', 'assistant', 'user'], array_column($messages, 'role'));
    }

    public function testWhitespaceOnlyTextIsDropped(): void
    {
        $messages = (new AnthropicMessageConverter())->convert(
            self::context(self::user('  '), self::user('Hi')),
            true,
        )['messages'];

        $this->assertSame([['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Hi']]]], $messages);
    }

    public function testResponseStartIsRejected(): void
    {
        $context = self::context(self::user('Hi'));
        $context->responseStart = 'Sure,';

        $this->expectException(\InvalidArgumentException::class);
        (new AnthropicMessageConverter())->convert($context, true);
    }

    public function testImagePartIsBase64ImageBlockAndKeepsWebp(): void
    {
        $webp = TestImages::webp(4, 4);

        $block = (new AnthropicMessageConverter())->buildImagePart($webp);

        $this->assertSame(
            ['type' => 'image', 'source' => ['type' => 'base64', 'mediaType' => 'image/webp', 'data' => base64_encode($webp)]],
            $block,
        );
    }

    public function testUndecodableImageIsDropped(): void
    {
        $this->assertNull((new AnthropicMessageConverter())->buildImagePart('not an image'));
    }

    /**
     * A last user message with no usable content (e.g. a document without alt
     * text) is dropped, which would leave an assistant turn last: the API
     * treats that as a prefill and rejects it.
     */
    public function testWhitespaceOnlyLastUserMessageStillEndsWithUserTurn(): void
    {
        $messages = (new AnthropicMessageConverter())->convert(
            self::context(self::user('Hi'), self::bot('Hello'), self::user('  ')),
            true,
        )['messages'];

        $this->assertSame(['user', 'assistant', 'user'], array_column($messages, 'role'));
        $this->assertSame('text', $messages[2]['content'][0]['type']);
        $this->assertNotSame('', trim($messages[2]['content'][0]['text']));
    }

    public function testContextWithoutUsableMessagesBecomesSingleUserTurn(): void
    {
        $messages = (new AnthropicMessageConverter())->convert(self::context(self::user(' ')), true)['messages'];

        $this->assertCount(1, $messages);
        $this->assertSame('user', $messages[0]['role']);
    }

    /**
     * The image a tool generated during the previous reply is stored as a bot
     * photo; it must be moved into the next user turn ahead of that turn's
     * own photo, after the tool results.
     */
    public function testBotPhotoFromToolLoopIsMovedBeforeTheNextUsersPhoto(): void
    {
        $toolCallMessage = self::bot('');
        $toolCallMessage->toolCalls = [new ToolCall('toolu_1', 'image_gen_tool', '{"prompt":"cat"}', '{"status":"ok"}')];
        $botPhoto = self::bot('');
        $botPhoto->photo = TestImages::png(2, 2);
        $userPhoto = self::user('Make it look like this');
        $userPhoto->photo = TestImages::jpeg(3, 3);

        $messages = (new AnthropicMessageConverter())->convert(
            self::context(self::user('Draw a cat'), $toolCallMessage, $botPhoto, $userPhoto),
            true,
        )['messages'];

        $this->assertSame(['user', 'assistant', 'user'], array_column($messages, 'role'));
        $lastTurn = $messages[2]['content'];
        $this->assertSame(['tool_result', 'text', 'image', 'text', 'image'], array_column($lastTurn, 'type'));
        $this->assertSame('image/png', $lastTurn[2]['source']['mediaType']);
        $this->assertSame('image/jpeg', $lastTurn[4]['source']['mediaType']);
    }

    private static function context(AssistantContextMessage ...$messages): AssistantContext
    {
        $context = new AssistantContext();
        $context->messages = $messages;

        return $context;
    }

    private static function user(string $text): AssistantContextMessage
    {
        $message = new AssistantContextMessage();
        $message->isUser = true;
        $message->text = $text;

        return $message;
    }

    private static function bot(string $text): AssistantContextMessage
    {
        $message = self::user($text);
        $message->isUser = false;

        return $message;
    }
}
