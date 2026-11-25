<?php

// file generated with AI assistance: Claude Code - 2026-10-02 05:54:24 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\Provider;

use Dmstr\NeuronAiBundle\Embeddings\EmbeddingModelRegistry;
use Dmstr\NeuronAiBundle\Provider\AnthropicWithToolChoice;
use Dmstr\NeuronAiBundle\Provider\ToolChoice;
use Dmstr\NeuronAiBundle\Provider\ToolChoiceAwareProviderInterface;
use Dmstr\NeuronAiBundle\ProviderFactory;
use Dmstr\NeuronAiBundle\Testing\CallbackTool;
use Dmstr\NeuronAiBundle\Testing\ConfigSecretsFixture;
use Dmstr\NeuronAiBundle\Testing\ProviderConnectionFixtures;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\HttpClient\HttpResponse;
use NeuronAI\HttpClient\StreamInterface;
use NeuronAI\Providers\Ollama\Ollama;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The Anthropic provider with tool choice: the request bodies of chat and
 * stream (captured by a fake HTTP client), the support matrix per model
 * and the factory wiring.
 */
final class AnthropicToolChoiceTest extends TestCase
{
    private const string HAIKU = 'claude-haiku-4-5-20251001';

    private CapturingHttpClient $http;

    protected function setUp(): void
    {
        $this->http = new CapturingHttpClient();
    }

    public function testNoneChoiceIsSentWithChatAndStream(): void
    {
        $provider = $this->provider()->setTools([new CallbackTool('search', 'Searches the documents.', static fn (): string => '')]);
        $provider->setToolChoice(ToolChoice::none());

        self::assertSame('ok', $provider->chat(new UserMessage('Hallo'))->message()->getContent());
        self::assertSame(['type' => 'none'], $this->http->requests[0]->body['tool_choice'] ?? null);
        self::assertArrayNotHasKey('stream', $this->http->requests[0]->body);

        $stream = $provider->stream(new UserMessage('Hallo'));
        foreach ($stream as $chunk) {
        }
        self::assertSame('ok', $stream->getReturn()->message()->getContent());
        self::assertSame(['type' => 'none'], $this->http->requests[1]->body['tool_choice'] ?? null);
        self::assertTrue($this->http->requests[1]->body['stream'] ?? null);
    }

    public function testChoiceStaysSetUntilCleared(): void
    {
        $provider = $this->provider()->setTools([new CallbackTool('search', 'Searches the documents.', static fn (): string => '')]);
        $returned = $provider->setToolChoice(ToolChoice::any());
        self::assertSame($provider, $returned);

        $provider->chat(new UserMessage('a'));
        $provider->chat(new UserMessage('b'));
        $provider->setToolChoice(null);
        $provider->chat(new UserMessage('c'));

        self::assertSame(['type' => 'any'], $this->http->requests[0]->body['tool_choice'] ?? null);
        self::assertSame(['type' => 'any'], $this->http->requests[1]->body['tool_choice'] ?? null);
        self::assertArrayNotHasKey('tool_choice', $this->http->requests[2]->body);
        self::assertNull($provider->getToolChoice());
    }

    public function testForcedToolCarriesItsName(): void
    {
        $provider = $this->provider()->setTools([new CallbackTool('search', 'Searches the documents.', static fn (): string => '')]);
        $provider->setToolChoice(ToolChoice::tool('search'));
        $provider->chat(new UserMessage('Hallo'));

        self::assertSame(['type' => 'tool', 'name' => 'search'], $this->http->requests[0]->body['tool_choice'] ?? null);
        self::assertTrue(ToolChoice::tool('search')->equals($provider->getToolChoice()));
    }

    public function testNoToolChoiceWithoutTools(): void
    {
        $provider = $this->provider();
        $provider->setToolChoice(ToolChoice::none());
        $provider->chat(new UserMessage('Hallo'));

        self::assertArrayNotHasKey('tools', $this->http->requests[0]->body);
        self::assertArrayNotHasKey('tool_choice', $this->http->requests[0]->body);
    }

