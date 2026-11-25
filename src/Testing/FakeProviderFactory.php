<?php

// file generated with AI assistance: Claude Code - 2026-09-30 05:42:49 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Testing;

use Dmstr\ApiConfiguration\Entity\ApiConfiguration;
use Dmstr\NeuronAiBundle\Embeddings\EmbeddingModelRegistry;
use Dmstr\NeuronAiBundle\Provider\ProviderType;
use Dmstr\NeuronAiBundle\Provider\ModelOptions;
use Dmstr\NeuronAiBundle\ProviderFactory;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;

/**
 * Provider factory for tests: no Anthropic or Ollama calls.
 *
 * Every chatProvider($connection, $model) call is recorded
 * (`['provider' => <connection type>, 'connection' => <connection name>,
 * 'model', 'temperature', 'maxTokens']` in this key order, see
 * getRequestedProviders()) and returns the same
 * {@see RecordingFakeAIProvider}, so a test can script the provider before
 * running the code under test and inspect the recorded requests afterwards.
 * The model is taken as is (no allow-list; availability is the business of
 * {@see FakeModelLister}); neither method contacts a provider or reads the
 * connection's secrets. {@see ProviderConnectionFixtures} builds matching
 * connections ({@see self::OLLAMA_CONNECTION}, {@see self::ANTHROPIC_CONNECTION}):
 *
 *     $factory->queueToolCall('search', ['query' => 'Wartung'])->queueAnswer('Alle 500 Stunden.');
 *     // ... code under test runs a turn
 *     $factory->getChatProvider()->assertCallCount(2);
 *
 * Streaming: queueStreamFailure() fails a streamed answer after N chunks,
 * setChunkDelayMs() paces the chunks and setChunkHook() acts before each
 * chunk (see {@see RecordingFakeAIProvider}); queueToolCall() with a
 * `text` streams that text as tokens before the tool events of the
 * response. For out-of-process callers
 * (php-fpm serving the test kernel) the constructor flags give the shared
 * fake a default: with $echo an empty script answers with the last user
 * message instead of throwing, and $chunkDelayMs is the initial pacing.
 * With the defaults nothing changes.
 *
 * Fixture mode: useFixtures() answers calls by a key phrase contained in
 * the last user message instead of by call order (see
 * {@see RecordingFakeAIProvider}); unmatched calls use the script as
 * before. {@see FixtureSet::load()} reads a fixture set from JSON:
 *
 *     $factory->useFixtures(FixtureSet::load(__DIR__.'/../Fixtures/evaluation/answers.json'));
 *
 * Tool choice: the shared fake mirrors production, where only the
 * Anthropic provider supports a tool choice. Each chatProvider() call
 * sets it to decline every choice for a connection of type `ollama`
 * ({@see ProviderType::of()}) and to accept every choice for `anthropic`,
 * so a turn on `anthropic` sees a capable provider and a turn on `ollama`
 * the fallback path, with one shared instance. declineToolChoice() makes
 * it decline for `anthropic` too, to test the fallback on a capable
 * provider; declineForcingChoices() makes it
 * decline only `any` and `tool` (accepting `auto` and `none`), standing in
 * for Anthropic's 5.x model lines.
 *
 * embeddingsProvider($connection, $model) records `['provider',
 * 'connection', 'model']` in getRequestedEmbeddings() and returns a
 * {@see FakeEmbeddingsProvider} with the model's registered dimension. All
 * of them record on one shared {@see FakeEmbeddingsRecorder}:
 *
 *     $factory->getEmbeddingsRecorder()->assertEmbeddedText('search_query: hallo', 'nomic-embed-text');
 */
class FakeProviderFactory extends ProviderFactory
{
    /** Models the tests use by convention; any non-empty model is accepted. */
    public const string ANTHROPIC_MODEL = 'claude-haiku-4-5-20251001';
    public const string OLLAMA_MODEL = 'llama3.2';

