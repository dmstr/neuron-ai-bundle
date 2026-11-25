<?php

// file generated with AI assistance: Claude Code - 2026-10-16 09:04:49 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\Conversation;

use Dmstr\NeuronAiBundle\Tests\Support\ArtifactTestFiles;
use Dmstr\NeuronAiBundle\Tests\Support\ArtifactTestLimits;
use Dmstr\NeuronAiBundle\Agent\VersionTurnMapper;
use Dmstr\NeuronAiBundle\Artifact\ArtifactAccess;
use Dmstr\NeuronAiBundle\Artifact\ArtifactScope;
use Dmstr\NeuronAiBundle\Artifact\ArtifactToolkit;
use Dmstr\NeuronAiBundle\Artifact\ArtifactToolkitFactory;
use Dmstr\NeuronAiBundle\Conversation\MessageKind;
use Dmstr\NeuronAiBundle\Conversation\StructuredAnswerValidator;
use Dmstr\NeuronAiBundle\Conversation\TurnRecorder;
use Dmstr\NeuronAiBundle\Conversation\TurnRequest;
use Dmstr\NeuronAiBundle\Conversation\TurnResult;
use Dmstr\NeuronAiBundle\Conversation\TurnRunner;
use Dmstr\NeuronAiBundle\Embeddings\EmbeddingModelRegistry;
use Dmstr\NeuronAiBundle\Entity\Conversation;
use Dmstr\NeuronAiBundle\Entity\Message;
use Dmstr\NeuronAiBundle\Stream\StreamEvent;
use Dmstr\NeuronAiBundle\Testing\FakeProviderFactory;
use Dmstr\NeuronAiBundle\Testing\InMemoryArtifactLedger;
use Dmstr\NeuronAiBundle\Testing\ProviderConnectionFixtures;
use Dmstr\NeuronAiBundle\Testing\RecordingStreamSink;
use Dmstr\NeuronAiBundle\Testing\ToolChoiceRequestRecord;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

/**
 * Kernel-free: a turn with the real artifact toolkit on a temporary
 * directory, run by {@see TurnRunner} against the bundle's fake provider,
 * plain and streamed. The request is built the way the turn services build
 * it from a mapped configuration: the toolkit's wrapped tools and the
 * instructions with the guidelines appended
 * ({@see VersionTurnMapper::withArtifactGuidelines()}).
 *
 * Checks: the offered tool names, a refused `write_file` on `../x` is
 * recorded with `toolError` and streamed as `tool_result` with `error`,
 * a write into the working folder lands on disk, and the storage root
 * appears in no tool result, no stream event and not in the system prompt.
 */
final class TurnRunnerArtifactTest extends TestCase
{
    private const string OWNER = 'user-1';

    private string $base;

    private Conversation $conversation;

    private ArtifactAccess $access;

    private FakeProviderFactory $factory;

    protected function setUp(): void
    {
        $this->base = ArtifactTestFiles::makeTempDir();
        mkdir($this->base.'/root');
        $this->conversation = new Conversation()->setOwnerSubject(self::OWNER);
        $conversationId = $this->conversation->getId()->toRfc4122();
        $scopeRoot = new ArtifactScope($this->base.'/root')->ensure(self::OWNER, $conversationId);
        $this->access = new ArtifactAccess(self::OWNER, $conversationId, $scopeRoot, true);
        $this->factory = new FakeProviderFactory(new EmbeddingModelRegistry([
            'nomic-embed-text' => ['provider' => 'ollama', 'dimension' => 768],
        ]));
    }

    protected function tearDown(): void
    {
        ArtifactTestFiles::remove($this->base);
    }

    public function testTheTurnOffersTheFiveToolsAndTheGuidelines(): void
    {
        $this->factory->queueAnswer('Fertig.');

        $result = $this->runner()->run($this->request());

        self::assertFalse($result->isFailed());
        [$request] = $this->requests();
        $names = array_values(array_map(static fn ($tool): string => $tool->getName(), $request->tools));
        self::assertSame(ArtifactToolkit::TOOL_NAMES, $names);
        foreach (['bash', 'delete_file', 'parse_file'] as $excluded) {
            self::assertNotContains($excluded, $names);
        }

        $prompt = (string) $request->systemPrompt?->getContent();
        self::assertStringStartsWith('Du bist ein Assistent.', $prompt);
        self::assertStringContainsString('<TOOLS-GUIDELINES>', $prompt);
        self::assertSame(1, substr_count($prompt, '<TOOLS-GUIDELINES>'), 'The guidelines are not added a second time by neuron-ai.');
        self::assertStringContainsString($this->access->conversationId.'/', $prompt);
        self::assertStringNotContainsString($this->base, $prompt);
    }

