<?php

// file generated with AI assistance: Claude Code - 2026-10-03 18:37:15 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\Provider;

use Dmstr\NeuronAiBundle\Embeddings\EmbeddingModelRegistry;
use Dmstr\NeuronAiBundle\Testing\CallbackTool;
use Dmstr\NeuronAiBundle\Testing\FakeProviderFactory;
use Dmstr\NeuronAiBundle\Testing\FixtureSet;
use Dmstr\NeuronAiBundle\Testing\ProviderConnectionFixtures;
use Dmstr\NeuronAiBundle\Testing\RecordingFakeAIProvider;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\ReasoningContent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Stream\Chunks\ReasoningChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\ProviderException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Kernel-free checks of the fixture mode of the fake AI provider: answers
 * by a key phrase in the last user message, falling back to the script and
 * the default behaviour, and the JSON loader.
 */
final class FixtureModeTest extends TestCase
{
    private const string ANSWERS = __DIR__.'/../../Fixtures/evaluation/answers.json';

    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir().'/fixture-mode-'.bin2hex(random_bytes(4));
        mkdir($this->tmpDir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->tmpDir);
    }

    public function testFixtureMatchesBySubstringOfTheLastUserMessage(): void
    {
        $provider = new RecordingFakeAIProvider()->useFixtures(['maintenance interval' => 'Alle 500 Stunden.']);

        $response = $provider->chat(
            new UserMessage('Something else entirely'),
            new AssistantMessage('Earlier answer'),
            new UserMessage('Please tell me: What is the maintenance interval?'),
        )->message();

        self::assertSame('Alle 500 Stunden.', $response->getContent());
        self::assertSame(['maintenance interval'], $provider->getFixtureHits());
        $provider->assertCallCount(1);
        self::assertSame('Please tell me: What is the maintenance interval?', $provider->getRecorded()[0]->messages[2]->getContent());
    }

    public function testMatchingIsCaseSensitiveAndOnlyLooksAtTheLastUserMessage(): void
    {
        $provider = new RecordingFakeAIProvider()
            ->useFixtures(['maintenance interval' => 'fixture'])
            ->queueAnswer('queued one')
            ->queueAnswer('queued two');

        self::assertSame('queued one', $provider->chat(new UserMessage('MAINTENANCE INTERVAL?'))->message()->getContent());
        self::assertSame('queued two', $provider->chat(
            new UserMessage('maintenance interval'),
            new AssistantMessage('answer'),
            new UserMessage('next question'),
        )->message()->getContent());
        self::assertSame([], $provider->getFixtureHits());
    }

    public function testLongestMatchingKeyWins(): void
    {
        $provider = new RecordingFakeAIProvider()->useFixtures([
            'interval' => 'short',
            'maintenance interval of the pump' => 'longest',
            'maintenance interval' => 'medium',
        ]);

        self::assertSame('longest', $provider->chat(new UserMessage('What is the maintenance interval of the pump?'))->message()->getContent());
        self::assertSame('medium', $provider->chat(new UserMessage('What is the maintenance interval?'))->message()->getContent());
        self::assertSame('short', $provider->chat(new UserMessage('Which interval?'))->message()->getContent());
        self::assertSame(['maintenance interval of the pump', 'maintenance interval', 'interval'], $provider->getFixtureHits());
    }

    public function testUnmatchedCallFallsBackToTheScriptQueue(): void
    {
        $provider = new RecordingFakeAIProvider()
            ->queueAnswer('from the queue')
            ->useFixtures(['torque' => '450 Nm']);

        self::assertSame('450 Nm', $provider->chat(new UserMessage('Which torque?'))->message()->getContent());
        self::assertTrue($provider->hasQueuedResponses(), 'A fixture hit must not consume the queue.');
        self::assertSame('from the queue', $provider->chat(new UserMessage('Something else'))->message()->getContent());
        self::assertFalse($provider->hasQueuedResponses());
        self::assertSame(['torque'], $provider->getFixtureHits());
        $provider->assertCallCount(2);
    }

    public function testFixtureHitBypassesAQueuedStreamFailureInChat(): void
    {
        $provider = new RecordingFakeAIProvider()
            ->queueStreamFailure('broken', 1)
            ->useFixtures(['torque' => '450 Nm']);

        self::assertSame('450 Nm', $provider->chat(new UserMessage('Which torque?'))->message()->getContent());
        self::assertTrue($provider->hasQueuedResponses());
    }

    public function testUnmatchedCallWithEmptyQueueKeepsTheDefaultBehaviour(): void
    {
        $provider = new RecordingFakeAIProvider()->useFixtures(['torque' => '450 Nm']);

        try {
            $provider->chat(new UserMessage('Something else'))->message();
            self::fail('Expected a ProviderException.');
        } catch (ProviderException $e) {
            self::assertStringContainsString('response queue is empty', $e->getMessage());
        }

        $provider->setEcho(true);
        self::assertSame('Something else', $provider->chat(new UserMessage('Something else'))->message()->getContent());
        self::assertSame('450 Nm', $provider->chat(new UserMessage('Which torque?'))->message()->getContent());
    }

    public function testClearFixturesAndUseFixturesReplaceTheSetButKeepTheQueue(): void
    {
        $provider = new RecordingFakeAIProvider()
            ->useFixtures(['torque' => 'old'])
            ->queueAnswer('queued');

        $provider->useFixtures(['pump' => 'new']);
        self::assertSame('queued', $provider->chat(new UserMessage('Which torque?'))->message()->getContent());
        self::assertSame('new', $provider->chat(new UserMessage('Which pump?'))->message()->getContent());

        $provider->queueAnswer('queued again')->clearFixtures();
        self::assertSame('queued again', $provider->chat(new UserMessage('Which pump?'))->message()->getContent());
        self::assertSame(['pump'], $provider->getFixtureHits());
    }

    public function testFailureFixtureThrowsAndIsNotRecorded(): void
    {
        $provider = new RecordingFakeAIProvider()->useFixtures(['warranty' => ['failure' => 'Upstream overloaded']]);

        try {
            $provider->chat(new UserMessage('Summarise the warranty'))->message();
            self::fail('Expected a ProviderException.');
        } catch (ProviderException $e) {
            self::assertSame('Upstream overloaded', $e->getMessage());
        }

        try {
            $provider->stream(new UserMessage('Summarise the warranty'));
            self::fail('Expected a ProviderException at stream() call time.');
        } catch (ProviderException $e) {
            self::assertSame('Upstream overloaded', $e->getMessage());
        }

        $provider->assertNothingSent();
        self::assertSame(['warranty', 'warranty'], $provider->getFixtureHits());
    }

    public function testToolCallsFirstThenTheAnswer(): void
    {
        $provider = new RecordingFakeAIProvider()->useFixtures([
            'maintenance' => [
                'toolCalls' => [
                    ['name' => 'search', 'inputs' => ['query' => 'Wartung'], 'callId' => 'call_fixed'],
                    ['name' => 'fetch', 'inputs' => ['id' => 7]],
                ],
                'answer' => 'Alle 500 Stunden.',
            ],
        ]);
        $provider->setTools([new CallbackTool('search', 'Search', static fn (): string => ''), new CallbackTool('fetch', 'Fetch', static fn (): string => '')]);

        $question = new UserMessage('What is the maintenance interval?');
        $first = $provider->chat($question)->message();

        self::assertInstanceOf(ToolCallMessage::class, $first);
        $tools = $first->getToolCalls();
        self::assertSame(['search', 'fetch'], array_map(static fn ($tool): string => $tool->getName(), $tools));
        self::assertSame(['query' => 'Wartung'], $tools[0]->getInputs());
        self::assertSame('call_fixed', $tools[0]->getCallId());
        self::assertSame(['id' => 7], $tools[1]->getInputs());
        self::assertNotEmpty($tools[1]->getCallId());

        $results = new ToolResultMessage([$tools[0]->setResult('Kapitel 4'), $tools[1]->setResult('Seite 7')]);
        $second = $provider->chat($question, $first, $results)->message();

        self::assertNotInstanceOf(ToolCallMessage::class, $second);
        self::assertSame('Alle 500 Stunden.', $second->getContent());

        // The next turn with the same question starts with the tool calls again.
        self::assertInstanceOf(ToolCallMessage::class, $provider->chat($question)->message());
        self::assertSame(['maintenance', 'maintenance', 'maintenance'], $provider->getFixtureHits());
        $provider->assertCallCount(3);
    }

    public function testToolCallFixtureForAnUnknownToolThrowsAndStaysAtTheToolCalls(): void
    {
        $provider = new RecordingFakeAIProvider()->useFixtures([
            'maintenance' => ['toolCalls' => [['name' => 'search']], 'answer' => 'done'],
        ]);

        try {
            $provider->chat(new UserMessage('maintenance?'))->message();
            self::fail('Expected a ProviderException.');
        } catch (ProviderException $e) {
            self::assertStringContainsString('non-existing tool: search', $e->getMessage());
        }

        $provider->setTools([new CallbackTool('search', 'Search', static fn (): string => '')]);
        self::assertInstanceOf(ToolCallMessage::class, $provider->chat(new UserMessage('maintenance?'))->message());
    }

    public function testStreamingAFixtureAnswerUsesChunkSizeAndHook(): void
    {
        $seen = [];
        $provider = new RecordingFakeAIProvider()
            ->useFixtures(['safety' => ['answer' => 'Einmal jährlich.', 'reasoning' => 'Chapter 1']])
            ->setChunkSize(4)
            ->setChunkHook(static function (int $index, $chunk) use (&$seen): void {
                $seen[] = $index;
            });

        $generator = $provider->stream(new UserMessage('Who is responsible for safety training?'));
        $chunks = iterator_to_array($generator, false);
        $final = $generator->getReturn()->message();

        $reasoning = array_values(array_filter($chunks, static fn ($chunk): bool => $chunk instanceof ReasoningChunk));
        $text = array_values(array_filter($chunks, static fn ($chunk): bool => $chunk instanceof TextChunk && !$chunk instanceof ReasoningChunk));
        self::assertSame(['Chap', 'ter ', '1'], array_map(static fn ($chunk): string => $chunk->content, $reasoning));
        self::assertSame(['Einm', 'al j', 'ährl', 'ich.'], array_map(static fn ($chunk): string => $chunk->content, $text));
        self::assertSame(range(0, 6), $seen);
        self::assertInstanceOf(Message::class, $final);
        self::assertSame('Einmal jährlich.', $final->getContent());
        self::assertInstanceOf(ReasoningContent::class, $final->getContentBlocks()[0]);
        $provider->assertMethodCallCount('stream', 1);
        self::assertSame(['safety'], $provider->getFixtureHits());
    }

    public function testStructuredIgnoresFixtures(): void
    {
        $provider = new RecordingFakeAIProvider()
            ->useFixtures(['torque' => 'fixture'])
            ->queueAnswer('{"value": "queued"}');

        $provider->chat(new UserMessage('Which torque?'))->message();
        $response = $provider->structured(new UserMessage('Which torque?'), \stdClass::class, [])->message();

        self::assertSame('{"value": "queued"}', $response->getContent());
        self::assertSame(['torque'], $provider->getFixtureHits());
    }

    public function testFactoryPassesFixturesToTheSharedFake(): void
    {
        $factory = new FakeProviderFactory(new EmbeddingModelRegistry([
            'nomic-embed-text' => ['provider' => 'ollama', 'dimension' => 768],
        ]));
        $factory->useFixtures(['torque' => '450 Nm'])->queueAnswer('queued');

        $provider = $factory->chatProvider(ProviderConnectionFixtures::anthropic(), FakeProviderFactory::ANTHROPIC_MODEL);
        self::assertSame('450 Nm', $provider->chat(new UserMessage('Which torque?'))->message()->getContent());

        $factory->clearFixtures();
        self::assertSame('queued', $provider->chat(new UserMessage('Which torque?'))->message()->getContent());
        self::assertSame(['torque'], $factory->getChatProvider()->getFixtureHits());
    }

    /**
     * @return iterable<string, array{array<array-key, mixed>}>
     */
    public static function invalidFixtures(): iterable
    {
        yield 'empty key' => [['' => 'x']];
        yield 'not a string or array' => [['k' => 42]];
        yield 'neither answer nor failure' => [['k' => ['reasoning' => 'r']]];
        yield 'answer and failure' => [['k' => ['answer' => 'a', 'failure' => 'f']]];
        yield 'unknown field' => [['k' => ['answer' => 'a', 'text' => 't']]];
        yield 'empty tool calls' => [['k' => ['toolCalls' => [], 'answer' => 'a']]];
        yield 'tool call without name' => [['k' => ['toolCalls' => [['inputs' => []]], 'answer' => 'a']]];
    }

    /**
     * @param array<array-key, mixed> $fixtures
     */
    #[DataProvider('invalidFixtures')]
    public function testMalformedFixturesAreRejected(array $fixtures): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new RecordingFakeAIProvider()->useFixtures($fixtures);
    }

    /**
     * @return iterable<string, array{?string, string}>
     */
    public static function invalidFiles(): iterable
    {
        yield 'missing file' => [null, 'does not exist'];
        yield 'invalid JSON' => ['{"a": ', 'is not valid JSON'];
        yield 'list' => ['["a", "b"]', 'must contain a JSON object'];
        yield 'empty list' => ['[]', 'must contain a JSON object'];
        yield 'scalar' => ['"answer"', 'must contain a JSON object'];
        yield 'numeric fixture' => ['{"a": 1}', 'must be a string or an object'];
    }

    #[DataProvider('invalidFiles')]
    public function testLoadRejectsInvalidFiles(?string $content, string $message): void
    {
        $path = $this->tmpDir.'/fixtures.json';
        if (null !== $content) {
            file_put_contents($path, $content);
        }

        try {
            FixtureSet::load($path);
            self::fail('Expected an InvalidArgumentException.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString($path, $e->getMessage());
            self::assertStringContainsString($message, $e->getMessage());
        }
    }

    public function testLoadAcceptsAnEmptyObject(): void
    {
        file_put_contents($this->tmpDir.'/empty.json', '{}');

        self::assertSame([], FixtureSet::load($this->tmpDir.'/empty.json'));
    }

    public function testLoadsTheEvaluationAnswers(): void
    {
        $fixtures = FixtureSet::load(self::ANSWERS);
        self::assertGreaterThanOrEqual(4, \count($fixtures));

        $provider = new RecordingFakeAIProvider()->useFixtures($fixtures);
        $provider->setTools([new CallbackTool('search_documents', 'Search documents', static fn (): string => '')]);

        self::assertStringContainsString('500 Betriebsstunden', (string) $provider->chat(new UserMessage('What is the maintenance interval?'))->message()->getContent());

        $question = new UserMessage('What is the maintenance interval of the hydraulic pump?');
        $call = $provider->chat($question)->message();
        self::assertInstanceOf(ToolCallMessage::class, $call);
        self::assertSame('search_documents', $call->getToolCalls()[0]->getName());
        self::assertSame('call_maintenance', $call->getToolCalls()[0]->getCallId());
        $results = new ToolResultMessage([$call->getToolCalls()[0]->setResult('Kapitel 4.2')]);
        self::assertStringContainsString('Kapitel 4.2', (string) $provider->chat($question, $call, $results)->message()->getContent());

        self::assertStringContainsString('450 Nm', (string) $provider->chat(new UserMessage('Which torque applies to the wheel nuts?'))->message()->getContent());

        $this->expectException(ProviderException::class);
        $provider->chat(new UserMessage('Summarise the warranty conditions, please.'))->message();
    }
}

// - revised 2026-10-14
