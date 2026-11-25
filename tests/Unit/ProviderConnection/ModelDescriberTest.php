<?php

// file generated with AI assistance: Claude Code - 2026-10-06 17:40:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\ProviderConnection;

use Dmstr\ApiConfiguration\Entity\ApiConfiguration;
use Dmstr\NeuronAiBundle\Provider\AnthropicModelDescriber;
use Dmstr\NeuronAiBundle\Provider\Auth\OllamaHttpClientFactory;
use Dmstr\NeuronAiBundle\Provider\Model\ModelDescription;
use Dmstr\NeuronAiBundle\Provider\ModelListingException;
use Dmstr\NeuronAiBundle\Provider\OllamaModelDescriber;
use Dmstr\NeuronAiBundle\Provider\ProviderModelDescriber;
use Dmstr\NeuronAiBundle\Testing\ConfigSecretsFixture;
use Dmstr\NeuronAiBundle\Testing\FakeModelDescriber;
use Dmstr\NeuronAiBundle\Testing\ProviderConnectionFixtures;
use Dmstr\NeuronAiBundle\Testing\FakeHttpClient;
use NeuronAI\HttpClient\HttpResponse;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * Kernel-free checks of the model describers against a FakeHttpClient:
 * Ollama `/tags` plus `/show` (incremental skip, budget, circuit
 * breaker, authentication), the Anthropic Models API mapping, the dispatch
 * by type and the test fake. For authenticated `ollama` connections the
 * token endpoint is a second FakeHttpClient.
 */
final class ModelDescriberTest extends TestCase
{
    private const string KEY = 'sk-ant-secret-placeholder-4711';

    /** @var list<array<string, mixed>> records of FakeHttpClient */
    private array $history = [];

    /** @var list<array<string, mixed>> records of FakeHttpClient */
    private array $tokenCalls = [];

    public function testOllamaCombinesTagsAndShow(): void
    {
        $describer = new OllamaModelDescriber(ConfigSecretsFixture::create(), $this->client([
            self::tags(self::tag('llama3.1:8b', 'sha-llama')),
            self::show(['completion', 'tools', 'unknown-capability'], 'llama', 131072, ['general.parameter_count' => 8030261248, 'llama.embedding_length' => 4096]),
        ]));

        $list = $describer->describeModels(ProviderConnectionFixtures::ollama('local', url: 'http://ollama:11434/api/'));

        self::assertSame(['llama3.1:8b'], $list->names());
        self::assertSame([], $list->warnings);
        $model = $list->models[0];
        self::assertTrue($model->described);
        self::assertSame(['completion', 'tools'], $model->capabilities);
        self::assertSame(131072, $model->maxContextLength);
        self::assertNull($model->maxOutputTokens);
        self::assertTrue($model->supportsSampling);
        self::assertSame('llama', $model->family);
        self::assertSame('8.0B', $model->parameterSize);
        self::assertSame('Q4_K_M', $model->quantization);
        self::assertSame('sha-llama', $model->digest);
        self::assertSame(8030261248, $model->providerData['parameter_count']);
        self::assertSame(4096, $model->providerData['embedding_length']);
        self::assertSame('gguf', $model->providerData['format']);
        self::assertNull($model->error);

        self::assertCount(2, $this->history);
        self::assertSame('http://ollama:11434/api/tags', $this->history[0]['url']);
        $show = $this->history[1];
        self::assertSame('POST', $show['method']);
        self::assertSame('http://ollama:11434/api/show', $show['url']);
        self::assertSame(['model' => 'llama3.1:8b'], json_decode((string) $show['body'], true), 'no verbose flag');
    }

