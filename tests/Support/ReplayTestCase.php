<?php

// file generated with AI assistance: Claude Code - 2026-10-01 15:35:36 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Support;

use Dmstr\NeuronAiBundle\Conversation\HistoryReconstructor;
use Dmstr\NeuronAiBundle\Conversation\StructuredAnswerValidator;
use Dmstr\NeuronAiBundle\Conversation\TurnRecorder;
use Dmstr\NeuronAiBundle\Conversation\TurnRequest;
use Dmstr\NeuronAiBundle\Conversation\TurnResult;
use Dmstr\NeuronAiBundle\Conversation\TurnRunner;
use Dmstr\NeuronAiBundle\Embeddings\EmbeddingModelRegistry;
use Dmstr\NeuronAiBundle\Entity\Conversation;
use Dmstr\NeuronAiBundle\Entity\Message;
use Dmstr\NeuronAiBundle\Testing\CallbackTool;
use Dmstr\NeuronAiBundle\Testing\FakeProviderFactory;
use Dmstr\NeuronAiBundle\Testing\ProviderConnectionFixtures;
use NeuronAI\Chat\Messages\Message as NeuronMessage;
use NeuronAI\Providers\MessageMapperInterface;
use NeuronAI\Tools\ToolInterface;
use PHPUnit\Framework\TestCase;

/**
 * Shared scenario of the mapper-level replay tests (kernel-free): three
 * turns run on the real TurnRunner with the bundle's fake provider, their
 * records are built by TurnRecorder, numbered like the turn service does
 * and sent through a JSON round trip (what the JSON columns return), and
 * the HistoryReconstructor rebuilds the history from them.
 *
 * 1. reasoning plus two parallel tool calls, then a final answer with
 *    reasoning and usage (complete);
 * 2. the provider fails (error record; must be skipped);
 * 3. a plain answer, run with the reconstructed history of turn 1.
 *
 * The test compares the provider mapping of the reconstructed history with
 * the mapping of the live messages of the complete turns.
 *
 * The terminal scenario ({@see runTerminalScenario()}): turn 1 ends with a
 * batch containing the terminal tool `submit` (no further model call, the
 * turn ends in the tool results), turn 2 is a plain answer run with the
 * replayed turn 1.
 */
abstract class ReplayTestCase extends TestCase
{
    protected FakeProviderFactory $factory;

    protected HistoryReconstructor $reconstructor;

    protected Conversation $conversation;

    /** @var list<Message> */
    protected array $records = [];

    private int $sequence = 1;

    protected function setUp(): void
    {
        $this->factory = new FakeProviderFactory(new EmbeddingModelRegistry([
            'nomic-embed-text' => ['provider' => 'ollama', 'dimension' => 768],
        ]));
        $this->reconstructor = new HistoryReconstructor();
        $this->conversation = new Conversation();
    }

    abstract protected function provider(): string;

    abstract protected function model(): string;

    abstract protected function mapper(): MessageMapperInterface;

    /**
     * Scripts the first provider response of turn 1 (a tool-call response).
     */
    protected function queueFirstStep(): void
    {
        $this->factory
            ->queueToolCall('search', ['query' => 'Wartung'], 'toolu_search', reasoning: 'Ich suche zuerst.', signature: 'sig-step-1')
            ->queueToolCall('fetch', ['id' => 'doc-7'], 'toolu_fetch');
    }

    /**
     * Scripts the response of turn 1 of the terminal scenario: a batch of
     * `search` and the terminal `submit`.
     */
    protected function queueTerminalStep(): void
    {
        $this->factory
            ->queueToolCall('search', ['query' => 'Wartung'], 'toolu_search')
            ->queueToolCall('submit', [], 'toolu_submit');
    }

    /**
     * Runs the terminal scenario.
     *
     * @return list<NeuronMessage> the live messages of both turns
     */
    protected function runTerminalScenario(): array
    {
        $this->queueTerminalStep();
        $first = $this->turn('Wann muss die Spindel gewartet werden?', ['submit']);
        self::assertNull($first->failure);
        self::assertSame('submit', $first->terminalCall?->name);

        $this->factory->queueAnswer('Gern geschehen.');
        $second = $this->turn('Danke!');
        self::assertNull($second->failure);

        return [...$first->transcript, ...$second->transcript];
    }

    /**
     * Runs the scenario.
     *
     * @return list<NeuronMessage> the live messages of the complete turns (1 and 3)
     */
    protected function runScenario(): array
    {
        $this->queueFirstStep();
        $this->factory->queueReasoning('Die Treffer sagen 500 Stunden.', 'Alle 500 Stunden.', 'sig-final');
        $first = $this->turn('Wann muss die Spindel gewartet werden?');
        self::assertNull($first->failure);

        $this->factory->queueFailure('Overloaded');
        $second = $this->turn('Und das Getriebe?');
        self::assertNotNull($second->failure);

        $this->factory->queueAnswer('Bitte im Handbuch nachsehen.');
        $third = $this->turn('Noch einmal: und das Getriebe?');
        self::assertNull($third->failure);

        return [...$first->transcript, ...$third->transcript];
    }

    /**
     * One turn as the turn service runs it: history from the records so far,
     * the run, then the user and assistant records.
     *
     * @param list<string> $terminalTools
     */
    protected function turn(string $content, array $terminalTools = []): TurnResult
    {
        $result = new TurnRunner($this->factory, new StructuredAnswerValidator())->run(new TurnRequest(
            provider: $this->provider(),
            connection: 'ollama' === $this->provider() ? ProviderConnectionFixtures::ollama() : ProviderConnectionFixtures::anthropic(),
            model: $this->model(),
            content: $content,
            history: $this->reconstructor->reconstruct($this->records),
            tools: $this->tools(),
            terminalTools: $terminalTools,
        ));

        $this->store([TurnRecorder::userRecord($this->conversation, $content)]);
        $this->store(TurnRecorder::assistantRecords($this->conversation, $result));

        return $result;
    }

    /**
     * @param list<NeuronMessage> $messages
     */
    protected function map(array $messages): string
    {
        return json_encode($this->mapper()->map($messages), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return list<ToolInterface>
     */
    private function tools(): array
    {
        return [
            new CallbackTool('search', 'Full-text search', static fn (): string => 'Spindel: alle 500 Stunden.'),
            new CallbackTool('fetch', 'Fetches a document', static fn (): string => 'Dokument 7'),
            // An MCP-shaped result (a content list, JSON-encoded by neuron-ai).
            new CallbackTool('submit', 'Submits the answer', static fn (): array => [['type' => 'text', 'text' => 'Alle 500 Stunden.']]),
        ];
    }

    /**
     * Numbers the records and gives their JSON fields back as the JSON
     * columns would.
     *
     * @param list<Message> $records
     */
    private function store(array $records): void
    {
        foreach ($records as $record) {
            $record
                ->setSequence($this->sequence++)
                ->setProviderPayload(self::roundTrip($record->getProviderPayload()))
                ->setToolInput(self::roundTrip($record->getToolInput()))
                ->setUsage(self::roundTrip($record->getUsage()));
            $this->records[] = $record;
        }
    }

    /**
     * @param array<mixed>|null $value
     *
     * @return array<mixed>|null
     */
    private static function roundTrip(?array $value): ?array
    {
        return null === $value ? null : json_decode(json_encode($value, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
    }
}

