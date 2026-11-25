<?php

// file generated with AI assistance: Claude Code - 2026-10-05 18:50:29 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\ProviderConnection;

use Dmstr\ApiConfiguration\Normalizer\HealthNormalizer;
use Dmstr\NeuronAiBundle\Provider\AnthropicModelLister;
use Dmstr\NeuronAiBundle\Provider\Auth\OllamaHttpClientFactory;
use Dmstr\NeuronAiBundle\Provider\ModelListerInterface;
use Dmstr\NeuronAiBundle\Provider\ModelListingException;
use Dmstr\NeuronAiBundle\Provider\OllamaModelLister;
use Dmstr\NeuronAiBundle\Provider\Connection\AnthropicConnectionType;
use Dmstr\NeuronAiBundle\Provider\Connection\OllamaConnectionType;
use Dmstr\NeuronAiBundle\Testing\ConfigSecretsFixture;
use Dmstr\NeuronAiBundle\Testing\FakeHttpClient;
use Dmstr\NeuronAiBundle\Testing\FakeModelLister;
use Dmstr\NeuronAiBundle\Testing\ProviderConnectionFixtures;
use NeuronAI\HttpClient\HttpResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * Kernel-free checks of the health probes of the provider connection
 * types (HealthProbeInterface of dmstr/api-configuration-bundle 0.5): raw
 * result shape, error codes, and no key in any result.
 */
final class ProviderHealthProbeTest extends TestCase
{
    public function testOkReportsTheModelCount(): void
    {
        $type = new OllamaConnectionType(new FakeModelLister()->offer(['llama3.2:latest', 'nomic-embed-text:latest']));

        $result = $type->probe(ProviderConnectionFixtures::ollamaConfig('http://ollama:11434/api'));

        self::assertSame('ok', $result['status']);
        self::assertTrue($result['authenticated']);
        self::assertSame('Provider reachable, 2 model(s) listed.', $result['message']);
        self::assertSame(['apiName' => 'ollama', 'custom' => ['modelCount' => 2]], $result['metadata']);
        self::assertSame('http://ollama:11434/api', $type->getEndpoint(ProviderConnectionFixtures::ollamaConfig('http://ollama:11434/api')));
    }

    /** Without a `url` the endpoint of the health result is a URN. */
    public function testEndpointWithoutUrlIsTheDmstrUrn(): void
    {
        self::assertSame('urn:dmstr:ollama', new OllamaConnectionType(new FakeModelLister())->getEndpoint([]));
    }

    public function testFakeDefaultReportsZeroModels(): void
    {
        $result = new AnthropicConnectionType(new FakeModelLister())->probe(ProviderConnectionFixtures::anthropicConfig());

        self::assertSame('ok', $result['status']);
        self::assertSame(0, $result['metadata']['custom']['modelCount']);
    }

    public function testUnreachableAndUnauthenticated(): void
    {
        $unreachable = new OllamaConnectionType(new FakeModelLister()->unreachable())->probe(ProviderConnectionFixtures::ollamaConfig());
        self::assertSame('error', $unreachable['status']);
        self::assertFalse($unreachable['authenticated']);
        self::assertSame('UNREACHABLE', $unreachable['error']['code']);
        self::assertArrayNotHasKey('details', $unreachable['error'], 'an empty list would fail health.json');

        $unauthenticated = new AnthropicConnectionType(new FakeModelLister()->unauthenticated())->probe(ProviderConnectionFixtures::anthropicConfig());
        self::assertSame('UNAUTHENTICATED', $unauthenticated['error']['code']);
        self::assertSame(['statusCode' => 401], $unauthenticated['error']['details']);
    }