    public function testShowParametersAndModelInfoLandInProviderData(): void
    {
        $describer = new OllamaModelDescriber(ConfigSecretsFixture::create(), $this->client([
            self::tags(self::tag('qwen3:14b', 'sha-qwen')),
            new HttpResponse(200, json_encode([
                'parameters' => "num_ctx                        8192\nrepeat_penalty                 1\nstop                           \"<|im_start|>\"\nstop                           \"<|im_end|>\"\ntemperature                    0.6",
                'template' => '{{ .Prompt }}',
                'license' => 'Apache License 2.0',
                'system' => 'You are Qwen.',
                'projector_info' => ['clip.has_vision_encoder' => true, 'clip.vision.image_mean' => [0.5, 0.5, 0.5]],
                'capabilities' => ['completion', 'tools'],
                'details' => ['family' => 'qwen3'],
                'model_info' => ['general.architecture' => 'qwen3', 'qwen3.context_length' => 40960, 'qwen3.block_count' => 40, 'tokenizer.ggml.tokens' => null],
            ], \JSON_THROW_ON_ERROR)),
        ]));

        $model = $describer->describeModels(ProviderConnectionFixtures::ollama())->models[0];

        self::assertSame(['num_ctx' => 8192, 'repeat_penalty' => 1, 'stop' => ['<|im_start|>', '<|im_end|>'], 'temperature' => 0.6], $model->providerData['parameters']);
        self::assertSame(['general.architecture' => 'qwen3', 'qwen3.context_length' => 40960, 'qwen3.block_count' => 40], $model->providerData['model_info'], 'scalar keys only');
        self::assertArrayNotHasKey('template', $model->providerData);
        self::assertArrayNotHasKey('license', $model->providerData);
        self::assertSame('You are Qwen.', $model->providerData['system']);
        self::assertSame(['clip.has_vision_encoder' => true], $model->providerData['projector_info']);
        self::assertSame(40960, $model->maxContextLength, 'the trained maximum, not the Modelfile num_ctx');
    }

    public function testParseParameters(): void
    {
        self::assertSame([], OllamaModelDescriber::parseParameters(null));
        self::assertSame([], OllamaModelDescriber::parseParameters(''));
        self::assertSame(['stop' => ['</s>']], OllamaModelDescriber::parseParameters('stop "</s>"'));
        self::assertSame(['seed' => -1, 'top_p' => 0.95, 'penalize_newline' => false, 'mirostat' => 'x y', 'quoted' => 'a "b"'], OllamaModelDescriber::parseParameters("seed -1\r\ntop_p 0.95\npenalize_newline false\n\nmirostat x y\nquoted \"a \\\"b\\\"\""));
        self::assertSame(['num_gpu' => [1, 2]], OllamaModelDescriber::parseParameters("num_gpu 1\nnum_gpu 2"), 'a repeated key becomes a list');
    }

    public function testTagsWithCapabilitiesStillNeedShowForTheParameters(): void
    {
        $describer = new OllamaModelDescriber(ConfigSecretsFixture::create(), $this->client([
            self::tags(self::tag('qwen3:14b', 'sha-qwen', ['capabilities' => ['tools', 'completion', 'thinking'], 'details' => ['family' => 'qwen3', 'context_length' => 40960]])),
            self::show(['completion', 'thinking', 'tools'], 'qwen3', 40960),
        ]));

        $model = $describer->describeModels(ProviderConnectionFixtures::ollama())->models[0];

        self::assertTrue($model->described);
        self::assertSame([], $model->providerData['parameters']);
        self::assertCount(2, $this->history);
    }

    public function testTagsWithCapabilitiesAreTheFallbackWhenShowIsBlocked(): void
    {
        $describer = new OllamaModelDescriber(ConfigSecretsFixture::create(), $this->client([
            self::tags(self::tag('qwen3:14b', 'sha-qwen', ['capabilities' => ['tools', 'completion'], 'details' => ['family' => 'qwen3', 'context_length' => 40960]]), self::tag('b:latest', 'sha-b')),
            new HttpResponse(404, '404 page not found'),
        ]));

        $list = $describer->describeModels(ProviderConnectionFixtures::ollama());

        [$qwen, $b] = $list->models;
        self::assertTrue($qwen->described);
        self::assertSame(['completion', 'tools'], $qwen->capabilities);
        self::assertSame(40960, $qwen->maxContextLength);
        self::assertArrayNotHasKey('parameters', $qwen->providerData, 'unknown, so the next scan asks /show again');
        self::assertFalse($b->described);
        self::assertSame(['/show not permitted by the endpoint (HTTP 404); capabilities not refreshed'], $list->warnings);
    }

    public function testUnchangedDigestWithoutKnownParametersIsDescribedAgain(): void
    {
        $describer = new OllamaModelDescriber(ConfigSecretsFixture::create(), $this->client([
            self::tags(self::tag('llama3.1:8b', 'sha-llama')),
            self::show(['completion', 'tools'], 'llama', 32768),
        ]));

        $model = $describer->describeModels(ProviderConnectionFixtures::ollama(), ['llama3.1:8b' => ['digest' => 'sha-llama', 'capabilitiesKnown' => true, 'parametersKnown' => false]])->models[0];

        self::assertTrue($model->described);
        self::assertCount(2, $this->history);
    }