    public function testNoToolChoiceWithoutChoice(): void
    {
        $provider = $this->provider()->setTools([new CallbackTool('search', 'Searches the documents.', static fn (): string => '')]);
        $provider->chat(new UserMessage('Hallo'));

        self::assertArrayHasKey('tools', $this->http->requests[0]->body);
        self::assertArrayNotHasKey('tool_choice', $this->http->requests[0]->body);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function models(): iterable
    {
        yield 'haiku 4.5' => [self::HAIKU, true];
        yield 'sonnet 5.5' => ['claude-sonnet-5-5', false];
        yield 'opus 5.5' => ['claude-opus-5-5', false];
        yield 'fable 5.1' => ['claude-fable-5-1', false];
        yield 'sonnet 4.5' => ['claude-sonnet-4-5', true];
    }

    #[DataProvider('models')]
    public function testSupportMatrix(string $model, bool $forcing): void
    {
        $provider = $this->provider($model);

        self::assertTrue($provider->supportsToolChoice(ToolChoice::auto()));
        self::assertTrue($provider->supportsToolChoice(ToolChoice::none()));
        self::assertSame($forcing, $provider->supportsToolChoice(ToolChoice::any()));
        self::assertSame($forcing, $provider->supportsToolChoice(ToolChoice::tool('search')));
    }

    public function testThinkingDeclinesForcing(): void
    {
        $provider = new AnthropicWithToolChoice(
            key: 'key',
            model: self::HAIKU,
            parameters: ['thinking' => ['type' => 'enabled', 'budget_tokens' => 1024]],
            httpClient: $this->http,
        );

        self::assertTrue($provider->supportsToolChoice(ToolChoice::auto()));
        self::assertTrue($provider->supportsToolChoice(ToolChoice::none()));
        self::assertFalse($provider->supportsToolChoice(ToolChoice::any()));
        self::assertFalse($provider->supportsToolChoice(ToolChoice::tool('search')));
    }

    public function testCustomPatternList(): void
    {
        $haiku = new AnthropicWithToolChoice(key: 'key', model: self::HAIKU, httpClient: $this->http, unsupportedForcingModels: ['/^claude-haiku-/']);
        $opus = new AnthropicWithToolChoice(key: 'key', model: 'claude-opus-5-5', httpClient: $this->http, unsupportedForcingModels: []);

        self::assertFalse($haiku->supportsToolChoice(ToolChoice::any()));
        self::assertTrue($haiku->supportsToolChoice(ToolChoice::none()));
        self::assertTrue($opus->supportsToolChoice(ToolChoice::any()));
    }

    public function testCallOfAnUnknownToolDoesNotThrow(): void
    {
        $provider = $this->provider()->setTools([new CallbackTool('search', 'Searches the documents.', static fn (): string => '')]);

        $known = $provider->newToolCall('search', 'toolu_1', ['q' => 'x']);
        self::assertSame('Searches the documents.', $known->getDescription());

        $unknown = $provider->newToolCall('json', 'toolu_2', ['kind' => 'answer']);
        self::assertSame(['json', 'toolu_2', ['kind' => 'answer']], [$unknown->getName(), $unknown->getCallId(), $unknown->getInputs()]);
    }

    public function testResponseFormatIsSentAsStructuredOutputs(): void
    {
        $provider = new AnthropicWithToolChoice(key: 'key', model: 'claude-sonnet-5-5', parameters: ['output_config' => ['effort' => 'low']], httpClient: $this->http);
        $schema = ['type' => 'object', 'additionalProperties' => false, 'required' => ['a'], 'properties' => ['a' => ['type' => 'string', 'minLength' => 1, 'x-format' => 'textarea']]];

        self::assertTrue($provider->setResponseFormat($schema));
        $provider->chat(new UserMessage('Hallo'));
        self::assertSame([
            'format' => ['type' => 'json_schema', 'schema' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['a'], 'properties' => ['a' => ['type' => 'string']]]],
            'effort' => 'low',
        ], $this->http->requests[0]->body['output_config'] ?? null, 'unsupported and x-* keywords dropped, effort kept');

        self::assertTrue($provider->setResponseFormat(null));
        $provider->chat(new UserMessage('Hallo'));
        self::assertSame(['effort' => 'low'], $this->http->requests[1]->body['output_config'] ?? null);
    }

