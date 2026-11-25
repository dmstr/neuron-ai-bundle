<?php

// file generated with AI assistance: Claude Code - 2026-10-04 13:45:01 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\Conversation;

use Dmstr\NeuronAiBundle\Conversation\ContextChunk;
use Dmstr\NeuronAiBundle\Conversation\Gate\AnswerabilityCheck;
use Dmstr\NeuronAiBundle\Conversation\Gate\GateCall;
use Dmstr\NeuronAiBundle\Conversation\Gate\GateOutcome;
use Dmstr\NeuronAiBundle\Conversation\Gate\GateReply;
use Dmstr\NeuronAiBundle\Testing\FakeProviderFactory;
use Dmstr\NeuronAiBundle\Tests\Support\GateTestTrait;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Usage;
use PHPUnit\Framework\TestCase;

/**
 * Kernel-free checks of the answerability check: the
 * request layout (system prompt, previous question and numbered context
 * before the marker, the question last), the verdict mapping, the strict
 * majority of the configured votes with failed votes counted as pass, the
 * votes list and summed usage of the outcome, all votes always run, the
 * stop closure asked before every vote and the provider default sampling
 * of the votes.
 */
final class AnswerabilityCheckTest extends TestCase
{
    use GateTestTrait;

    private const string QUESTION = 'Und die zweite?';
    private const string PREVIOUS = 'Welche Wartungsstufen gibt es?';

    public function testTheQuestionIsJudgedAloneFirstAndTheContextComesBeforeTheMarkedQuestion(): void
    {
        $factory = self::fakeFactory();
        $factory->getChatProvider()->useFixtures([
            self::QUESTION => 'Die zweite Stufe ist die Inspektion.',
            AnswerabilityCheck::MARKER.' '.self::QUESTION => '{"answerable": true, "reason": "Chunk 2 names the second stage."}',
        ]);

        $outcome = $this->check($factory)->check(self::answerabilityTurn(), self::QUESTION, self::PREVIOUS, self::context());

        self::assertSame([AnswerabilityCheck::MARKER.' '.self::QUESTION], $factory->getChatProvider()->getFixtureHits());
        $recorded = $factory->getChatProvider()->getRecorded();
        self::assertCount(1, $recorded, 'it passed alone, so the previous question was not needed');
        [$request] = $recorded;
        self::assertSame(AnswerabilityCheck::SYSTEM_PROMPT, $request->systemPrompt?->getContent());
        self::assertCount(1, $request->messages);
        self::assertSame(
            "Retrieved context:\n"
            ."[1] (handbuch.pdf > Kapitel 4 > Wartung)\nEs gibt drei Wartungsstufen.\n\n"
            ."[2] (https://example.org/wartung)\nDie zweite Stufe ist die Inspektion nach 500 Stunden.\n\n"
            .AnswerabilityCheck::MARKER.' '.self::QUESTION,
            (string) $request->messages[0]->getContent(),
        );
        self::assertSame(GateOutcome::ANSWERABILITY, $outcome->gate);
        self::assertFalse($outcome->isRefusal());
        self::assertSame('Chunk 2 names the second stage.', $outcome->reason);
        self::assertSame([['verdict' => 'pass', 'reason' => 'Chunk 2 names the second stage.']], $outcome->votes);
    }