    public function testUnchangedDigestWithKnownCapabilitiesIsNotDescribedAgain(): void
    {
        $describer = new OllamaModelDescriber(ConfigSecretsFixture::create(), $this->client([
            self::tags(self::tag('llama3.1:8b', 'sha-llama'), self::tag('mistral:latest', 'sha-mistral-new')),
            self::show(['completion', 'tools'], 'llama', 32768),
        ]));

        $list = $describer->describeModels(ProviderConnectionFixtures::ollama(), [
            'llama3.1:8b' => ['digest' => 'sha-llama', 'capabilitiesKnown' => true],
            'mistral:latest' => ['digest' => 'sha-mistral-old', 'capabilitiesKnown' => true],
        ]);

        [$llama, $mistral] = $list->models;
        self::assertFalse($llama->described);
        self::assertTrue($llama->unchanged, 'the digest skip is marked unchanged');
        self::assertNull($llama->capabilities);
        self::assertNull($llama->error);
        self::assertSame('sha-llama', $llama->digest);
        self::assertTrue($mistral->described, 'a changed digest is described again');
        self::assertFalse($mistral->unchanged);
        self::assertCount(2, $this->history, 'one /show, for the changed model only');
        self::assertSame(['model' => 'mistral:latest'], json_decode((string) $this->history[1]['body'], true));
    }

    public function testKnownRowWithoutCapabilitiesIsDescribedAgain(): void
    {
        $describer = new OllamaModelDescriber(ConfigSecretsFixture::create(), $this->client([
            self::tags(self::tag('llama3.1:8b', 'sha-llama')),
            self::show(['completion', 'tools'], 'llama', 32768),
        ]));

        $model = $describer->describeModels(ProviderConnectionFixtures::ollama(), ['llama3.1:8b' => ['digest' => 'sha-llama', 'capabilitiesKnown' => false]])->models[0];

        self::assertTrue($model->described);
        self::assertSame(['completion', 'tools'], $model->capabilities);
    }

    public function testShowWithoutCapabilitiesGivesNull(): void
    {
        $describer = new OllamaModelDescriber(ConfigSecretsFixture::create(), $this->client([
            self::tags(self::tag('old-model:latest', 'sha-old')),
            new HttpResponse(200, json_encode(['details' => ['family' => 'llama'], 'model_info' => ['general.architecture' => 'llama', 'llama.context_length' => 0]], \JSON_THROW_ON_ERROR)),
        ]));

        $model = $describer->describeModels(ProviderConnectionFixtures::ollama())->models[0];

        self::assertTrue($model->described);
        self::assertNull($model->capabilities, 'unknown, not "known to have none"');
        self::assertNull($model->maxContextLength, '0 is no context length');
        self::assertFalse($model->hasCapability('tools'));
    }

    public function testServerErrorOnShowMarksOnlyThatModel(): void
    {
        $describer = new OllamaModelDescriber(ConfigSecretsFixture::create(), $this->client([
            self::tags(self::tag('a:latest', 'sha-a'), self::tag('b:latest', 'sha-b')),
            new HttpResponse(500, '{"error": "model runner crashed"}'),
            self::show(['embedding'], 'bert', 2048),
        ]));

        $list = $describer->describeModels(ProviderConnectionFixtures::ollama());

        [$a, $b] = $list->models;
        self::assertFalse($a->described);
        self::assertStringContainsString('HTTP 500 from POST', (string) $a->error);
        self::assertStringContainsString('model runner crashed', (string) $a->error);
        self::assertSame('sha-a', $a->digest, 'the /tags fields stay fresh');
        self::assertTrue($b->described);
        self::assertSame(['embedding'], $b->capabilities);
        self::assertSame([], $list->warnings);
    }

