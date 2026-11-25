<?php

// file generated with AI assistance: Claude Code - 2026-10-01 15:35:36 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\Conversation\Replay;

use Dmstr\NeuronAiBundle\Testing\FakeProviderFactory;
use Dmstr\NeuronAiBundle\Tests\Support\ReplayTestCase;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\ReasoningContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\Anthropic\MessageMapper;
use NeuronAI\Providers\MessageMapperInterface;
use NeuronAI\Tools\ToolCall;

/**
 * Replay fidelity for Anthropic: the reconstructed history maps to exactly
 * the request body the live messages map to, block for block — thinking
 * blocks with their signatures, redacted thinking and tool positions from
 * the provider metadata, tool_use ids and tool_result tool_use_ids; a
 * turn a terminal tool ended replays as its tool call and results.
 */
final class AnthropicReplayTest extends ReplayTestCase
{
    protected function provider(): string
    {
        return 'anthropic';
    }

    protected function model(): string
    {
        return FakeProviderFactory::ANTHROPIC_MODEL;
    }

    protected function mapper(): MessageMapperInterface
    {
        return new MessageMapper();
    }

    /**
     * A tool-call response the way the Anthropic provider builds it: text
     * and thinking blocks, the redacted-thinking and tool-position metadata,
     * two parallel tool_use blocks and usage.
     */
    protected function queueFirstStep(): void
    {
        $response = new ToolCallMessage(
            [new ReasoningContent('Ich suche zuerst.', 'sig-step-1'), new TextContent('Ich sehe nach.')],
            [
                ToolCall::make(name: 'search', callId: 'toolu_01', inputs: ['query' => 'Wartung'], description: 'Full-text search'),
                ToolCall::make(name: 'fetch', callId: 'toolu_02', inputs: [], description: 'Fetches a document'),
            ],
        );
        $response->addMetadata('anthropic_redacted_thinking', [1 => 'opaque-redacted-data']);
        $response->addMetadata('anthropic_tool_positions', [3, 4]);
        $response->setUsage(new Usage(120, 40, 7, 0));
        $this->factory->getChatProvider()->addResponses($response);
    }

    public function testReconstructedHistoryMapsLikeTheLiveMessages(): void
    {
        $live = $this->runScenario();

        $reconstructed = $this->reconstructor->reconstruct($this->records);

        self::assertCount(\count($live), $reconstructed);
        self::assertSame($this->map($live), $this->map($reconstructed));

        $mapped = json_decode($this->map($reconstructed), true);
        // user, tool-call response, tool results, final answer, user, answer
        self::assertSame(['user', 'assistant', 'user', 'assistant', 'user', 'assistant'], array_column($mapped, 'role'));

        $toolCall = $mapped[1]['content'];
        self::assertSame(['thinking', 'redacted_thinking', 'text', 'tool_use', 'tool_use'], array_column($toolCall, 'type'));
        self::assertSame('sig-step-1', $toolCall[0]['signature']);
        self::assertSame('opaque-redacted-data', $toolCall[1]['data']);
        self::assertSame(['toolu_01', 'toolu_02'], [$toolCall[3]['id'], $toolCall[4]['id']]);
        self::assertSame(['query' => 'Wartung'], $toolCall[3]['input']);
        self::assertSame([], $toolCall[4]['input']);

        $results = $mapped[2]['content'];
        self::assertSame(['toolu_01', 'toolu_02'], array_column($results, 'tool_use_id'));
        self::assertSame(['Spindel: alle 500 Stunden.', 'Dokument 7'], array_column($results, 'content'));

        self::assertSame(['thinking', 'text'], array_column($mapped[3]['content'], 'type'));
        self::assertSame('sig-final', $mapped[3]['content'][0]['signature']);
    }

    public function testFailedTurnIsSkippedAndTheNextTurnSawTheReplayedHistory(): void
    {
        $live = $this->runScenario();

        // The third provider call of the scenario (turn 3) received the
        // replayed turn 1 plus its own user message; turn 2 is absent.
        $recorded = $this->factory->getChatProvider()->getRecorded();
        $turnThree = $recorded[array_key_last($recorded)]->messages;
        self::assertCount(5, $turnThree);
        self::assertInstanceOf(ToolCallMessage::class, $turnThree[1]);
        self::assertInstanceOf(ToolResultMessage::class, $turnThree[2]);
        self::assertSame('Noch einmal: und das Getriebe?', $turnThree[4]->getContent());

        // What the provider received maps like the live messages of turn 1.
        $turnOneLive = \array_slice($live, 0, 4);
        self::assertSame($this->map([...$turnOneLive, $turnThree[4]]), $this->map($turnThree));
    }

    public function testTerminalTurnIsReplayedWithPairedIds(): void
    {
        $live = $this->runTerminalScenario();

        $reconstructed = $this->reconstructor->reconstruct($this->records);

        self::assertSame(
            [UserMessage::class, ToolCallMessage::class, ToolResultMessage::class, UserMessage::class, AssistantMessage::class],
            array_map(static fn (object $m): string => $m::class, $reconstructed),
        );
        self::assertSame($this->map($live), $this->map($reconstructed));

        $mapped = json_decode($this->map($reconstructed), true);
        // Two consecutive user messages: the tool results, then the next
        // question (no synthetic assistant message in between).
        self::assertSame(['user', 'assistant', 'user', 'user', 'assistant'], array_column($mapped, 'role'));
        $toolUses = array_values(array_filter($mapped[1]['content'], static fn (array $block): bool => 'tool_use' === $block['type']));
        self::assertSame(['toolu_search', 'toolu_submit'], array_column($toolUses, 'id'));
        self::assertSame(['tool_result', 'tool_result'], array_column($mapped[2]['content'], 'type'));
        self::assertSame(['toolu_search', 'toolu_submit'], array_column($mapped[2]['content'], 'tool_use_id'));
        // The tool result keeps the raw MCP content list.
        self::assertSame('[{"type":"text","text":"Alle 500 Stunden."}]', $mapped[2]['content'][1]['content']);

        // Turn 2 was sent with the replayed turn 1 and its own question.
        $recorded = $this->factory->getChatProvider()->getRecorded();
        self::assertCount(2, $recorded);
        $turnTwo = $recorded[1]->messages;
        self::assertSame($this->map([...\array_slice($live, 0, 3), $live[3]]), $this->map($turnTwo));
    }

    public function testUsageSurvivesTheReplay(): void
    {
        $this->runScenario();

        $reconstructed = $this->reconstructor->reconstruct($this->records);

        $usage = $reconstructed[1]->getUsage();
        self::assertInstanceOf(Usage::class, $usage);
        self::assertSame([120, 40, 7, 0], [$usage->inputTokens, $usage->outputTokens, $usage->cachedInputTokens, $usage->reasoningTokens]);
    }
}

// - revised 2026-10-14