    public function testAQuestionRefusedAloneIsAskedAgainWithThePreviousQuestionAndTheSecondVerdictDecides(): void
    {
        $factory = self::fakeFactory();
        self::queueVote($factory, false, 'Nothing about "the second one".', [40, 6]);
        self::queueVote($factory, true, 'With the previous question it is the second stage.', [41, 7]);

        $outcome = $this->check($factory)->check(self::answerabilityTurn(), self::QUESTION, self::PREVIOUS, self::context());

        $recorded = $factory->getChatProvider()->getRecorded();
        self::assertCount(2, $recorded);
        self::assertStringStartsWith('Retrieved context:', (string) $recorded[0]->messages[0]->getContent());
        self::assertStringStartsWith("Previous user question: Welche Wartungsstufen gibt es?\n\nRetrieved context:", (string) $recorded[1]->messages[0]->getContent());
        self::assertFalse($outcome->isRefusal());
        self::assertSame('With the previous question it is the second stage.', $outcome->reason);
        self::assertSame([
            ['verdict' => 'refuse', 'reason' => 'Nothing about "the second one".'],
            ['verdict' => 'pass', 'reason' => 'With the previous question it is the second stage.'],
        ], $outcome->votes);
        self::assertSame(['input_tokens' => 81, 'output_tokens' => 13], $outcome->usage);
    }

    public function testItRefusesOnlyWhenBothViewsRefuse(): void
    {
        $factory = self::fakeFactory();
        self::queueVote($factory, false, 'Not alone.', [40, 6]);
        self::queueVote($factory, false, 'Not with the previous question either.', [41, 7]);

        $outcome = $this->check($factory)->check(self::answerabilityTurn(), self::QUESTION, self::PREVIOUS, self::context());

        self::assertTrue($outcome->isRefusal());
        self::assertSame('Not with the previous question either.', $outcome->reason);
        self::assertCount(2, $outcome->votes ?? []);
        self::assertCount(2, $factory->getChatProvider()->getRecorded());
    }

    public function testWithoutAPreviousQuestionARefusalIsFinalAfterOneView(): void
    {
        $factory = self::fakeFactory();
        self::queueVote($factory, false, 'Not in the context.', [40, 6]);

        $outcome = $this->check($factory)->check(self::answerabilityTurn(), self::QUESTION, null, self::context());

        self::assertTrue($outcome->isRefusal());
        self::assertCount(1, $factory->getChatProvider()->getRecorded());
    }

    public function testFirstTurnAndEmptyContextHaveNoPreviousQuestionAndNoChunks(): void
    {
        self::assertSame("Retrieved context:\n(none)", AnswerabilityCheck::preamble(null, []));
        self::assertSame("Retrieved context:\n(none)", AnswerabilityCheck::preamble('  ', []));
        self::assertSame("Retrieved context:\n[1]\nText.", AnswerabilityCheck::preamble(null, [new ContextChunk('Text.', 0.5)]));
    }

    public function testVerdictNeedsABooleanAnswerable(): void
    {
        self::assertSame(['refuse' => true, 'reason' => 'x'], AnswerabilityCheck::verdict(['answerable' => false, 'reason' => 'x']));
        self::assertSame(['refuse' => false, 'reason' => null], AnswerabilityCheck::verdict(['answerable' => true, 'reason' => 3]));
        self::assertNull(AnswerabilityCheck::verdict(['answerable' => 'false']));
        self::assertNull(AnswerabilityCheck::verdict(['injection' => false]));
    }

    public function testThreeVotesTwoToOneRefuseWithTheFirstRefusingReasonAndSummedUsage(): void
    {
        $factory = self::fakeFactory();
        self::queueVote($factory, true, 'Covered.', [40, 6]);
        self::queueVote($factory, false, 'Not in the context.', [41, 7]);
        self::queueVote($factory, false, 'Only the first stage.', [42, 8]);

        $outcome = $this->check($factory)->check(self::answerabilityTurn(3), self::QUESTION, null, self::context());

        self::assertTrue($outcome->isRefusal());
        self::assertSame('Not in the context.', $outcome->reason);
        self::assertNull($outcome->failure);
        self::assertSame(['input_tokens' => 123, 'output_tokens' => 21], $outcome->usage);
        self::assertSame([
            'verdict' => 'refuse',
            'reason' => 'Not in the context.',
            'votes' => [
                ['verdict' => 'pass', 'reason' => 'Covered.'],
                ['verdict' => 'refuse', 'reason' => 'Not in the context.'],
                ['verdict' => 'refuse', 'reason' => 'Only the first stage.'],
            ],
            'usage' => ['input_tokens' => 123, 'output_tokens' => 21],
        ], $outcome->toPayload());
        self::assertCount(3, $factory->getRequestedProviders());
    }

