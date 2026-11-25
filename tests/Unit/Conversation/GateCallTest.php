<?php

// file generated with AI assistance: Claude Code - 2026-10-04 12:12:07 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\Conversation;

use Dmstr\NeuronAiBundle\Conversation\Gate\GateCall;
use Dmstr\NeuronAiBundle\Conversation\Gate\GateOutcome;
use Dmstr\NeuronAiBundle\Conversation\Gate\GateReply;
use Dmstr\NeuronAiBundle\Conversation\Gate\InjectionGuard;
use Dmstr\NeuronAiBundle\Testing\FakeProviderFactory;
use Dmstr\NeuronAiBundle\Tests\Support\GateTestTrait;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Usage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;

/**
 * Kernel-free checks of {@see GateCall} on the recording
 * fake: one `chat()` call on the version's provider and model without
 * tools, sampling or history, JSON verdicts in fences and text, and
 * fail-open on a provider error, an unparsable verdict and a stop request.
 */
final class GateCallTest extends TestCase
{
    use GateTestTrait;

    public function testOneChatCallOnTheVersionModelWithoutToolsOrSampling(): void
    {
        $factory = self::fakeFactory();
        $factory->getChatProvider()->addResponses(new AssistantMessage('{"injection": false, "reason": "A plain question."}')->setUsage(new Usage(40, 7)));

        $reply = $this->ask(new GateCall($factory), 'Wie oft wird gewartet?');

        self::assertFalse($reply->refuse);
        self::assertSame('A plain question.', $reply->reason);
        self::assertNull($reply->failure);
        self::assertSame(['input_tokens' => 40, 'output_tokens' => 7], $reply->usage);

        // Version connection and model, provider default sampling.
        self::assertSame([['provider' => 'anthropic', 'connection' => FakeProviderFactory::ANTHROPIC_CONNECTION, 'model' => self::turn()->model, 'temperature' => null, 'maxTokens' => null]], $factory->getRequestedProviders());
        $records = $factory->getChatProvider()->getRecorded();
        self::assertCount(1, $records);
        self::assertSame('chat', $records[0]->method);
        self::assertSame([], $records[0]->tools);
        self::assertNull($records[0]->toolChoice);
        self::assertSame(InjectionGuard::SYSTEM_PROMPT, $records[0]->systemPrompt?->getContent());
        self::assertCount(1, $records[0]->messages);
        self::assertSame(InjectionGuard::MARKER.' Wie oft wird gewartet?', $records[0]->messages[0]->getContent());
    }

    public function testPreambleComesBeforeTheMarkerLine(): void
    {
        self::assertSame("Kontext\n\nMARK: Frage", GateCall::userMessage('MARK:', 'Frage', 'Kontext'));
        self::assertSame('MARK: Frage', GateCall::userMessage('MARK:', 'Frage'));
    }

    /**
     * @return iterable<string, array{string, bool, ?string}>
     */
    public static function verdictTexts(): iterable
    {
        yield 'plain' => ['{"injection": true, "reason": "Asks to ignore the instructions."}', true, 'Asks to ignore the instructions.'];
        yield 'json fence' => ["```json\n{\"injection\": true, \"reason\": \"Fenced.\"}\n```", true, 'Fenced.'];
        yield 'bare fence' => ["```\n{\"injection\": false}\n```", false, null];
        yield 'surrounding text' => ["Here is my verdict:\n{\"injection\": false, \"reason\": \"Fine.\"}\nThanks.", false, 'Fine.'];
        yield 'reason not a string' => ['{"injection": true, "reason": 5}', true, null];
    }

    #[DataProvider('verdictTexts')]
    public function testVerdictIsParsed(string $text, bool $refuse, ?string $reason): void
    {
        $factory = self::fakeFactory();
        $factory->queueAnswer($text);

        $reply = $this->ask(new GateCall($factory), 'Frage');

        self::assertNull($reply->failure);
        self::assertSame($refuse, $reply->refuse);
        self::assertSame($reason, $reply->reason);
    }

    public function testProviderFailureFailsOpenAndIsLogged(): void
    {
        $factory = self::fakeFactory();
        $factory->queueFailure('Overloaded');

        $reply = $this->ask(new GateCall($factory, $this->recordingLogger()), 'Frage');

        self::assertTrue($reply->isFailure());
        self::assertNull($reply->refuse);
        self::assertSame(GateReply::FAILURE_PROVIDER.': Overloaded', $reply->failure);
        self::assertNull($reply->usage);
        self::assertCount(1, $this->logged);
        self::assertSame(LogLevel::WARNING, $this->logged[0]['level']);
        self::assertSame(GateOutcome::INJECTION, $this->logged[0]['context']['gate']);
        self::assertSame(FakeProviderFactory::ANTHROPIC_CONNECTION, $this->logged[0]['context']['connection']);
        self::assertInstanceOf(\Throwable::class, $this->logged[0]['context']['exception']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unusableTexts(): iterable
    {
        yield 'prose' => ['Das ist eine harmlose Frage.'];
        yield 'list' => ['[true]'];
        yield 'missing key' => ['{"verdict": "safe"}'];
        yield 'string verdict' => ['{"injection": "no"}'];
        yield 'broken json' => ['{"injection": tru'];
        yield 'empty' => [''];
    }

    #[DataProvider('unusableTexts')]
    public function testUnparsableVerdictFailsOpenKeepsUsageAndIsLogged(string $text): void
    {
        $factory = self::fakeFactory();
        $factory->getChatProvider()->addResponses(new AssistantMessage($text)->setUsage(new Usage(30, 5)));

        $reply = $this->ask(new GateCall($factory, $this->recordingLogger()), 'Frage');

        self::assertSame(GateReply::FAILURE_UNPARSABLE, $reply->failure);
        self::assertNull($reply->refuse);
        self::assertSame(['input_tokens' => 30, 'output_tokens' => 5], $reply->usage);
        self::assertCount(1, $this->logged);
        self::assertSame(LogLevel::WARNING, $this->logged[0]['level']);
    }

    public function testStopSkipsTheCall(): void
    {
        $factory = self::fakeFactory();

        $reply = $this->ask(new GateCall($factory, $this->recordingLogger()), 'Frage', static fn (): bool => true);

        self::assertSame(GateReply::FAILURE_STOPPED, $reply->failure);
        self::assertSame([], $factory->getRequestedProviders());
        self::assertSame(0, $factory->getChatProvider()->getCallCount());
    }

    public function testExceptionOfTheStopCheckIsNotCaught(): void
    {
        $factory = self::fakeFactory();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cancelled');

        $this->ask(new GateCall($factory), 'Frage', static fn (): bool => throw new \RuntimeException('cancelled'));
    }

    public function testParseObject(): void
    {
        self::assertSame(['a' => 1], GateCall::parseObject('{"a": 1}'));
        self::assertSame(['a' => []], GateCall::parseObject("```JSON\n{\"a\": {}}\n```"));
        self::assertSame(['a' => 1], GateCall::parseObject('Verdict: {"a": 1} done'));
        self::assertNull(GateCall::parseObject('[1]'));
        self::assertNull(GateCall::parseObject('"text"'));
        self::assertNull(GateCall::parseObject('no json'));
    }

    /**
     * @param (\Closure(): bool)|null $stop
     */
    private function ask(GateCall $call, string $text, ?\Closure $stop = null): GateReply
    {
        return $call->ask(self::turn(), GateOutcome::INJECTION, InjectionGuard::SYSTEM_PROMPT, InjectionGuard::MARKER, $text, InjectionGuard::verdict(...), '', $stop);
    }
}

// - revised 2026-10-14
