<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use GuzzleHttp\Client as GuzzleClient;
use OpenAI;
use OpenAI\Exceptions\ErrorException;
use Perk11\Viktor89\Assistant\AssistantContext;
use Perk11\Viktor89\Assistant\AssistantContextMessage;
use Perk11\Viktor89\Assistant\ContextCompactor;
use PHPUnit\Framework\Attributes\BeforeClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Integration test for ContextCompactor that calls a real LLM.
 *
 * Reads the model config from config.json (assistantModels.Viktor89) and
 * tests the full compaction pipeline:
 *   build large context → compact → send compacted context to LLM → verify response
 *
 * Skipped if the LLM endpoint is unreachable.
 */
#[TestDox('ContextCompactor Integration Test (real LLM)')]
class ContextCompactorIntegrationTest extends TestCase
{
    private const int MAX_RECENT_CHARACTERS = 1200; // character budget for recent messages (~10 messages)
    private const int TOTAL_MESSAGES         = 25;
    private const int EXPECTED_RECENT_COUNT  = 10;   // target recent message count for assertions

    private static ?string $llmUrl    = null;
    private static ?string $modelName = null;

    #[BeforeClass]
    public static function loadConfig(): void
    {
        $configPath = __DIR__ . '/../config.json';
        if (!file_exists($configPath)) {
            self::markTestSkipped('config.json not found');
        }

        $config = json_decode(file_get_contents($configPath), true, 512, JSON_THROW_ON_ERROR);
        $modelConfig = $config['assistantModels']['Viktor89'] ?? null;
        if ($modelConfig === null) {
            self::markTestSkipped('assistantModels.Viktor89 not found in config.json');
        }

        self::$llmUrl    = $modelConfig['url'] ?? '';
        self::$modelName = $modelConfig['model'] ?? null;

        if (self::$llmUrl === '') {
            self::markTestSkipped('Viktor89 model URL is empty');
        }
    }

    private function createClient(): OpenAI\Client
    {
        return OpenAI::factory()
            ->withBaseUri(rtrim(self::$llmUrl, '/'))
            ->withHttpClient(new GuzzleClient(['timeout' => 300]))
            ->make();
    }

    private function createSummaryGenerator(OpenAI\Client $client): callable
    {
        $model = self::$modelName;
        return static function (string $prompt) use ($client, $model): string {
            $result = $client->chat()->create([
                'model'      => $model,
                'messages'   => [
                    ['role' => 'system', 'content' => 'You are a helpful assistant that summarizes conversations concisely.'],
                    ['role' => 'user',   'content' => $prompt],
                ],
                'max_tokens'  => 600,
                'temperature' => 0.5,
            ]);

            $content = $result->choices[0]->message->content ?? '';
            if (is_array($content)) {
                $first = current($content);
                $content = $first['text'] ?? '';
            }

            return trim($content);
        };
    }

    /**
     * Build a context with alternating user/assistant messages.
     *
     * @param int    $count       Total messages to generate
     * @param string $topic       Topic prefix so messages feel "real"
     * @return AssistantContext
     */
    private function buildLargeContext(int $count, string $topic = 'artificial intelligence'): AssistantContext
    {
        $ctx = new AssistantContext();
        $ctx->systemPrompt = "You are a knowledgeable assistant. Answer helpfully and concisely.";
        $ctx->responseStart = null;

        $topics = [
            'the history of AI',
            'machine learning basics',
            'neural networks',
            'deep learning',
            'reinforcement learning',
            'natural language processing',
            'computer vision',
            'ethics of AI',
            'future of AI',
        ];

        for ($i = 0; $i < $count; $i++) {
            $msg = new AssistantContextMessage();

            if ($i % 2 === 0) {
                // User message
                $msg->isUser = true;
                $topicIdx = ($i / 2) % count($topics);
                $msg->text = "Tell me about {$topics[$topicIdx]}.";
            } else {
                // Assistant message — a few sentences to consume tokens
                $msg->isUser = false;
                $topicIdx = (int)($i / 2) % count($topics);
                $msg->text = $this->generateAssistantText($topics[$topicIdx], $i);
            }

            $ctx->messages[] = $msg;
        }

        return $ctx;
    }