    public function testGatewayRejectingShowStopsTheLoopAfterOneTokenRefresh(): void
    {
        $describer = $this->authDescriber([
            self::tags(self::tag('a:latest', 'sha-a'), self::tag('b:latest', 'sha-b'), self::tag('c:latest', 'sha-c')),
            new HttpResponse(403, '{"error": "route not allowed"}'),
            new HttpResponse(403, '{"error": "route not allowed"}'),
        ], [self::tokenResponse('cc-access-token-1'), self::tokenResponse('cc-access-token-2')]);

        $list = $describer->describeModels(ProviderConnectionFixtures::ollamaClientCredentials('sso'));

        self::assertSame(['a:latest', 'b:latest', 'c:latest'], $list->names());
        foreach ($list->models as $model) {
            self::assertFalse($model->described);
            self::assertNull($model->error);
        }
        self::assertSame(['/show not permitted by the endpoint (HTTP 403); capabilities not refreshed'], $list->warnings);
        self::assertCount(2, $this->tokenCalls, 'the first token and one refresh after the rejection');
        self::assertCount(3, $this->history, '/tags, /show and its one retry');
    }

    public function testNotFoundForAListedModelStopsTheLoop(): void
    {
        $describer = new OllamaModelDescriber(ConfigSecretsFixture::create(), $this->client([
            self::tags(self::tag('a:latest', 'sha-a'), self::tag('b:latest', 'sha-b')),
            new HttpResponse(404, '404 page not found'),
        ]));

        $list = $describer->describeModels(ProviderConnectionFixtures::ollama());

        self::assertSame(['/show not permitted by the endpoint (HTTP 404); capabilities not refreshed'], $list->warnings);
        self::assertFalse($list->models[1]->described);
        self::assertCount(2, $this->history);
    }

    public function testConnectFailureOnShowStopsTheLoop(): void
    {
        $describer = new OllamaModelDescriber(ConfigSecretsFixture::create(), $this->client([
            self::tags(self::tag('a:latest', 'sha-a'), self::tag('b:latest', 'sha-b')),
            FakeHttpClient::networkError('cURL error 7: Failed to connect'),
        ]));

        $list = $describer->describeModels(ProviderConnectionFixtures::ollama());

        self::assertSame(['/show aborted: endpoint unreachable'], $list->warnings);
        self::assertCount(2, $this->history);
    }

    public function testBudgetServesNewModelsFirstAndKeepsTheRest(): void
    {
        $describer = new OllamaModelDescriber(ConfigSecretsFixture::create(), $this->client([
            self::tags(self::tag('changed:latest', 'sha-changed-new'), self::tag('undescribed:latest', 'sha-u'), self::tag('new:latest', 'sha-new')),
            self::show(['completion'], 'llama', 4096),
        ]), maxDescribeCalls: 1);

        $list = $describer->describeModels(ProviderConnectionFixtures::ollama(), [
            'changed:latest' => ['digest' => 'sha-changed-old', 'capabilitiesKnown' => true],
            'undescribed:latest' => ['digest' => 'sha-u', 'capabilitiesKnown' => false],
        ]);

        [$changed, $undescribed, $new] = $list->models;
        self::assertTrue($new->described, 'new models get the budget first');
        self::assertFalse($changed->described);
        self::assertFalse($undescribed->described);
        self::assertSame(['model' => 'new:latest'], json_decode((string) $this->history[1]['body'], true));
        self::assertSame(['2 models not described: describe budget of 1 reached'], $list->warnings);
    }

    public function testDuplicateNamesBecomeAWarning(): void
    {
        $describer = new OllamaModelDescriber(ConfigSecretsFixture::create(), $this->client([
            self::tags(self::tag('a:latest', 'sha-a', ['capabilities' => ['completion'], 'details' => ['context_length' => 2048]]), self::tag('a:latest', 'sha-a2')),
            self::show(['completion'], 'llama', 2048),
        ]));

        $list = $describer->describeModels(ProviderConnectionFixtures::ollama());

        self::assertSame(['a:latest'], $list->names());
        self::assertSame(['Model "a:latest" is listed more than once; the first entry is used.'], $list->warnings);
    }

    public function testDescribeModelMatchesTheDefaultTagAndAlwaysShows(): void
    {
        $describer = new OllamaModelDescriber(ConfigSecretsFixture::create(), $this->client([
            self::tags(self::tag('llama3.2:latest', 'sha-llama', ['capabilities' => ['completion'], 'details' => ['context_length' => 2048]])),
            self::show(['completion', 'tools'], 'llama', 131072),
        ]));

        $model = $describer->describeModel(ProviderConnectionFixtures::ollama(), 'llama3.2');

        self::assertNotNull($model);
        self::assertSame('llama3.2:latest', $model->name);
        self::assertSame(['completion', 'tools'], $model->capabilities, '/show is forced even when /tags has capabilities');
        self::assertSame(['model' => 'llama3.2:latest'], json_decode((string) $this->history[1]['body'], true));
    }