    public function testThreeVotesTwoToOnePassWithTheFirstPassingReason(): void
    {
        $factory = self::fakeFactory();
        self::queueVote($factory, false, 'Not in the context.');
        self::queueVote($factory, true, 'Chunk 2 answers it.');
        self::queueVote($factory, true, 'Covered.');

        $outcome = $this->check($factory)->check(self::answerabilityTurn(3), self::QUESTION, null, self::context());

        self::assertFalse($outcome->isRefusal());
        self::assertSame('Chunk 2 answers it.', $outcome->reason);
        self::assertSame(['refuse', 'pass', 'pass'], array_column($outcome->votes ?? [], 'verdict'));
        self::assertSame(['input_tokens' => 120, 'output_tokens' => 18], $outcome->usage);
    }

    public function testSingleVoteDecidesAlone(): void
    {
        $factory = self::fakeFactory();
        self::queueVote($factory, false, 'Off-topic context.', null);

        $outcome = $this->check($factory)->check(self::answerabilityTurn(1), self::QUESTION, null, []);

        self::assertTrue($outcome->isRefusal());
        self::assertSame('Off-topic context.', $outcome->reason);
        self::assertSame([['verdict' => 'refuse', 'reason' => 'Off-topic context.']], $outcome->votes);
        self::assertNull($outcome->usage, 'no usage reported');
        self::assertCount(1, $factory->getRequestedProviders());
    }

    public function testFailedVotesCountAsPassAndEveryVoteRuns(): void
    {
        // One refusal, a provider error and an unparsable reply: 1 of 3 refuse.
        $factory = self::fakeFactory();
        self::queueVote($factory, false, 'Not in the context.', [40, 6]);
        $factory->queueFailure('Overloaded');
        $factory->queueAnswer('Kann ich nicht sagen.');

        $outcome = $this->check($factory)->check(self::answerabilityTurn(3), self::QUESTION, null, self::context());

        self::assertFalse($outcome->isRefusal());
        self::assertNull($outcome->reason, 'no decided vote on the winning side');
        self::assertNull($outcome->failure, 'one vote decided');
        self::assertSame([
            ['verdict' => 'refuse', 'reason' => 'Not in the context.'],
            ['verdict' => 'pass', 'reason' => null, 'failure' => GateReply::FAILURE_PROVIDER.': Overloaded'],
            ['verdict' => 'pass', 'reason' => null, 'failure' => GateReply::FAILURE_UNPARSABLE],
        ], $outcome->votes);
        self::assertSame(['input_tokens' => 40, 'output_tokens' => 6], $outcome->usage);
        self::assertCount(3, $factory->getRequestedProviders(), 'all votes run');
        self::assertCount(2, $this->warnings(), 'each failed vote is logged');

        // Two refusals of five are no strict majority.
        $factory = self::fakeFactory();
        self::queueVote($factory, false, 'a');
        self::queueVote($factory, false, 'b');
        $factory->queueFailure('Overloaded')->queueFailure('Overloaded')->queueFailure('Overloaded');

        self::assertFalse($this->check($factory)->check(self::answerabilityTurn(5), self::QUESTION, null, self::context())->isRefusal());

        // Three refusals of five are.
        $factory = self::fakeFactory();
        $factory->queueFailure('Overloaded');
        self::queueVote($factory, false, 'a');
        self::queueVote($factory, true, 'b');
        self::queueVote($factory, false, 'c');
        self::queueVote($factory, false, 'd');

        $refused = $this->check($factory)->check(self::answerabilityTurn(5), self::QUESTION, null, self::context());
        self::assertTrue($refused->isRefusal());
        self::assertSame('a', $refused->reason);
        self::assertCount(5, $factory->getRequestedProviders());
    }