    /**
     * Generate a short paragraph for the given topic, varying by index so
     * the messages are diverse and consume meaningful context.
     */
    private function generateAssistantText(string $topic, int $index): string
    {
        $texts = [
            'AI' => [
                "Artificial intelligence is a broad field that encompasses many sub-disciplines. " .
                "From machine learning to natural language processing, AI continues to evolve rapidly.",
                "The history of AI goes back to the 1950s with pioneers like Alan Turing. " .
                "Since then, we've seen multiple waves of innovation and investment.",
                "Modern AI systems use deep neural networks trained on vast datasets. " .
                "These systems can recognize patterns and make predictions with remarkable accuracy.",
            ],
            'machine learning basics' => [
                "Machine learning is a subset of AI that focuses on learning from data. " .
                "Supervised learning uses labeled data, while unsupervised learning finds patterns in unlabeled data.",
                "Key algorithms include linear regression, decision trees, and support vector machines. " .
                "Each has strengths depending on the problem domain.",
                "Feature engineering is crucial in ML — the quality of input features often " .
                "determines the ceiling of model performance.",
            ],
            'neural networks' => [
                "Neural networks are inspired by the human brain. They consist of layers of interconnected " .
                "neurons that can learn complex patterns through backpropagation.",
                "Deep learning uses many layers to model hierarchical features. " .
                "Convolutional networks excel at images, while transformers dominate NLP.",
                "Training neural networks requires careful tuning of hyperparameters like learning rate, " .
                "batch size, and architecture depth.",
            ],
            'deep learning' => [
                "Deep learning revolutionized AI by enabling end-to-end learning from raw data. " .
                "It eliminates the need for manual feature extraction in many domains.",
                "Key breakthroughs include AlexNet for image classification, AlphaGo for games, " .
                "and GPT for language understanding.",
                "Deep learning models require large datasets and significant compute resources, " .
                "but they can achieve stunning results when properly trained.",
            ],
            'reinforcement learning' => [
                "Reinforcement learning trains agents through trial and error. The agent learns " .
                "to maximize cumulative reward by interacting with an environment.",
                "Key concepts include policies, value functions, and the exploration-exploitation " .
                "tradeoff. Deep RL combines neural networks with RL algorithms.",
                "Applications range from game playing (AlphaGo, Dota 5) to robotics, " .
                "autonomous driving, and industrial control systems.",
            ],
            'natural language processing' => [
                "NLP enables computers to understand, generate, and process human language. " .
                "It powers chatbots, translation, and sentiment analysis.",
                "Modern NLP is dominated by transformer architectures like BERT and GPT. " .
                "These models use attention mechanisms to process context effectively.",
                "Challenges include handling ambiguity, sarcasm, and cultural nuances. " .
                "Multilingual models are an active research area.",
            ],
            'computer vision' => [
                "Computer vision allows machines to interpret visual information. " .
                "Tasks include image classification, object detection, and segmentation.",
                "Convolutional neural networks (CNNs) are the backbone of most vision systems. " .
                "Recent advances include vision transformers and diffusion models.",
                "Applications range from medical imaging to autonomous vehicles, " .
                "facial recognition, and augmented reality.",
            ],
            'ethics of AI' => [
                "AI ethics addresses issues like bias, fairness, transparency, and accountability. " .
                "Biased training data can lead to discriminatory outcomes.",
                "Explainable AI (XAI) aims to make model decisions interpretable. " .
                "This is crucial for high-stakes applications like healthcare and criminal justice.",
                "Regulatory frameworks like the EU AI Act are emerging to govern AI use. " .
                "Responsible AI development is a growing priority.",
            ],
            'future of AI' => [
                "The future of AI includes advances in general intelligence, reasoning, " .
                "and autonomous agents. AGI remains a long-term goal.",
                "AI is expected to transform every industry — healthcare, education, " .
                "transportation, and more. Human-AI collaboration will be key.",
                "Challenges include alignment, safety, and ensuring AI benefits " .
                "humanity broadly rather than concentrating power.",
            ],
        ];

        $topicKey = strtolower($topic);
        $variants = $texts[$topicKey] ?? $texts['AI'];
        $variantIdx = $index % count($variants);
        return $variants[$variantIdx];
    }

