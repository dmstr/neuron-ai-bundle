<?php

// file generated with AI assistance: Claude Code - 2026-10-04 12:12:07 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\Conversation;

use Dmstr\NeuronAiBundle\Agent\Template\TemplateSet;
use Dmstr\NeuronAiBundle\Conversation\Gate\GateClose;
use Dmstr\NeuronAiBundle\Conversation\Gate\GateOutcome;
use Dmstr\NeuronAiBundle\Conversation\Gate\GateReply;
use Dmstr\NeuronAiBundle\Conversation\MessageKind;
use PHPUnit\Framework\TestCase;

/**
 * Kernel-free checks of {@see GateOutcome} and {@see GateClose}: the
 * payload shape stored under `providerPayload.gates.<gate>`, the kinds and close reasons, slots and the agent-chat
 * summary.
 */
final class GateOutcomeTest extends TestCase
{
    public function testPayloadOfARefusal(): void
    {
        $outcome = GateOutcome::refuse(GateOutcome::INJECTION, 'Asks for the system prompt.', ['input_tokens' => 80, 'output_tokens' => 12]);

        self::assertSame([
            'verdict' => 'refuse',
            'reason' => 'Asks for the system prompt.',
            'usage' => ['input_tokens' => 80, 'output_tokens' => 12],
        ], $outcome->toPayload());
    }

    public function testPayloadOfAFailureWithoutUsage(): void
    {
        $outcome = GateOutcome::pass(GateOutcome::INJECTION, null, null, 'provider_error: Overloaded');

        self::assertSame([
            'verdict' => 'pass',
            'reason' => null,
            'failure' => 'provider_error: Overloaded',
            'usage' => ['input_tokens' => 0, 'output_tokens' => 0],
        ], $outcome->toPayload());
        self::assertTrue($outcome->isFailure());
        self::assertFalse($outcome->isRefusal());
    }

    public function testPayloadWithVotes(): void
    {
        $votes = [
            ['verdict' => 'refuse', 'reason' => 'Not in the context.'],
            ['verdict' => 'pass', 'reason' => 'Chunk 2 answers it.'],
            ['verdict' => 'refuse', 'reason' => null, 'failure' => 'unparsable_verdict'],
        ];
        $outcome = new GateOutcome(GateOutcome::ANSWERABILITY, GateOutcome::REFUSE, 'Not in the context.', $votes, null, ['input_tokens' => 300, 'output_tokens' => 30]);

        self::assertSame(['verdict', 'reason', 'votes', 'usage'], array_keys($outcome->toPayload()));
        self::assertSame($votes, $outcome->toPayload()['votes']);
    }

    public function testFromReply(): void
    {
        $usage = ['input_tokens' => 10, 'output_tokens' => 2];

        $refusal = GateOutcome::fromReply(GateOutcome::INJECTION, new GateReply(true, 'bad', null, $usage));
        self::assertTrue($refusal->isRefusal());
        self::assertSame('bad', $refusal->reason);
        self::assertSame($usage, $refusal->usage);

        $pass = GateOutcome::fromReply(GateOutcome::INJECTION, new GateReply(false, 'fine', null, $usage));
        self::assertFalse($pass->isRefusal());
        self::assertNull($pass->failure);

        $failed = GateOutcome::fromReply(GateOutcome::INJECTION, GateReply::failed(GateReply::FAILURE_UNPARSABLE, $usage));
        self::assertFalse($failed->isRefusal());
        self::assertSame(GateReply::FAILURE_UNPARSABLE, $failed->failure);
        self::assertSame($usage, $failed->usage);
    }

    public function testInvalidOutcomesAreRejected(): void
    {
        foreach ([
            static fn (): GateOutcome => new GateOutcome('source', GateOutcome::PASS),
            static fn (): GateOutcome => new GateOutcome(GateOutcome::INJECTION, 'maybe'),
            static fn (): GateOutcome => new GateOutcome(GateOutcome::FOLLOW_UPS, GateOutcome::REFUSE),
        ] as $index => $build) {
            try {
                $build();
                self::fail((string) $index);
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testKindsCloseReasonsAndSlots(): void
    {
        self::assertSame('injection_refusal', MessageKind::INJECTION_REFUSAL);
        self::assertSame('unanswerable_refusal', MessageKind::UNANSWERABLE_REFUSAL);
        self::assertContains(MessageKind::INJECTION_REFUSAL, MessageKind::all());
        self::assertContains(MessageKind::UNANSWERABLE_REFUSAL, MessageKind::all());
        foreach (MessageKind::all() as $kind) {
            self::assertLessThanOrEqual(20, \strlen($kind), $kind);
        }

        self::assertSame(MessageKind::INJECTION_REFUSAL, GateClose::reason(GateOutcome::INJECTION));
        self::assertSame(MessageKind::UNANSWERABLE_REFUSAL, GateClose::reason(GateOutcome::ANSWERABILITY));
        self::assertSame(TemplateSet::INJECTION_REFUSAL, GateClose::slot(GateOutcome::INJECTION));
        self::assertSame(TemplateSet::UNANSWERABLE_REFUSAL, GateClose::slot(GateOutcome::ANSWERABILITY));
        self::assertTrue(GateClose::isRefusalKind(MessageKind::INJECTION_REFUSAL));
        self::assertTrue(GateClose::isRefusalKind(MessageKind::UNANSWERABLE_REFUSAL));
        self::assertFalse(GateClose::isRefusalKind(MessageKind::RULE_REFUSAL));

        $this->expectException(\InvalidArgumentException::class);
        GateClose::reason(GateOutcome::FOLLOW_UPS);
    }

    public function testSummaryHasNoReason(): void
    {
        self::assertSame(
            ['gate' => 'injection', 'kind' => 'injection_refusal', 'template' => 'injectionRefusal'],
            GateClose::summary(GateOutcome::refuse(GateOutcome::INJECTION, 'internal reason')),
        );
    }
}
