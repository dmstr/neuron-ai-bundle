<?php

// file generated with AI assistance: Claude Code - 2026-10-04 12:12:07 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\Conversation;

use Dmstr\NeuronAiBundle\Conversation\Gate\GateOutcome;
use Dmstr\NeuronAiBundle\Conversation\Gate\GateReply;
use Dmstr\NeuronAiBundle\Conversation\TurnRequest;
use Dmstr\NeuronAiBundle\Conversation\TurnResult;
use Dmstr\NeuronAiBundle\Conversation\TurnStep;
use Dmstr\NeuronAiBundle\Testing\FakeProviderFactory;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Usage;
use PHPUnit\Framework\TestCase;

/**
 * Kernel-free checks of the gate fields of {@see TurnResult}: gate
 * tokens count in totalUsage(), the share per
 * gate, the payload and a gate-refused result; without gates nothing
 * changes. The follow-up suggestions of the result: null without
 * the follow-up gate, else its list.
 */
final class TurnResultGatesTest extends TestCase
{
    public function testWithoutGatesTheTotalIsUnchanged(): void
    {
        $result = $this->answered(new Usage(30, 4, 10, 2));

        self::assertSame(['input_tokens' => 30, 'output_tokens' => 4, 'cached_input_tokens' => 10, 'reasoning_tokens' => 2], $result->totalUsage());
        self::assertSame($result->totalUsage(), $result->usageWithGates());
        self::assertSame([], $result->gateUsage());
        self::assertSame([], $result->gatesPayload());
        self::assertSame($result, $result->withGates());

        $none = $this->answered(null);
        self::assertNull($none->totalUsage());
        self::assertNull($none->usageWithGates());
    }

    public function testGateTokensCountInTheTotal(): void
    {
        $result = $this->answered(new Usage(30, 4, 10, 2))
            ->withGates(GateOutcome::pass(GateOutcome::INJECTION, 'fine', ['input_tokens' => 80, 'output_tokens' => 12]));

        self::assertSame(['input_tokens' => 110, 'output_tokens' => 16, 'cached_input_tokens' => 10, 'reasoning_tokens' => 2], $result->totalUsage());
        self::assertSame(['injection' => ['input_tokens' => 80, 'output_tokens' => 12]], $result->gateUsage());
        self::assertSame(
            ['input_tokens' => 110, 'output_tokens' => 16, 'cached_input_tokens' => 10, 'reasoning_tokens' => 2, 'gates' => ['injection' => ['input_tokens' => 80, 'output_tokens' => 12]]],
            $result->usageWithGates(),
        );
        self::assertSame('Alle 500 Stunden.', $result->answer);
        self::assertCount(1, $result->steps);
        self::assertFalse($result->isGateRefused());
    }

    public function testGateTokensAloneMakeATotal(): void
    {
        $result = $this->answered(null)->withGates(GateOutcome::pass(GateOutcome::INJECTION, null, ['input_tokens' => 5, 'output_tokens' => 1]));

        self::assertSame(['input_tokens' => 5, 'output_tokens' => 1, 'cached_input_tokens' => 0, 'reasoning_tokens' => 0], $result->totalUsage());
    }

    public function testGateWithoutUsageKeepsANullTotalButShowsItsShare(): void
    {
        $result = $this->answered(null)->withGates(GateOutcome::pass(GateOutcome::INJECTION, null, null, 'stopped'));

        self::assertNull($result->totalUsage());
        self::assertSame(
            ['input_tokens' => 0, 'output_tokens' => 0, 'cached_input_tokens' => 0, 'reasoning_tokens' => 0, 'gates' => ['injection' => ['input_tokens' => 0, 'output_tokens' => 0]]],
            $result->usageWithGates(),
        );
        self::assertSame(['injection' => ['verdict' => 'pass', 'reason' => null, 'failure' => 'stopped', 'usage' => ['input_tokens' => 0, 'output_tokens' => 0]]], $result->gatesPayload());
    }

    public function testGateRefusedResult(): void
    {
        $refusal = GateOutcome::refuse(GateOutcome::INJECTION, 'Asks for the system prompt.', ['input_tokens' => 80, 'output_tokens' => 12]);

        $result = TurnResult::gateRefused(self::request(), 'Dazu sage ich nichts.', [$refusal]);

        self::assertTrue($result->isGateRefused());
        self::assertSame($refusal, $result->gateRefusal());
        self::assertSame('Dazu sage ich nichts.', $result->answer);
        self::assertSame([], $result->steps);
        self::assertSame([], $result->context);
        self::assertFalse($result->isFailed());
        self::assertFalse($result->isRuled());
        self::assertSame(['input_tokens' => 80, 'output_tokens' => 12, 'cached_input_tokens' => 0, 'reasoning_tokens' => 0], $result->totalUsage());
    }

    public function testGateRefusedNeedsTheRefusingGateLast(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        TurnResult::gateRefused(self::request(), 'x', [GateOutcome::pass(GateOutcome::INJECTION)]);
    }

    public function testFollowUpsAreNullUntilTheFollowUpGateRan(): void
    {
        $result = $this->answered(new Usage(30, 4));
        self::assertNull($result->followUps());
        self::assertNull($result->withGates(GateOutcome::pass(GateOutcome::INJECTION))->followUps());

        $failed = $result->withGates(GateOutcome::pass(GateOutcome::FOLLOW_UPS, null, ['input_tokens' => 9, 'output_tokens' => 1], 'unparsable_verdict'));
        self::assertSame([], $failed->followUps());

        $suggested = $result->withGates(
            GateOutcome::pass(GateOutcome::INJECTION),
            GateOutcome::fromReply(GateOutcome::FOLLOW_UPS, new GateReply(false, null, null, ['input_tokens' => 90, 'output_tokens' => 20], ['Was kostet das?', 'Wer wartet?'])),
        );
        self::assertSame(['Was kostet das?', 'Wer wartet?'], $suggested->followUps());
        self::assertSame(['input_tokens' => 120, 'output_tokens' => 24, 'cached_input_tokens' => 0, 'reasoning_tokens' => 0], $suggested->totalUsage());
        self::assertSame(['injection' => ['input_tokens' => 0, 'output_tokens' => 0], 'followUps' => ['input_tokens' => 90, 'output_tokens' => 20]], $suggested->gateUsage());
        // The suggestions are a record field, not part of the internal payload.
        self::assertSame(['verdict' => 'pass', 'reason' => null, 'usage' => ['input_tokens' => 90, 'output_tokens' => 20]], $suggested->gatesPayload()['followUps']);
    }

    public function testAFailedReplyKeepsNoSuggestions(): void
    {
        $outcome = GateOutcome::fromReply(GateOutcome::FOLLOW_UPS, GateReply::failed(GateReply::FAILURE_PROVIDER.': x'));

        self::assertSame([], $outcome->followUps);
        self::assertSame(GateReply::FAILURE_PROVIDER.': x', $outcome->failure);
        self::assertFalse($outcome->isRefusal());
    }

    private function answered(?Usage $usage): TurnResult
    {
        $response = new AssistantMessage('Alle 500 Stunden.');
        if (null !== $usage) {
            $response->setUsage($usage);
        }

        return new TurnResult(self::request(), 'Du bist hilfreich.', [new TurnStep(1, $response, 5)], [], [], answer: 'Alle 500 Stunden.');
    }

    private static function request(): TurnRequest
    {
        return new TurnRequest('anthropic', FakeProviderFactory::ANTHROPIC_MODEL, 'Frage');
    }
}