    public function testRefusedWriteIsAToolErrorInRecordAndStream(): void
    {
        $this->factory->queueToolCall('write_file', ['file_path' => '../x', 'content' => 'x'], 'call_out')->queueAnswer('Ging nicht.');

        $plain = $this->runner()->run($this->request());

        $record = $this->toolRecord($plain);
        self::assertSame('write_file', $record->getToolName());
        self::assertTrue($record->isToolError());
        self::assertNotSame('', (string) $record->getToolResult());
        self::assertStringNotContainsString($this->base, (string) $record->getToolResult());
        self::assertFileDoesNotExist($this->base.'/root/x');
        self::assertFileDoesNotExist($this->base.'/x');

        $this->factory->queueToolCall('write_file', ['file_path' => '../x', 'content' => 'x'], 'call_out_stream')->queueAnswer('Ging nicht.');
        $sink = new RecordingStreamSink();

        $streamed = $this->runner()->stream($this->request(), $sink, messageId: 'msg-final');

        self::assertTrue($this->toolRecord($streamed)->isToolError());
        $results = array_values(array_filter($sink->getEvents(), static fn (StreamEvent $event): bool => StreamEvent::TOOL_RESULT === $event->type));
        self::assertCount(1, $results);
        self::assertSame('write_file', $results[0]->payload['name']);
        self::assertTrue($results[0]->payload['error']);
        $this->assertNoServerPath($sink, $streamed);
        self::assertFileDoesNotExist($this->base.'/root/x');
    }

    public function testWriteIntoTheWorkingFolderSucceeds(): void
    {
        $path = $this->access->conversationId.'/notes.md';
        $this->factory->queueToolCall('write_file', ['file_path' => $path, 'content' => '# Notizen'], 'call_write')->queueAnswer('Gespeichert.');
        $sink = new RecordingStreamSink();

        $result = $this->runner()->stream($this->request(), $sink, messageId: 'msg-final');

        self::assertFalse($result->isFailed());
        $record = $this->toolRecord($result);
        self::assertSame('write_file', $record->getToolName());
        self::assertFalse($record->isToolError());
        self::assertSame('# Notizen', file_get_contents($this->access->scopeRoot.'/'.$path));
        $results = array_values(array_filter($sink->getEvents(), static fn (StreamEvent $event): bool => StreamEvent::TOOL_RESULT === $event->type));
        self::assertFalse($results[0]->payload['error']);
        $this->assertNoServerPath($sink, $result);
    }

    /**
     * The storage root (and with it the scope root) appears in no tool
     * result, no stream event and not in the instructions of the result.
     */
    private function assertNoServerPath(RecordingStreamSink $sink, TurnResult $result): void
    {
        foreach (TurnRecorder::assistantRecords($this->conversation, $result) as $record) {
            self::assertStringNotContainsString($this->base, (string) $record->getToolResult());
            self::assertStringNotContainsString($this->base, (string) $record->getContent());
        }
        foreach ($sink->getEvents() as $event) {
            self::assertStringNotContainsString($this->base, (string) json_encode($event->payload, \JSON_UNESCAPED_SLASHES), $event->type);
        }
        foreach ($this->requests() as $request) {
            self::assertStringNotContainsString($this->base, (string) $request->systemPrompt?->getContent());
        }
        self::assertStringNotContainsString($this->base, $result->instructions);
    }

    private function toolRecord(TurnResult $result): Message
    {
        $records = array_values(array_filter(
            TurnRecorder::assistantRecords($this->conversation, $result),
            static fn (Message $record): bool => MessageKind::TOOL_CALL === $record->getKind(),
        ));
        self::assertCount(1, $records);

        return $records[0];
    }

    private function runner(): TurnRunner
    {
        return new TurnRunner($this->factory, new StructuredAnswerValidator());
    }

    private function request(): TurnRequest
    {
        $toolkit = new ArtifactToolkitFactory(new ArtifactTestLimits(), new InMemoryArtifactLedger(), new LockFactory(new InMemoryStore()))->forAccess($this->access);

        return new TurnRequest(
            provider: 'anthropic',
            model: FakeProviderFactory::ANTHROPIC_MODEL,
            content: 'Schreib Notizen.',
            tools: $toolkit->tools(),
            instructions: VersionTurnMapper::withArtifactGuidelines('Du bist ein Assistent.', $toolkit),
            connection: ProviderConnectionFixtures::anthropic(),
        );
    }

    /**
     * @return list<ToolChoiceRequestRecord>
     */
    private function requests(): array
    {
        $records = $this->factory->getChatProvider()->getRecorded();
        self::assertContainsOnlyInstancesOf(ToolChoiceRequestRecord::class, $records);

        return array_values($records);
    }
}
