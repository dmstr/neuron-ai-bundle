<?php

// file generated with AI assistance: Claude Code - 2026-10-04 12:12:07 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Support;

use Dmstr\NeuronAiBundle\Agent\Template\TemplateSet;
use Dmstr\NeuronAiBundle\Agent\TurnConfiguration;
use Dmstr\NeuronAiBundle\Conversation\TurnTexts;
use Dmstr\NeuronAiBundle\Embeddings\EmbeddingModelRegistry;
use Dmstr\NeuronAiBundle\Entity\Agent;
use Dmstr\NeuronAiBundle\Entity\AgentVersion;
use Dmstr\NeuronAiBundle\Provider\ModelOptions;
use Dmstr\NeuronAiBundle\Testing\FakeProviderFactory;
use Dmstr\NeuronAiBundle\Testing\ProviderConnectionFixtures;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Usage;
use Psr\Log\AbstractLogger;

/**
 * Shared fixtures of the kernel-free answer gate tests: the fake provider
 * factory, a recording logger, a turn configuration (on the unsaved
 * `anthropic` fixture connection {@see FakeProviderFactory::ANTHROPIC_CONNECTION})
 * with the gate switches and templates under test and scripted
 * answerability votes.
 */
trait GateTestTrait
{
    /** @var list<array{level: mixed, message: string, context: array<string, mixed>}> */
    private array $logged = [];

    private static function fakeFactory(): FakeProviderFactory
    {
        return new FakeProviderFactory(new EmbeddingModelRegistry(['nomic-embed-text' => ['provider' => 'ollama', 'dimension' => 768]]));
    }

    private function recordingLogger(): AbstractLogger
    {
        $this->logged = [];

        return new class($this->logged) extends AbstractLogger {
            /**
             * @param list<array{level: mixed, message: string, context: array<string, mixed>}> $logged
             */
            public function __construct(private array &$logged)
            {
            }

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->logged[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
            }
        };
    }

    /**
     * A turn with the answerability check on, $votes votes and one
     * collection.
     *
     * @param array<string, array<string, string>> $templates
     */
    private static function answerabilityTurn(int $votes = 1, array $templates = [], string $language = 'de'): TurnConfiguration
    {
        return self::turn(false, $templates, $language, true, $votes, ['0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b']);
    }

    /**
     * One answerability vote as the next scripted answer of $factory:
     * `{"answerable": …, "reason": …}` with $usage tokens (none when null).
     *
     * @param array{int, int}|null $usage input and output tokens
     */
    private static function queueVote(FakeProviderFactory $factory, bool $answerable, string $reason, ?array $usage = [40, 6]): void
    {
        $message = new AssistantMessage((string) json_encode(['answerable' => $answerable, 'reason' => $reason]));
        if (null !== $usage) {
            $message->setUsage(new Usage(...$usage));
        }
        $factory->getChatProvider()->addResponses($message);
    }

    /**
     * @param array<string, array<string, string>> $templates
     * @param list<string>                         $collections
     */
    private static function turn(bool $injectionGuard = false, array $templates = [], string $language = 'de', bool $answerabilityCheck = false, int $answerabilityVotes = 1, array $collections = [], bool $followUps = false): TurnConfiguration
    {
        $agent = new Agent()->setName('Support Bot')->setSlug('support');
        $templateSet = new TemplateSet($templates, 'de');

        return new TurnConfiguration(
            version: new AgentVersion($agent, 1, Agent::candidateDefaults(), 'alice'),
            provider: 'anthropic',
            model: FakeProviderFactory::ANTHROPIC_MODEL,
            instructions: 'Du bist hilfreich.',
            responseSchema: null,
            collections: $collections,
            retrievalDepth: 10,
            tools: [],
            mcpConnections: [],
            terminalTools: [],
            maxToolCalls: null,
            maxUserTurns: null,
            modelOptions: new ModelOptions(temperature: 0.0, maxTokens: 50),
            language: $language,
            templates: $templateSet,
            texts: new TurnTexts($templateSet, $language, ['agentName' => 'Support Bot', 'language' => $language]),
            injectionGuard: $injectionGuard,
            answerabilityCheck: $answerabilityCheck,
            answerabilityVotes: $answerabilityVotes,
            followUps: $followUps,
            connection: ProviderConnectionFixtures::anthropic(),
        );
    }
}
