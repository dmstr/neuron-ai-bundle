<?php

// file generated with AI assistance: Claude Code - 2026-10-04 13:44:59 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\Conversation;

use Dmstr\NeuronAiBundle\Conversation\ContextChunk;
use Dmstr\NeuronAiBundle\Conversation\Gate\FollowUpGenerator;
use Dmstr\NeuronAiBundle\Conversation\Gate\GateCall;
use Dmstr\NeuronAiBundle\Conversation\Gate\GateOutcome;
use Dmstr\NeuronAiBundle\Conversation\Gate\GateReply;
use Dmstr\NeuronAiBundle\Conversation\Gate\InjectionGuard;
use Dmstr\NeuronAiBundle\Conversation\Gate\TurnGates;
use Dmstr\NeuronAiBundle\Testing\FakeProviderFactory;
use Dmstr\NeuronAiBundle\Tests\Support\GateTestTrait;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Usage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Kernel-free checks of the follow-up gate ({@see FollowUpGenerator})
 * on the recording fake: one `chat()` call with the
 * marker directly before the question, the preamble (context, answer,
 * language), normalisation of the suggestions, fail-open with `[]` and a
 * note, usage, the stop closure and the {@see TurnGates::afterAnswer()}
 * switch.
 */
final class FollowUpGeneratorTest extends TestCase
{
    use GateTestTrait;

    private const string QUESTION = 'Wie oft wird die Anlage gewartet?';
    private const string ANSWER = 'Alle 500 Betriebsstunden.';

    public function testOneChatCallWithTheMarkerDirectlyBeforeTheQuestionSoItsFixtureWins(): void
    {
        $factory = self::fakeFactory();
        $factory->getChatProvider()->useFixtures([
            self::QUESTION => self::ANSWER,
            FollowUpGenerator::MARKER.' '.self::QUESTION => '{"followUps": ["Was kostet eine Wartung?"]}',
        ]);
        $context = [new ContextChunk('Die Anlage wird alle 500 Stunden gewartet.', 0.9), new ContextChunk('Eine Wartung kostet 300 Euro.', 0.8)];

        $outcome = $this->generator($factory)->suggest(self::turn(followUps: true), self::QUESTION, self::ANSWER, $context);

        self::assertSame([FollowUpGenerator::MARKER.' '.self::QUESTION], $factory->getChatProvider()->getFixtureHits());
        self::assertSame(GateOutcome::FOLLOW_UPS, $outcome->gate);
        self::assertSame(GateOutcome::PASS, $outcome->verdict);
        self::assertSame(['Was kostet eine Wartung?'], $outcome->followUps);
        self::assertNull($outcome->failure);

        $records = $factory->getChatProvider()->getRecorded();
        self::assertCount(1, $records);
        self::assertSame('chat', $records[0]->method);
        self::assertSame([], $records[0]->tools);
        self::assertSame(FollowUpGenerator::SYSTEM_PROMPT, $records[0]->systemPrompt?->getContent());
        self::assertCount(1, $records[0]->messages);
        self::assertSame(
            "Context:\nDie Anlage wird alle 500 Stunden gewartet.\n\n---\n\nEine Wartung kostet 300 Euro.\n\n"
            ."Answer:\n".self::ANSWER."\n\n"
            ."Language of the follow-up questions: de\n\n"
            .FollowUpGenerator::MARKER.' '.self::QUESTION,
            $records[0]->messages[0]->getContent(),
        );
        self::assertSame([['provider' => 'anthropic', 'connection' => FakeProviderFactory::ANTHROPIC_CONNECTION, 'model' => FakeProviderFactory::ANTHROPIC_MODEL, 'temperature' => null, 'maxTokens' => null]], $factory->getRequestedProviders());
    }

    public function testPreambleWithoutContextHasNoContextBlockAndTheEffectiveLanguage(): void
    {
        self::assertSame("Answer:\nYes.\n\nLanguage of the follow-up questions: en", FollowUpGenerator::preamble('en', 'Yes.', []));

        $factory = self::fakeFactory();
        $factory->queueAnswer('{"followUps": []}');
        $this->generator($factory)->suggest(self::turn(language: 'en', followUps: true), 'Is it safe?', 'Yes.', []);

        self::assertSame(
            "Answer:\nYes.\n\nLanguage of the follow-up questions: en\n\n".FollowUpGenerator::MARKER.' Is it safe?',
            $factory->getChatProvider()->getRecorded()[0]->messages[0]->getContent(),
        );
    }

    public function testSuggestionsAreNormalisedAndTheUsageKept(): void
    {
        $long = str_repeat('ä', 250);
        $reply = json_encode(['followUps' => [
            '  Was kostet eine Wartung?  ',
            '',
            '   ',
            42,
            null,
            ['nested'],
            'Was kostet eine Wartung?',
            $long,
            'Wer führt die Wartung durch?',
            'Eine vierte Frage?',
        ]], \JSON_THROW_ON_ERROR);
        $factory = self::fakeFactory();
        $factory->getChatProvider()->addResponses(new AssistantMessage("```json\n".$reply."\n```")->setUsage(new Usage(120, 30)));

        $outcome = $this->generator($factory)->suggest(self::turn(followUps: true), self::QUESTION, self::ANSWER, []);

        self::assertSame(['Was kostet eine Wartung?', str_repeat('ä', FollowUpGenerator::MAX_LENGTH), 'Wer führt die Wartung durch?'], $outcome->followUps);
        self::assertSame(200, mb_strlen($outcome->followUps[1]));
        self::assertNull($outcome->failure);
        self::assertSame(['input_tokens' => 120, 'output_tokens' => 30], $outcome->usage);
        self::assertSame(['verdict' => 'pass', 'reason' => null, 'usage' => ['input_tokens' => 120, 'output_tokens' => 30]], $outcome->toPayload());
    }