    public function testResponseFormatDeclinedForAnOpenObjectOrAnOldModel(): void
    {
        $open = ['type' => 'object', 'additionalProperties' => false, 'properties' => ['form' => ['type' => 'object', 'additionalProperties' => ['type' => 'string']]]];
        self::assertFalse($this->provider('claude-sonnet-5-5')->setResponseFormat($open));
        self::assertFalse($this->provider('claude-3-5-haiku-20241022')->setResponseFormat(['type' => 'object', 'additionalProperties' => false, 'properties' => []]));

        $declined = $this->provider('claude-sonnet-5-5');
        $declined->setResponseFormat($open);
        $declined->chat(new UserMessage('Hallo'));
        self::assertArrayNotHasKey('output_config', $this->http->requests[0]->body);
    }

    public function testFactoryReturnsToolChoiceProviderForAnthropicOnly(): void
    {
        $factory = new ProviderFactory(new EmbeddingModelRegistry([
            'nomic-embed-text' => ['provider' => 'ollama', 'dimension' => 768],
        ]), ConfigSecretsFixture::create());

        self::assertInstanceOf(AnthropicWithToolChoice::class, $factory->chatProvider(ProviderConnectionFixtures::anthropic(), self::HAIKU));

        $ollama = $factory->chatProvider(ProviderConnectionFixtures::ollama(), 'llama3.2');
        self::assertInstanceOf(Ollama::class, $ollama);
        self::assertNotInstanceOf(ToolChoiceAwareProviderInterface::class, $ollama);
    }

    private function provider(string $model = self::HAIKU): AnthropicWithToolChoice
    {
        return new AnthropicWithToolChoice(key: 'key', model: $model, httpClient: $this->http);
    }
}

/**
 * Records every request and answers with a minimal Anthropic text reply
 * (JSON for chat, SSE events for stream).
 */
final class CapturingHttpClient implements HttpClientInterface
{
    /** @var list<HttpRequest> */
    public array $requests = [];

    public function request(HttpRequest $request): HttpResponse
    {
        $this->requests[] = $request;

        return new HttpResponse(200, '{"content":[{"type":"text","text":"ok"}],"stop_reason":"end_turn","usage":{"input_tokens":1,"output_tokens":1}}');
    }

    public function stream(HttpRequest $request): StreamInterface
    {
        $this->requests[] = $request;

        return new LineStream([
            'data: {"type":"message_start","message":{"id":"msg_1","usage":{"input_tokens":1,"output_tokens":0}}}',
            'data: {"type":"content_block_start","index":0,"content_block":{"type":"text","text":""}}',
            'data: {"type":"content_block_delta","index":0,"delta":{"type":"text_delta","text":"ok"}}',
            // neuron-ai 4.x treats a stream without the stop reason as ended early (neuron 4.x guide 40).
            'data: {"type":"message_delta","delta":{"stop_reason":"end_turn"},"usage":{"output_tokens":1}}',
            'data: {"type":"message_stop"}',
        ]);
    }
}

/**
 * A stream that returns one prepared line per readLine() call.
 */
final class LineStream implements StreamInterface
{
    /**
     * @param list<string> $lines
     */
    public function __construct(private array $lines)
    {
    }

    public function eof(): bool
    {
        return [] === $this->lines;
    }

    public function read(int $length): string
    {
        return $this->readLine();
    }

    public function readLine(): string
    {
        return (array_shift($this->lines) ?? '')."\n";
    }

    public function close(): void
    {
    }
}

// - revised 2026-10-14