    public function testAllVotesFailedPassesWithTheFirstFailureNote(): void
    {
        $factory = self::fakeFactory();
        $factory->queueFailure('Overloaded');
        $factory->getChatProvider()->addResponses(new AssistantMessage('{"answerable": "yes"}')->setUsage(new Usage(30, 4)));
        $factory->queueFailure('Timeout');

        $outcome = $this->check($factory)->check(self::answerabilityTurn(3), self::QUESTION, null, self::context());

        self::assertFalse($outcome->isRefusal());
        self::assertTrue($outcome->isFailure());
        self::assertSame(GateReply::FAILURE_PROVIDER.': Overloaded', $outcome->failure);
        self::assertNull($outcome->reason);
        self::assertSame(['input_tokens' => 30, 'output_tokens' => 4], $outcome->usage);
        self::assertSame(['verdict' => 'pass', 'reason' => null], array_diff_key($outcome->toPayload(), ['votes' => 0, 'failure' => 0, 'usage' => 0]));
        self::assertSame(GateReply::FAILURE_PROVIDER.': Overloaded', $outcome->toPayload()['failure'] ?? null);
    }

    public function testStopIsAskedBeforeEveryVote(): void
    {
        $factory = self::fakeFactory();
        self::queueVote($factory, false, 'Not in the context.');
        $asked = 0;
        // The second and third votes are skipped (failure `stopped`).
        $stop = static function () use (&$asked): bool {
            return ++$asked > 1;
        };

        $outcome = $this->check($factory)->check(self::answerabilityTurn(3), self::QUESTION, null, self::context(), $stop);

        self::assertSame(3, $asked);
        self::assertCount(1, $factory->getRequestedProviders());
        self::assertFalse($outcome->isRefusal(), 'one refusal of three');
        self::assertSame([null, GateReply::FAILURE_STOPPED, GateReply::FAILURE_STOPPED], array_map(static fn (array $vote): ?string => $vote['failure'] ?? null, $outcome->votes ?? []));

        // An exception of the stop closure (deadline, cancel) ends the check.
        $this->expectException(\RuntimeException::class);
        $this->check(self::fakeFactory())->check(self::answerabilityTurn(3), self::QUESTION, null, [], static fn (): bool => throw new \RuntimeException('deadline'));
    }

    public function testVotesUseTheProviderDefaultSampling(): void
    {
        $factory = self::fakeFactory();
        self::queueVote($factory, true, 'a');
        self::queueVote($factory, true, 'b');
        self::queueVote($factory, true, 'c');

        // The version samples at temperature 0 (GateTestTrait); the votes do not.
        $this->check($factory)->check(self::answerabilityTurn(3), self::QUESTION, null, self::context());

        self::assertSame([null, null, null], array_column($factory->getRequestedProviders(), 'temperature'));
        self::assertSame([null, null, null], array_column($factory->getRequestedProviders(), 'maxTokens'));
    }

    public function testFromVotesNeedsAVote(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        GateOutcome::fromVotes(GateOutcome::ANSWERABILITY, []);
    }

    private function check(FakeProviderFactory $factory): AnswerabilityCheck
    {
        return new AnswerabilityCheck(new GateCall($factory, $this->recordingLogger()));
    }

    /**
     * @return list<array{level: mixed, message: string, context: array<string, mixed>}>
     */
    private function warnings(): array
    {
        return array_values(array_filter($this->logged, static fn (array $entry): bool => 'warning' === $entry['level']));
    }

    /**
     * @return list<ContextChunk>
     */
    private static function context(): array
    {
        return [
            new ContextChunk('Es gibt drei Wartungsstufen.', 0.9, filename: 'handbuch.pdf', breadcrumb: 'Kapitel 4 > Wartung'),
            new ContextChunk('Die zweite Stufe ist die Inspektion nach 500 Stunden.', 0.8, sourceUrl: 'https://example.org/wartung'),
        ];
    }
}

// - revised 2026-10-14
