<?php

// file generated with AI assistance: Claude Code - 2026-10-04 12:12:07 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Conversation\Gate;

use Dmstr\NeuronAiBundle\Agent\TurnConfiguration;
use Dmstr\NeuronAiBundle\Provider\ToolChoiceAwareProviderInterface;
use Dmstr\NeuronAiBundle\ProviderFactory;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Chat\Messages\UserMessage;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * One provider call of an answer gate: the
 * provider connection and model of the agent version via
 * {@see ProviderFactory::chatProvider()} (a configuration without a
 * connection fails open), called directly with
 * `chat()` (never `structured()`), without an agent, tools or history and
 * with the provider's default sampling (votes must be able to differ).
 *
 * The request is the gate's system prompt plus one user message: an
 * optional preamble (context, previous question), then the gate's fixed
 * marker, a space and the text under test, last. A fixture of the fake
 * provider keyed `"<marker> <text>"` is therefore longer than a fixture
 * keyed by the text alone and wins for the gate call.
 *
 * The reply text is parsed as a JSON object (code fences and text around
 * the object are tolerated, {@see parseObject()}) and mapped to a verdict
 * by the gate's closure (for the follow-up gate, which never refuses, a
 * pass with the suggestions under `followUps`). The call fails open: a provider error, a reply
 * without a usable verdict or a stop request before the call give a
 * {@see GateReply} with a failure note, logged as a warning, and the turn
 * continues. The tokens the provider reported are kept in every case.
 */
final class GateCall
{
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly ProviderFactory $providerFactory,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * @param string                                                         $gate     one of {@see GateOutcome::GATES}, for the log
     * @param string                                                         $system   system prompt of the gate
     * @param string                                                         $marker   the gate's marker, written directly before $text
     * @param string                                                         $text     the text under test (the user message), last in the request
     * @param \Closure(array<string, mixed>): ?array{refuse: bool, reason: ?string, followUps?: list<string>} $verdict maps the decoded JSON object to the verdict; null when it holds none
     * @param string                                                         $preamble text in front of the marker line; '' = none
     * @param (\Closure(): bool)|null                                        $stop     asked before the provider call: true skips the call (failure {@see GateReply::FAILURE_STOPPED}); an exception it throws (deadline, cancellation) is not caught
     */
    public function ask(TurnConfiguration $turn, string $gate, string $system, string $marker, string $text, \Closure $verdict, string $preamble = '', ?\Closure $stop = null): GateReply
    {
        if (null !== $stop && $stop()) {
            return $this->failOpen($turn, $gate, GateReply::FAILURE_STOPPED);
        }

        try {
            $connection = $turn->getConnection() ?? throw new \LogicException('The agent configuration has no provider connection.');
            $provider = $this->providerFactory->chatProvider($connection, $turn->model);
            $provider->systemPrompt($system);
            $provider->setTools([]);
            if ($provider instanceof ToolChoiceAwareProviderInterface) {
                $provider->setToolChoice(null);
            }
            $response = $provider->chat(new UserMessage(self::userMessage($marker, $text, $preamble)))->message();
        } catch (\Throwable $e) {
            return $this->failOpen($turn, $gate, \sprintf('%s: %s', GateReply::FAILURE_PROVIDER, $e->getMessage()), null, $e);
        }

        $usage = self::usage($response->getUsage());
        $answer = (string) $response->getContent();
        $object = self::parseObject($answer);
        $mapped = null === $object ? null : $verdict($object);
        if (null === $mapped) {
            return $this->failOpen($turn, $gate, GateReply::FAILURE_UNPARSABLE, $usage, null, $answer);
        }

        return new GateReply($mapped['refuse'], $mapped['reason'], null, $usage, $mapped['followUps'] ?? []);
    }

    /**
     * The user message of a gate call: the preamble (if any) and a blank
     * line, then `<marker> <text>`.
     */
    public static function userMessage(string $marker, string $text, string $preamble = ''): string
    {
        return ('' === $preamble ? '' : $preamble."\n\n").$marker.' '.$text;
    }

    /**
     * The first JSON object in $answer: the whole text without code fences,
     * else the span from the first `{` to the last `}`. Null when there is
     * no JSON object (a list or a scalar is none).
     *
     * @return array<string, mixed>|null
     */
    public static function parseObject(string $answer): ?array
    {
        $text = trim($answer);
        $unfenced = preg_replace('/^```[A-Za-z]*\s*|\s*```$/', '', $text) ?? $text;

        $candidates = [$unfenced];
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if (false !== $start && false !== $end && $end > $start) {
            $candidates[] = substr($text, $start, $end - $start + 1);
        }

        foreach ($candidates as $candidate) {
            try {
                $decoded = json_decode($candidate, false, 512, \JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                continue;
            }
            if ($decoded instanceof \stdClass) {
                /** @var array<string, mixed> */
                return json_decode($candidate, true, 512, \JSON_THROW_ON_ERROR);
            }
        }

        return null;
    }

    /**
     * @return array{input_tokens: int, output_tokens: int}|null
     */
    private static function usage(?Usage $usage): ?array
    {
        return null === $usage ? null : ['input_tokens' => $usage->inputTokens, 'output_tokens' => $usage->outputTokens];
    }

    /**
     * @param array{input_tokens: int, output_tokens: int}|null $usage
     */
    private function failOpen(TurnConfiguration $turn, string $gate, string $failure, ?array $usage = null, ?\Throwable $exception = null, ?string $answer = null): GateReply
    {
        $this->logger->warning('Answer gate "{gate}" failed open: {failure}', array_filter([
            'gate' => $gate,
            'failure' => $failure,
            'provider' => $turn->provider,
            'connection' => $turn->getConnection()?->getName(),
            'model' => $turn->model,
            'answer' => null === $answer ? null : mb_substr($answer, 0, 500),
            'exception' => $exception,
        ], static fn (mixed $value): bool => null !== $value));

        return GateReply::failed($failure, $usage);
    }
}

// - revised 2026-10-14