    public function testDescribeModelDistinguishesNotOfferedFromAFailedShow(): void
    {
        $describer = new OllamaModelDescriber(ConfigSecretsFixture::create(), $this->client([
            self::tags(self::tag('a:latest', 'sha-a')),
            self::tags(self::tag('a:latest', 'sha-a')),
            new HttpResponse(404, '{"error": "model not found"}'),
        ]));
        $connection = ProviderConnectionFixtures::ollama();

        self::assertNull($describer->describeModel($connection, 'missing'));
        self::assertCount(1, $this->history, 'no /show for a model /tags does not list');

        $failed = $describer->describeModel($connection, 'a:latest');
        self::assertNotNull($failed);
        self::assertFalse($failed->described);
        self::assertStringContainsString('HTTP 404', (string) $failed->error);
    }

    public function testBearerTokenOnTagsAndShow(): void
    {
        $describer = $this->authDescriber([self::tags(self::tag('a:latest', 'sha-a')), self::show(['completion'], 'llama', 2048)], []);

        $describer->describeModels(ProviderConnectionFixtures::ollamaBearer('static'));

        self::assertCount(2, $this->history);
        foreach ($this->history as $call) {
            self::assertSame('Bearer '.ProviderConnectionFixtures::PLACEHOLDER_TOKEN, FakeHttpClient::headerOf($call, 'Authorization'));
        }
    }

    public function testExpiredTokenInTheMiddleIsRenewedOnce(): void
    {
        $describer = $this->authDescriber([
            self::tags(self::tag('a:latest', 'sha-a'), self::tag('b:latest', 'sha-b')),
            self::show(['completion'], 'llama', 2048),
            new HttpResponse(401, '{"error": "token expired"}'),
            self::show(['tools', 'completion'], 'llama', 2048),
        ], [self::tokenResponse('cc-access-token-1'), self::tokenResponse('cc-access-token-2')]);

        $list = $describer->describeModels(ProviderConnectionFixtures::ollamaClientCredentials('sso'));

        self::assertTrue($list->models[0]->described);
        self::assertTrue($list->models[1]->described);
        self::assertSame(['completion', 'tools'], $list->models[1]->capabilities);
        self::assertSame([], $list->warnings);
        self::assertCount(2, $this->tokenCalls);
        self::assertSame('Bearer cc-access-token-2', FakeHttpClient::headerOf($this->history[3], 'Authorization'));
    }

    public function testErrorsDoNotRevealTheToken(): void
    {
        $describer = $this->authDescriber([
            self::tags(self::tag('a:latest', 'sha-a')),
            new HttpResponse(500, '{"error": "upstream said '.ProviderConnectionFixtures::PLACEHOLDER_TOKEN.'"}'),
        ], []);

        $model = $describer->describeModels(ProviderConnectionFixtures::ollamaBearer('static'))->models[0];

        self::assertNotNull($model->error);
        self::assertStringNotContainsString(ProviderConnectionFixtures::PLACEHOLDER_TOKEN, $model->error);
    }

    public function testFailingTagsIsAConnectionFailure(): void
    {
        $describer = new OllamaModelDescriber(ConfigSecretsFixture::create(), $this->client([new HttpResponse(503, 'Service Unavailable')]));

        $this->expectException(ModelListingException::class);
        $describer->describeModels(ProviderConnectionFixtures::ollama());
    }

