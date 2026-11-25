<?php

// file generated with AI assistance: Claude Code - 2026-10-01 15:35:36 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\Conversation\Replay;

use Dmstr\NeuronAiBundle\Testing\FakeProviderFactory;
use Dmstr\NeuronAiBundle\Tests\Support\ReplayTestCase;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Providers\MessageMapperInterface;
use NeuronAI\Providers\Ollama\MessageMapper;
use NeuronAI\Tools\ToolCall;

/**
 * Replay fidelity for Ollama: the reconstructed history maps to exactly
 * the request body the live messages map to.
 *
 * neuron-ai 4.x maps an assistant tool-call message to Ollama with its
 * `tool_calls` (the 3.17.0 mapper dropped them), live and replayed alike;
 * the tool results follow as `tool` messages, and reasoning blocks are not
 * sent. Ollama tool calls stored before 4.x carry no call id (4.x
 * generates one), so the reconstruction matches tool records by position.
 */
final class OllamaReplayTest extends ReplayTestCase
{
    protected function provider(): string
    {
        return 'ollama';
    }

    protected function model(): string
    {
        return FakeProviderFactory::OLLAMA_MODEL;
    }

    protected function mapper(): MessageMapperInterface
    {
        return new MessageMapper();
    }

    public function testReconstructedHistoryMapsLikeTheLiveMessages(): void
    {
        $live = $this->runScenario();

        $reconstructed = $this->reconstructor->reconstruct($this->records);

        self::assertSame($this->map($live), $this->map($reconstructed));

        $mapped = json_decode($this->map($reconstructed), true);
        // user, tool-call response, two tool results, final answer, user, answer
        self::assertSame(['user', 'assistant', 'tool', 'tool', 'assistant', 'user', 'assistant'], array_column($mapped, 'role'));
        self::assertSame(['Spindel: alle 500 Stunden.', 'Dokument 7'], [$mapped[2]['content'], $mapped[3]['content']]);
        // The mapper sends the text blocks only (neuron-ai 4.x; 3.x sent the reasoning too).
        self::assertSame('Alle 500 Stunden.', $mapped[4]['content']);
    }

    public function testToolCallsAreSentLiveAndReplayedAlike(): void
    {
        $live = $this->runScenario();

        foreach ([$live, $this->reconstructor->reconstruct($this->records)] as $messages) {
            $mapped = json_decode($this->map($messages), true);
            self::assertSame(['search', 'fetch'], array_map(static fn (array $call): string => $call['function']['name'], $mapped[1]['tool_calls']));
        }
    }

    public function testTerminalTurnIsReplayedWithToolMessagesAfterTheAssistantMessage(): void
    {
        $live = $this->runTerminalScenario();

        $reconstructed = $this->reconstructor->reconstruct($this->records);

        self::assertCount(5, $reconstructed);
        self::assertSame($this->map($live), $this->map($reconstructed));

        $mapped = json_decode($this->map($reconstructed), true);
        self::assertSame(['user', 'assistant', 'tool', 'tool', 'user', 'assistant'], array_column($mapped, 'role'));
        self::assertSame(['Spindel: alle 500 Stunden.', '[{"type":"text","text":"Alle 500 Stunden."}]'], [$mapped[2]['content'], $mapped[3]['content']]);
        self::assertSame('Danke!', $mapped[4]['content']);
    }

    public function testToolCallsWithoutIdsAreMatchedByPosition(): void
    {
        // Tool calls the way the Ollama provider builds them: no call ids.
        $this->factory->getChatProvider()->addResponses(new ToolCallMessage(null, [
            ToolCall::make(name: 'search', inputs: ['query' => 'Wartung'], description: 'Full-text search'),
            ToolCall::make(name: 'fetch', inputs: ['id' => 'doc-7'], description: 'Fetches a document'),
        ]));
        $this->factory->queueAnswer('Alle 500 Stunden.');
        $live = $this->turn('Wann muss die Spindel gewartet werden?')->transcript;

        $reconstructed = $this->reconstructor->reconstruct($this->records);

        self::assertCount(4, $reconstructed);
        self::assertNull($this->records[2]->getToolCallId());
        self::assertSame($this->map($live), $this->map($reconstructed));
    }
}

// - revised 2026-10-14