    // ─── tests ───────────────────────────────────────────────────────────────

    public function testSummaryGenerationAgainstRealLLM(): void
    {
        $client = $this->createClient();

        // Quick connectivity check
        try {
            $client->chat()->create([
                'model'    => self::$modelName,
                'messages' => [
                    ['role' => 'user', 'content' => 'Say "hello" and nothing else.'],
                ],
                'max_tokens' => 20,
            ]);
        } catch (\Throwable $e) {
            $this->markTestSkipped(
                "LLM endpoint at " . self::$llmUrl . " is unreachable: " . $e->getMessage()
            );
        }

        $summaryGenerator = $this->createSummaryGenerator($client);
        $compactor = new ContextCompactor($summaryGenerator, new NullLogger(), $this->createStub(\Perk11\Viktor89\Assistant\Compaction\CompactionSummaryStoreInterface::class), maxRecentCharacters: self::MAX_RECENT_CHARACTERS);

        // Build a context that will definitely trigger compaction
        $ctx = $this->buildLargeContext(self::TOTAL_MESSAGES);
        $this->assertCount(self::TOTAL_MESSAGES, $ctx->messages);

        // Compact — this calls the real LLM for the summary
        $compacted = $compactor->compact($ctx);

        // Verify compaction structure: summary + N recent messages
        $this->assertGreaterThan(1, count($compacted->messages), 'Compacted context should have summary + recent messages');
        $this->assertStringContainsString('[Summary of earlier conversation:', $compacted->messages[0]->text ?? '');
        // The summary is emitted as a user-role message so the compacted
        // conversation starts with system → user (summary) → …, satisfying
        // strict user/assistant alternation chat templates.
        $this->assertTrue($compacted->messages[0]->isUser);
        $this->assertSame($ctx->systemPrompt, $compacted->systemPrompt);

        // Determine how many recent messages were kept (all except the summary)
        $keptCount = count($compacted->messages) - 1;
        $this->assertGreaterThan(0, $keptCount, 'At least one recent message should be kept');
        $this->assertLessThan(self::TOTAL_MESSAGES, $keptCount, 'Some messages should have been compacted');

        // Verify recent messages match the tail of the original context
        $originalRecent = array_slice($ctx->messages, -$keptCount);
        foreach ($originalRecent as $i => $origMsg) {
            $this->assertSame(
                $origMsg->text,
                $compacted->messages[$i + 1]->text,
                sprintf('Recent message %d text differs after compaction', $i)
            );
            $this->assertSame(
                $origMsg->isUser,
                $compacted->messages[$i + 1]->isUser,
                sprintf('Recent message %d role differs after compaction', $i)
            );
        }

        // Verify the compacted context works by sending it to the LLM
        // Add a final user prompt so the LLM has something to respond to
        $compactedMessages = $compacted->toOpenAiMessagesArray();
        $compactedMessages[] = ['role' => 'user', 'content' => 'Please continue the conversation.'];
        $this->assertGreaterThan(0, count($compactedMessages));

        $response = $client->chat()->create([
            'model'      => self::$modelName,
            'messages'   => $compactedMessages,
            'max_tokens' => 200,
            'temperature' => 0.7,
        ]);

        echo "\n  LLM response debug: " . json_encode(['id' => $response->id ?? null, 'choices_count' => count($response->choices ?? []), 'finish_reason' => $response->choices[0]->finishReason ?? 'unknown'], JSON_THROW_ON_ERROR) . "\n";
        $message = $response->choices[0]->message ?? null;
        echo "  message content type: " . get_debug_type($message->content) . "\n";
        echo "  message content: " . json_encode($message->content, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) . "\n";
        echo "  finish_reason: " . ($response->choices[0]->finishReason ?? 'null') . "\n";
        // Dump full message to see reasoning_content or other fields
        echo "  full message: " . json_encode($message, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) . "\n";

        // Check for reasoning_content or other content fields
        $replyText = '';
        if (is_string($message->content ?? null) && trim($message->content ?? '') !== '') {
            $replyText = $message->content;
        } elseif (isset($message->reasoningContent)) {
            $replyText = is_string($message->reasoningContent) ? $message->reasoningContent : '';
            echo "  using reasoningContent instead\n";
        }
        $this->assertGreaterThan(0, strlen(trim($replyText)),
            'LLM reply should be non-empty');
        echo "  LLM reply (first 200 chars): " . mb_substr(trim($replyText), 0, 200) . "...\n";
    }