    public function testAnthropicMapsTheModelsApi(): void
    {
        $secrets = ConfigSecretsFixture::create();
        $connection = ProviderConnectionFixtures::anthropic('claude', apiKey: self::KEY);
        $connection->setConfigJson($secrets->encrypt($connection->getConfigJson()));

        $describer = new AnthropicModelDescriber($secrets, $this->client([
            new HttpResponse(200, json_encode(['data' => [self::anthropicModel('claude-opus-5-5', 'Claude Opus 5.5', vision: true, thinking: true)], 'has_more' => true, 'last_id' => 'claude-opus-5-5'], \JSON_THROW_ON_ERROR)),
            new HttpResponse(200, json_encode(['data' => [self::anthropicModel('claude-haiku-4-5-20251001', 'Claude Haiku 4.5', maxInput: 0, maxTokens: 0)], 'has_more' => false], \JSON_THROW_ON_ERROR)),
        ]));

        $list = $describer->describeModels($connection);

        self::assertSame(['claude-opus-5-5', 'claude-haiku-4-5-20251001'], $list->names());
        [$opus, $haiku] = $list->models;
        self::assertSame('Claude Opus 5.5', $opus->displayName);
        self::assertSame('opus', $opus->family);
        self::assertSame(['completion', 'thinking', 'tools', 'vision'], $opus->capabilities);
        self::assertSame(200000, $opus->maxContextLength);
        self::assertSame(64000, $opus->maxOutputTokens);
        self::assertFalse($opus->supportsSampling);
        self::assertTrue($opus->providerData['capabilities']['thinking']['supported']);
        self::assertSame('2026-01-01T00:00:00Z', $opus->providerData['created_at']);
        self::assertSame(['completion', 'tools'], $haiku->capabilities, 'tools always, never embedding');
        self::assertNull($haiku->maxContextLength, '0 is not reported');
        self::assertNull($haiku->maxOutputTokens);
        self::assertTrue($haiku->supportsSampling);
        self::assertSame(self::KEY, FakeHttpClient::headerOf($this->history[0], 'x-api-key'));
        self::assertStringContainsString('after_id=claude-opus-5-5', $this->history[1]['url']);
    }

    public function testAnthropicDescribeModelUsesTheItemEndpoint(): void
    {
        $describer = new AnthropicModelDescriber(ConfigSecretsFixture::create(), $this->client([
            new HttpResponse(200, json_encode(self::anthropicModel('claude-sonnet-4-5-20250929', 'Claude Sonnet 4.5'), \JSON_THROW_ON_ERROR)),
            new HttpResponse(404, '{"type": "error", "error": {"type": "not_found_error", "message": "model: nope"}}'),
        ]));
        $connection = ProviderConnectionFixtures::anthropic();

        $model = $describer->describeModel($connection, 'claude-sonnet-4-5');
        self::assertNotNull($model);
        self::assertSame('claude-sonnet-4-5-20250929', $model->name, 'aliases resolve to the dated id');
        self::assertSame('https://api.anthropic.com/v1/models/claude-sonnet-4-5', $this->history[0]['url']);
        self::assertNull($describer->describeModel($connection, 'nope'));
    }

    public function testAnthropicWithoutAModelsApiListsTheConfiguredModelsWithoutARequest(): void
    {
        $describer = new AnthropicModelDescriber(ConfigSecretsFixture::create(), $this->client([]));
        $connection = ProviderConnectionFixtures::anthropic(options: ['models' => ['claude-sonnet-5-5', 'claude-haiku-5-5']]);

        $names = array_map(static fn ($m): string => $m->name, $describer->describeModels($connection)->models);
        self::assertSame(['claude-sonnet-5-5', 'claude-haiku-5-5'], $names);
        self::assertSame('claude-haiku-5-5', $describer->describeModel($connection, 'claude-haiku-5-5')?->name);
        self::assertNull($describer->describeModel($connection, 'claude-opus-5-5'));
        self::assertSame([], $this->history);
    }

    public function testDispatchesByType(): void
    {
        $secrets = ConfigSecretsFixture::create();
        $dispatching = new ProviderModelDescriber(
            new OllamaModelDescriber($secrets, $this->client([self::tags(self::tag('a:latest', 'sha-a')), self::show(['completion'], 'llama', 2048)])),
            new AnthropicModelDescriber($secrets, $this->client([new HttpResponse(200, '{"data": [{"id": "claude-haiku-4-5-20251001"}]}')])),
        );
        $mcp = new ApiConfiguration()->setName('docs')->setConfigJson(['mcp' => true, 'type' => 'mcp', 'endpoint_type' => 'rest', 'url' => 'https://mcp.example.org/mcp']);

        self::assertSame(['a:latest'], $dispatching->describeModels(ProviderConnectionFixtures::ollama())->names());
        self::assertSame(['claude-haiku-4-5-20251001'], $dispatching->describeModels(ProviderConnectionFixtures::anthropic())->names());

        try {
            $dispatching->describeModels($mcp);
            self::fail('Expected a ModelListingException.');
        } catch (ModelListingException $e) {
            self::assertSame(ModelListingException::CONFIGURATION, $e->reason);
        }
    }