    /** Default names of the provider connections of {@see ProviderConnectionFixtures}. */
    public const string OLLAMA_CONNECTION = 'fake-ollama';
    public const string ANTHROPIC_CONNECTION = 'fake-anthropic';

    private readonly RecordingFakeAIProvider $chatProvider;

    private readonly FakeEmbeddingsRecorder $recorder;

    /** @var list<array{provider: string, connection: string, model: string, temperature: ?float, maxTokens: ?int}> */
    private array $requestedProviders = [];

    /** @var list<array{provider: string, connection: string, model: string}> */
    private array $requestedEmbeddings = [];

    private bool $declineToolChoice = false;

    private bool $declineForcingChoices = false;

    /**
     * @param bool $echo         answer with the last user message when the script is empty (instead of throwing)
     * @param int  $chunkDelayMs initial sleep before every streamed chunk
     */
    public function __construct(EmbeddingModelRegistry $registry, bool $echo = false, int $chunkDelayMs = 0)
    {
        parent::__construct($registry);
        $this->chatProvider = new RecordingFakeAIProvider()
            ->setEcho($echo)
            ->setChunkDelayMs($chunkDelayMs);
        $this->recorder = new FakeEmbeddingsRecorder();
    }

    /**
     * Records the request (`provider` = the connection type, `connection`
     * = its name, the model, and `temperature` and `maxTokens` of the model options) and returns the shared
     * fake. No allow-list: any non-empty model is accepted. A connection of
     * another type (`mcp`, `firecrawl`) throws \InvalidArgumentException
     * as in production. The tool choice is declined for type `ollama` or
     * with declineToolChoice() on, `any` and `tool` are declined with
     * declineForcingChoices() on, all choices are accepted otherwise.
     */
    public function chatProvider(ApiConfiguration $connection, string $model, ?ModelOptions $options = null): AIProviderInterface
    {
        $type = ProviderType::of($connection);
        $this->requestedProviders[] = ['provider' => $type, 'connection' => ProviderType::nameOf($connection), 'model' => $model, 'temperature' => $options?->temperature, 'maxTokens' => $options?->maxTokens];
        if ('' === trim($model)) {
            throw new \InvalidArgumentException(\sprintf('No model given for connection "%s".', ProviderType::nameOf($connection)));
        }
        $this->chatProvider->declineToolChoice(ProviderType::OLLAMA === $type || $this->declineToolChoice);
        $this->chatProvider->declineForcingChoices($this->declineForcingChoices);

        return $this->chatProvider;
    }

    /**
     * Records the request in getRequestedEmbeddings() and returns a
     * {@see FakeEmbeddingsProvider} on the shared recorder. Non-`ollama`
     * connections and unregistered models throw \InvalidArgumentException
     * as in production.
     */
    public function embeddingsProvider(ApiConfiguration $connection, string $model): EmbeddingsProviderInterface
    {
        if (!ProviderType::isEmbedding($connection)) {
            throw new \InvalidArgumentException(\sprintf('Connection "%s" cannot serve embeddings (expected one of: %s).', ProviderType::nameOf($connection), implode(', ', ProviderType::EMBEDDING_TYPES)));
        }
        $this->requestedEmbeddings[] = ['provider' => ProviderType::OLLAMA, 'connection' => ProviderType::nameOf($connection), 'model' => $model];

        return new FakeEmbeddingsProvider($model, $this->registry->get($model)->dimension, $this->recorder);
    }

    /**
     * @return list<array{provider: string, connection: string, model: string}> every embeddingsProvider() call in order
     */
    public function getRequestedEmbeddings(): array
    {
        return $this->requestedEmbeddings;
    }

    /**
     * Makes the shared fake decline tool choices on every provider, from
     * the next chatProvider() call on.
     */
    public function declineToolChoice(bool $decline = true): self
    {
        $this->declineToolChoice = $decline;

        return $this;
    }