    /**
     * @return iterable<string, array{\Closure(FakeModelLister): FakeModelLister, string, string}>
     */
    public static function failureScripts(): iterable
    {
        // The scripts of ProviderConnectionResourceTest::testHealthUsesTheModelLister.
        yield 'unreachable, anthropic' => [static fn (FakeModelLister $l): FakeModelLister => $l->unreachable(), 'anthropic', 'UNREACHABLE'];
        yield 'unreachable, ollama' => [static fn (FakeModelLister $l): FakeModelLister => $l->unreachable(), 'ollama', 'UNREACHABLE'];
        yield 'unauthenticated' => [static fn (FakeModelLister $l): FakeModelLister => $l->unauthenticated(), 'anthropic', 'UNAUTHENTICATED'];
    }

    /**
     * The result must pass the vendor HealthNormalizer (health.json), as in
     * ApiConfigurationHealthChecker; otherwise the route reports
     * HEALTH_CHECK_ERROR instead of the probe's code.
     *
     * @param \Closure(FakeModelLister): FakeModelLister $script
     */
    #[DataProvider('failureScripts')]
    public function testFakeFailuresKeepTheirCodeThroughTheNormalizer(\Closure $script, string $type, string $code): void
    {
        $lister = $script(new FakeModelLister());
        [$probe, $config] = 'ollama' === $type
            ? [new OllamaConnectionType($lister), ProviderConnectionFixtures::ollamaConfig()]
            : [new AnthropicConnectionType($lister), ProviderConnectionFixtures::anthropicConfig()];

        $normalized = new HealthNormalizer()->normalize($probe->probe($config), $probe->getEndpoint($config), 1.0);

        self::assertSame('error', $normalized['status']);
        self::assertSame($code, $normalized['error']['code']);
        self::assertStringNotContainsString(ProviderConnectionFixtures::PLACEHOLDER_API_KEY, json_encode($normalized, \JSON_THROW_ON_ERROR));
    }

    /**
     * @return iterable<string, array{string, ?int}>
     */
    public static function listingReasons(): iterable
    {
        foreach ([ModelListingException::UNREACHABLE, ModelListingException::UNAUTHENTICATED, ModelListingException::PROTOCOL, ModelListingException::CONFIGURATION] as $reason) {
            yield $reason.' without status' => [$reason, null];
            yield $reason.' with status' => [$reason, 502];
        }
    }

    #[DataProvider('listingReasons')]
    public function testEveryListingReasonMapsToItsCode(string $reason, ?int $status): void
    {
        $lister = new class($reason, $status) implements ModelListerInterface {
            public function __construct(private readonly string $reason, private readonly ?int $status)
            {
            }

            public function listModels(\Dmstr\ApiConfiguration\Entity\ApiConfiguration $connection): array
            {
                throw new ModelListingException('listing failed', $this->reason, $this->status);
            }
        };
        $probe = new OllamaConnectionType($lister);
        $config = ProviderConnectionFixtures::ollamaConfig();

        $normalized = new HealthNormalizer()->normalize($probe->probe($config), $probe->getEndpoint($config), 1.0);

        self::assertSame($reason, $normalized['error']['code']);
        self::assertSame(null !== $status ? ['statusCode' => $status] : null, $normalized['error']['details'] ?? null);
    }

    public function testForeignListerExceptionIsAResultWithoutItsMessage(): void
    {
        $lister = new class implements ModelListerInterface {
            public function listModels(\Dmstr\ApiConfiguration\Entity\ApiConfiguration $connection): array
            {
                throw new \RuntimeException('x-api-key: '.ProviderConnectionFixtures::PLACEHOLDER_API_KEY);
            }
        };
        $probe = new AnthropicConnectionType($lister);
        $config = ProviderConnectionFixtures::anthropicConfig();

        $normalized = new HealthNormalizer()->normalize($probe->probe($config), $probe->getEndpoint($config), 1.0);

        self::assertSame('UNREACHABLE', $normalized['error']['code']);
        self::assertStringNotContainsString(ProviderConnectionFixtures::PLACEHOLDER_API_KEY, json_encode($normalized, \JSON_THROW_ON_ERROR));
    }