    public function testFakeOffersNothingByDefaultAndPlaysScripts(): void
    {
        $fake = new FakeModelDescriber();
        $remote = ProviderConnectionFixtures::ollama('remote');

        self::assertSame([], $fake->describeModels($remote)->models);

        $fake->describe('remote', ['name' => 'llama3.2:latest', 'capabilities' => ['tools', 'completion', 'bogus']], new ModelDescription('qwen3:14b', capabilities: ['completion']))
            ->failModel('remote', 'qwen3:14b');
        $list = $fake->describeModels($remote);
        self::assertSame(['completion', 'tools'], $list->models[0]->capabilities);
        self::assertFalse($list->models[1]->described);
        self::assertNotNull($list->models[1]->error);
        self::assertSame('llama3.2:latest', $fake->describeModel($remote, 'llama3.2')?->name);
        self::assertNull($fake->describeModel($remote, 'missing'));

        $fake->unreachable('remote');
        try {
            $fake->describeModels($remote);
            self::fail('Expected a ModelListingException.');
        } catch (ModelListingException $e) {
            self::assertSame(ModelListingException::UNREACHABLE, $e->reason);
            self::assertStringContainsString('"remote"', $e->getMessage());
        }
        self::assertSame(['remote', 'remote', 'remote', 'remote', 'remote'], $fake->getDescribedConnections());

        $fake->reset();
        self::assertSame([], $fake->describeModels($remote)->models);
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private static function tag(string $name, string $digest, array $extra = []): array
    {
        return array_replace_recursive([
            'name' => $name,
            'model' => $name,
            'digest' => $digest,
            'size' => 4920753328,
            'modified_at' => '2026-09-01T10:00:00Z',
            'details' => ['format' => 'gguf', 'family' => 'llama', 'families' => ['llama'], 'parameter_size' => '8.0B', 'quantization_level' => 'Q4_K_M'],
        ], $extra);
    }

    /**
     * @param array<string, mixed> ...$tags
     */
    private static function tags(array ...$tags): HttpResponse
    {
        return new HttpResponse(200, json_encode(['models' => $tags], \JSON_THROW_ON_ERROR));
    }

    /**
     * @param list<string>         $capabilities
     * @param array<string, mixed> $info
     */
    private static function show(array $capabilities, string $arch, int $contextLength, array $info = []): HttpResponse
    {
        return new HttpResponse(200, json_encode([
            'capabilities' => $capabilities,
            'details' => ['family' => $arch],
            'model_info' => ['general.architecture' => $arch, $arch.'.context_length' => $contextLength] + $info,
        ], \JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>
     */
    private static function anthropicModel(string $id, string $displayName, bool $vision = false, bool $thinking = false, int $maxInput = 200000, int $maxTokens = 64000): array
    {
        return [
            'id' => $id,
            'type' => 'model',
            'display_name' => $displayName,
            'line' => str_contains($id, 'opus') ? 'opus' : null,
            'created_at' => '2026-01-01T00:00:00Z',
            'max_input_tokens' => $maxInput,
            'max_tokens' => $maxTokens,
            'capabilities' => ['image_input' => ['supported' => $vision], 'thinking' => ['supported' => $thinking]],
        ];
    }

    /**
     * @param list<HttpResponse|\Throwable|\Closure> $provider
     * @param list<HttpResponse|\Throwable|\Closure> $tokenEndpoint
     */
    private function authDescriber(array $provider, array $tokenEndpoint): OllamaModelDescriber
    {
        return new OllamaModelDescriber(
            ConfigSecretsFixture::create(),
            $this->client($provider),
            authClients: new OllamaHttpClientFactory(new ArrayAdapter(), new FakeHttpClient($tokenEndpoint)->recordInto($this->tokenCalls)),
        );
    }

    private static function tokenResponse(string $token): HttpResponse
    {
        return new HttpResponse(200, json_encode(['access_token' => $token, 'expires_in' => 300], \JSON_THROW_ON_ERROR), ['Content-Type' => 'application/json']);
    }

    /**
     * @param list<HttpResponse|\Throwable|\Closure> $queue
     */
    private function client(array $queue): FakeHttpClient
    {
        return new FakeHttpClient($queue)->recordInto($this->history);
    }
}

// - revised 2026-10-07
// - revised 2026-10-14