    public function testNormaliseDropsWhatIsNotUsable(): void
    {
        self::assertSame([], FollowUpGenerator::normalise([]));
        self::assertSame([], FollowUpGenerator::normalise([1, true, null, '', ' ']));
        self::assertSame(['a', 'b', 'c'], FollowUpGenerator::normalise(['a', ' a ', 'b', 'c', 'd']));
        // Cut first, then compared: two texts equal in their first 200 characters are one.
        self::assertSame([str_repeat('x', 200)], FollowUpGenerator::normalise([str_repeat('x', 201), str_repeat('x', 202)]));
    }

    public function testASuccessfulCallWithoutUsableEntriesPassesWithoutFailure(): void
    {
        $factory = self::fakeFactory();
        $factory->getChatProvider()->addResponses(new AssistantMessage('{"followUps": ["", 3]}')->setUsage(new Usage(60, 5)));

        $outcome = $this->generator($factory)->suggest(self::turn(followUps: true), self::QUESTION, self::ANSWER, []);

        self::assertSame([], $outcome->followUps);
        self::assertNull($outcome->failure);
        self::assertSame(['input_tokens' => 60, 'output_tokens' => 5], $outcome->usage);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unusableReplies(): iterable
    {
        yield 'prose' => ['Here are some follow-up questions: what does it cost?'];
        yield 'no followUps key' => ['{"questions": ["Was kostet das?"]}'];
        yield 'followUps not a list' => ['{"followUps": "Was kostet das?"}'];
        yield 'followUps an object' => ['{"followUps": {"a": "Was kostet das?"}}'];
        yield 'a bare list' => ['["Was kostet das?"]'];
    }

    #[DataProvider('unusableReplies')]
    public function testAnUnusableReplyGivesNoSuggestionsAndAFailureNote(string $reply): void
    {
        $factory = self::fakeFactory();
        $factory->getChatProvider()->addResponses(new AssistantMessage($reply)->setUsage(new Usage(70, 9)));

        $outcome = $this->generator($factory)->suggest(self::turn(followUps: true), self::QUESTION, self::ANSWER, []);

        self::assertSame(GateOutcome::PASS, $outcome->verdict);
        self::assertSame([], $outcome->followUps);
        self::assertSame(GateReply::FAILURE_UNPARSABLE, $outcome->failure);
        self::assertSame(['input_tokens' => 70, 'output_tokens' => 9], $outcome->usage);
        self::assertSame('Answer gate "{gate}" failed open: {failure}', $this->logged[0]['message'] ?? null);
        self::assertSame(GateOutcome::FOLLOW_UPS, $this->logged[0]['context']['gate'] ?? null);
    }

    public function testAProviderFailureGivesNoSuggestionsAndAFailureNote(): void
    {
        $factory = self::fakeFactory();
        $factory->queueFailure('Overloaded');

        $outcome = $this->generator($factory)->suggest(self::turn(followUps: true), self::QUESTION, self::ANSWER, []);

        self::assertSame(GateOutcome::PASS, $outcome->verdict);
        self::assertSame([], $outcome->followUps);
        self::assertSame(GateReply::FAILURE_PROVIDER.': Overloaded', $outcome->failure);
        self::assertNull($outcome->usage);
    }

    public function testAStopRequestSkipsTheCallAndAStopExceptionPropagates(): void
    {
        $factory = self::fakeFactory();

        $stopped = $this->generator($factory)->suggest(self::turn(followUps: true), self::QUESTION, self::ANSWER, [], static fn (): bool => true);

        self::assertSame([], $stopped->followUps);
        self::assertSame(GateReply::FAILURE_STOPPED, $stopped->failure);
        self::assertSame([], $factory->getRequestedProviders());

        try {
            $this->generator($factory)->suggest(self::turn(followUps: true), self::QUESTION, self::ANSWER, [], static fn (): bool => throw new \RuntimeException('cancelled'));
            self::fail('The stop exception was swallowed.');
        } catch (\RuntimeException $e) {
            self::assertSame('cancelled', $e->getMessage());
        }
        self::assertSame([], $factory->getRequestedProviders());
    }

    public function testTurnGatesRunTheGeneratorOnlyWhenFollowUpsAreOn(): void
    {
        $factory = self::fakeFactory();
        $gates = new TurnGates(new InjectionGuard(new GateCall($factory)), followUpGenerator: $this->generator($factory));

        self::assertNull($gates->afterAnswer(self::turn(injectionGuard: true), self::QUESTION, self::ANSWER, []));
        self::assertSame([], $factory->getRequestedProviders());

        $factory->queueAnswer('{"followUps": ["Was kostet eine Wartung?"]}');
        $outcome = $gates->afterAnswer(self::turn(followUps: true), self::QUESTION, self::ANSWER, []);

        self::assertInstanceOf(GateOutcome::class, $outcome);
        self::assertSame(['Was kostet eine Wartung?'], $outcome->followUps);
        self::assertCount(1, $factory->getRequestedProviders());
    }

    public function testFollowUpsOnWithoutTheGeneratorIsAConfigurationError(): void
    {
        $gates = new TurnGates(new InjectionGuard(new GateCall(self::fakeFactory())));

        $this->expectException(\LogicException::class);

        $gates->afterAnswer(self::turn(followUps: true), self::QUESTION, self::ANSWER, []);
    }

    private function generator(FakeProviderFactory $factory): FollowUpGenerator
    {
        return new FollowUpGenerator(new GateCall($factory, $this->recordingLogger()));
    }
}

// - revised 2026-10-14