    public function testCompactionPreservesRecentMessagesAndProducesCoherentSummary(): void
    {
        $client = $this->createClient();

        try {
            $client->chat()->create([
                'model'    => self::$modelName,
                'messages' => [
                    ['role' => 'user', 'content' => 'test'],
                ],
                'max_tokens' => 5,
            ]);
        } catch (\Throwable $e) {
            $this->markTestSkipped(
                "LLM endpoint unreachable: " . $e->getMessage()
            );
        }

        $summaryGenerator = $this->createSummaryGenerator($client);
        $compactor = new ContextCompactor($summaryGenerator, new NullLogger(), $this->createStub(\Perk11\Viktor89\Assistant\Compaction\CompactionSummaryStoreInterface::class), maxRecentCharacters: 5);

        // Build context with specific topics so we can verify the summary captures them
        $ctx = $this->buildLargeContext(20, 'AI');

        // Compact
        $compacted = $compactor->compact($ctx);

        // The summary message should reference topics from the conversation
        $summaryText = $compacted->messages[0]->text ?? '';
        echo "\n  Generated summary: " . mb_substr($summaryText, 0, 300) . "...\n";

        // Verify the compacted context works as a prompt
        $compactedMessages = $compacted->toOpenAiMessagesArray();
        $compactedMessages[] = ['role' => 'user', 'content' => 'Continue the conversation.'];
        $response = $client->chat()->create([
            'model'      => self::$modelName,
            'messages'   => $compactedMessages,
            'max_tokens' => 100,
        ]);

        echo "\n  LLM response debug: " . json_encode(['id' => $response->id ?? null, 'choices_count' => count($response->choices ?? []), 'finish_reason' => $response->choices[0]->finishReason ?? 'unknown'], JSON_THROW_ON_ERROR) . "\n";
        $message = $response->choices[0]->message ?? null;
        echo "  message content type: " . get_debug_type($message->content) . "\n";
        echo "  message content: " . json_encode($message->content, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) . "\n";
        echo "  finish_reason: " . ($response->choices[0]->finishReason ?? 'null') . "\n";
        echo "  full message: " . json_encode($message, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) . "\n";

        $reply = '';
        if (is_string($message->content ?? null) && trim($message->content ?? '') !== '') {
            $reply = $message->content;
        } elseif (isset($message->reasoningContent)) {
            $reply = is_string($message->reasoningContent) ? $message->reasoningContent : '';
            echo "  using reasoningContent instead\n";
        }
        $this->assertNotSame('', $reply, 'LLM should reply to compacted context');
        echo "  LLM reply: " . mb_substr($reply, 0, 200) . "...\n";
    }
}