    public function testRealListerResultNeverContainsTheKey(): void
    {
        $key = 'sk-ant-health-placeholder-0815';
        $lister = new AnthropicModelLister(ConfigSecretsFixture::create(), new FakeHttpClient([
            new HttpResponse(401, json_encode(['error' => ['message' => 'invalid x-api-key: '.$key]], \JSON_THROW_ON_ERROR)),
        ]));
        $type = new AnthropicConnectionType($lister);
        $config = ProviderConnectionFixtures::anthropicConfig($key);

        $result = $type->probe($config);

        self::assertSame('UNAUTHENTICATED', $result['error']['code']);
        self::assertStringNotContainsString($key, json_encode($result, \JSON_THROW_ON_ERROR));
        self::assertSame('https://api.anthropic.com/v1/', $type->getEndpoint($config));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, list<HttpResponse|\Closure>, list<HttpResponse|\Closure>, string, ?int}>
     */
    public static function authenticatedOllamaProbes(): iterable
    {
        $token = static fn (string $token): HttpResponse => new HttpResponse(200, json_encode(['access_token' => $token, 'expires_in' => 300], \JSON_THROW_ON_ERROR));
        $forbidden = new HttpResponse(403, '{"error": "forbidden"}');

        yield 'bearer rejected' => [ProviderConnectionFixtures::ollamaBearerConfig(), [$forbidden], [], 'UNAUTHENTICATED', 403];
        yield 'client credentials rejected twice' => [ProviderConnectionFixtures::ollamaClientCredentialsConfig(), [$forbidden, $forbidden], [$token('cc-1'), $token('cc-2')], 'UNAUTHENTICATED', 403];
        yield 'token endpoint refuses' => [ProviderConnectionFixtures::ollamaClientCredentialsConfig(), [], [new HttpResponse(401, '{"error": "unauthorized_client"}')], 'UNAUTHENTICATED', 401];
        yield 'provider unreachable' => [ProviderConnectionFixtures::ollamaBearerConfig(), [FakeHttpClient::networkError('cURL error 7: Failed to connect')], [], 'UNREACHABLE', null];
        yield 'provider 503' => [ProviderConnectionFixtures::ollamaClientCredentialsConfig(), [new HttpResponse(503, 'down')], [$token('cc-1')], 'UNREACHABLE', 503];
    }

    /**
     * The real Ollama lister behind the probe: a token failure and a
     * rejected token alike are UNAUTHENTICATED with the status, distinct
     * from an unreachable provider; no secret in the result.
     *
     * @param array<string, mixed>       $config
     * @param list<HttpResponse|\Closure> $provider
     * @param list<HttpResponse|\Closure> $tokenEndpoint
     */
    #[DataProvider('authenticatedOllamaProbes')]
    public function testAuthenticatedOllamaProbe(array $config, array $provider, array $tokenEndpoint, string $code, ?int $status): void
    {
        $lister = new OllamaModelLister(
            ConfigSecretsFixture::create(),
            new FakeHttpClient($provider),
            authClients: new OllamaHttpClientFactory(new ArrayAdapter(), new FakeHttpClient($tokenEndpoint)),
        );
        $probe = new OllamaConnectionType($lister);

        $normalized = new HealthNormalizer()->normalize($probe->probe($config), $probe->getEndpoint($config), 1.0);

        self::assertSame('error', $normalized['status']);
        self::assertSame($code, $normalized['error']['code']);
        self::assertSame(null !== $status ? ['statusCode' => $status] : null, $normalized['error']['details'] ?? null);
        $json = json_encode($normalized, \JSON_THROW_ON_ERROR);
        foreach ([ProviderConnectionFixtures::PLACEHOLDER_TOKEN, ProviderConnectionFixtures::PLACEHOLDER_CLIENT_SECRET, 'cc-1', 'cc-2'] as $secret) {
            self::assertStringNotContainsString($secret, $json);
        }
    }

    public function testEndpointDropsUserInfo(): void
    {
        $type = new OllamaConnectionType(new FakeModelLister());

        self::assertSame('https://ollama.example.org/api', $type->getEndpoint(ProviderConnectionFixtures::ollamaConfig('https://user:pw@ollama.example.org/api')));
    }
}

// - revised 2026-10-14
// - revised 2026-11-25
