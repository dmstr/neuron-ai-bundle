<?php

// file generated with AI assistance: Claude Code - 2026-10-06 18:00:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\ProviderConnection\Catalogue;

use Dmstr\NeuronAiBundle\Entity\Refs\ProviderModel;
use Dmstr\NeuronAiBundle\Filter\CapabilityFilter;
use Dmstr\NeuronAiBundle\Provider\EndpointFingerprint;
use Dmstr\NeuronAiBundle\Provider\Model\ModelCapability;
use Dmstr\NeuronAiBundle\Testing\ProviderConnectionFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Kernel-free checks of the catalogue's helpers: the endpoint fingerprint
 * (normalisation, `stale`), the capability filter's value parsing, the
 * entity's capability normalisation, its IRI and the collection of the
 * model picker (`x-collection`).
 */
final class CatalogueHelpersTest extends TestCase
{
    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function urls(): iterable
    {
        yield 'trailing slash and case' => [ProviderConnectionFixtures::ollamaConfig(' HTTP://Ollama.Fake.Test:11434/api/ '), 'http://ollama.fake.test:11434/api'];
        yield 'path case kept' => [ProviderConnectionFixtures::ollamaConfig('https://gw.example.org/Ollama/api'), 'https://gw.example.org/Ollama/api'];
        yield 'anthropic default' => [['type' => 'anthropic', 'api_key' => 'x'], 'https://api.anthropic.com/v1'];
        yield 'anthropic base_url' => [['type' => 'anthropic', 'base_url' => 'https://gateway.example.org/v1/'], 'https://gateway.example.org/v1'];
    }

    /**
     * @param array<string, mixed> $config
     */
    #[DataProvider('urls')]
    public function testNormalizedUrl(array $config, string $expected): void
    {
        self::assertSame($expected, EndpointFingerprint::normalizedUrl($config));
    }

    public function testRowTurnsStaleWhenTheConnectionUrlChanges(): void
    {
        $remote = ProviderConnectionFixtures::ollama('remote');
        $model = new ProviderModel($remote, 'llama3.1:8b');
        self::assertFalse($model->isStale());

        $remote->setConfigJson(ProviderConnectionFixtures::ollamaConfig(ProviderConnectionFixtures::OLLAMA_URL.'/'));
        self::assertFalse($model->isStale());

        $remote->setConfigJson(ProviderConnectionFixtures::ollamaConfig('https://other.example.org/api'));
        self::assertTrue($model->isStale());
        self::assertSame(64, \strlen(EndpointFingerprint::of($remote)));
    }

    public function testCapabilitiesAreNormalized(): void
    {
        $model = (new ProviderModel(ProviderConnectionFixtures::ollama('remote'), 'x'))->setCapabilities(['tools', 'unknown', 'completion', 'tools']);

        self::assertSame(['completion', 'tools'], $model->getCapabilities());
        self::assertTrue($model->hasCapability('tools'));
        self::assertFalse($model->setCapabilities(null)->hasCapability('tools'));
        self::assertSame([], $model->setCapabilities([])->getCapabilities());
    }

    public function testFilterValuesAreSortedAndUnique(): void
    {
        self::assertSame(['tools'], CapabilityFilter::capabilities('tools'));
        self::assertSame(['tools', 'vision'], CapabilityFilter::capabilities(['vision', 'tools', 'vision']));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidFilterValues(): iterable
    {
        yield 'unknown' => ['telepathy'];
        yield 'unknown in list' => [['tools', 'telepathy']];
        yield 'nested' => [[['tools']]];
    }

    #[DataProvider('invalidFilterValues')]
    public function testUnknownFilterValueIsABadRequest(mixed $value): void
    {
        $this->expectException(BadRequestHttpException::class);

        CapabilityFilter::capabilities($value);
    }

    public function testFilterDescribesBothParameterForms(): void
    {
        $description = (new CapabilityFilter())->getDescription(ProviderModel::class);

        self::assertSame(['capability', 'capability[]'], array_keys($description));
        self::assertTrue($description['capability[]']['is_collection']);
        self::assertSame(ProviderModel::CAPABILITIES, $description['capability']['schema']['enum']);
    }

    public function testModelCollectionUrlOffersAvailableModelsOfActiveConnectionsWithTheCapability(): void
    {
        self::assertSame('/api/refs/provider_models?available=true&apiConfiguration.active=true&capability[]=completion', ProviderModel::modelCollectionUrl(ModelCapability::COMPLETION));
        self::assertSame('/api/refs/provider_models?available=true&apiConfiguration.active=true&capability[]=embedding', ProviderModel::modelCollectionUrl(ModelCapability::EMBEDDING));
    }

    public function testModelCollectionUrlRejectsAnUnknownCapability(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown capability "telepathy"');

        ProviderModel::modelCollectionUrl('telepathy');
    }

    public function testIriIsTheCollectionPathAndTheUuid(): void
    {
        $model = new ProviderModel(ProviderConnectionFixtures::ollama('remote'), 'x');

        self::assertSame('/api/refs/provider_models/'.$model->getId()->toRfc4122(), $model->iri());
    }
}