    /**
     * Makes the shared fake decline the forcing choices `any` and `tool`
     * (accepting `auto` and `none`), from the next chatProvider() call on.
     */
    public function declineForcingChoices(bool $decline = true): self
    {
        $this->declineForcingChoices = $decline;

        return $this;
    }

    /**
     * @return list<array{provider: string, connection: string, model: string, temperature: ?float, maxTokens: ?int}> every chatProvider() call in order; `temperature` and `maxTokens` of the model options are always present (null when absent)
     */
    public function getRequestedProviders(): array
    {
        return $this->requestedProviders;
    }

    public function getChatProvider(): RecordingFakeAIProvider
    {
        return $this->chatProvider;
    }

    /**
     * Appends an assistant answer to the shared fake's script.
     */
    public function queueAnswer(string $text): self
    {
        $this->chatProvider->queueAnswer($text);

        return $this;
    }

    /**
     * Appends a response that used up `max_tokens` thinking, without text.
     */
    public function queueThinkingCutOff(string $reasoning): self
    {
        $this->chatProvider->queueThinkingCutOff($reasoning);

        return $this;
    }

    /**
     * Appends an answer with a reasoning block ($signature = block id).
     */
    public function queueReasoning(string $reasoning, string $answer, string $signature = 'sig'): self
    {
        $this->chatProvider->queueReasoning($reasoning, $answer, $signature);

        return $this;
    }

    /**
     * Appends a tool call; consecutive calls form one response with
     * parallel calls. Default call ids are unique within the fake. $text is
     * the text of that response before its tool calls (streamed as tokens
     * before the tool events).
     *
     * @param array<string, mixed> $inputs
     */
    public function queueToolCall(string $name, array $inputs = [], ?string $callId = null, ?string $reasoning = null, string $signature = 'sig', ?string $text = null): self
    {
        $this->chatProvider->queueToolCall($name, $inputs, $callId, $reasoning, $signature, $text);

        return $this;
    }

    /**
     * The next provider call throws a ProviderException with $message.
     */
    public function queueFailure(string $message): self
    {
        $this->chatProvider->queueFailure($message);

        return $this;
    }

    /**
     * The next streamed answer fails after $afterChunks chunks (see
     * {@see RecordingFakeAIProvider::queueStreamFailure()}).
     */
    public function queueStreamFailure(string $message, int $afterChunks, ?string $text = null): self
    {
        $this->chatProvider->queueStreamFailure($message, $afterChunks, $text);

        return $this;
    }

    /**
     * Replaces the fixture set of the shared fake (key phrase => fixture,
     * see {@see RecordingFakeAIProvider::useFixtures()}).
     *
     * @param array<string, string|array<string, mixed>> $byKey
     */
    public function useFixtures(array $byKey): self
    {
        $this->chatProvider->useFixtures($byKey);

        return $this;
    }

    /**
     * Empties the fixture set of the shared fake.
     */
    public function clearFixtures(): self
    {
        $this->chatProvider->clearFixtures();

        return $this;
    }

    /**
     * Sleep before every streamed chunk, in milliseconds.
     */
    public function setChunkDelayMs(int $ms): self
    {
        $this->chatProvider->setChunkDelayMs($ms);

        return $this;
    }

    /**
     * Characters per streamed chunk (default 5).
     */
    public function setChunkSize(int $chars): self
    {
        $this->chatProvider->setChunkSize($chars);

        return $this;
    }

    /**
     * @param (\Closure(int, \NeuronAI\Chat\Messages\Stream\Chunks\StreamChunk): void)|null $hook called before each streamed chunk is yielded (index from 0 per provider call)
     */
    public function setChunkHook(?\Closure $hook): self
    {
        $this->chatProvider->setChunkHook($hook);

        return $this;
    }

    public function getEmbeddingsRecorder(): FakeEmbeddingsRecorder
    {
        return $this->recorder;
    }
}
// - revised 2026-11-18
