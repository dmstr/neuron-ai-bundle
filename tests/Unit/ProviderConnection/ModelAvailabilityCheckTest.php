<?php

// file generated with AI assistance: Claude Code - 2026-10-05 18:50:29 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\ProviderConnection;

use Dmstr\NeuronAiBundle\Provider\ModelAvailabilityCheck;
use Dmstr\NeuronAiBundle\Provider\ModelListingException;
use Dmstr\NeuronAiBundle\Provider\ModelUnavailableException;
use Dmstr\NeuronAiBundle\Testing\FakeModelLister;
use Dmstr\NeuronAiBundle\Testing\ProviderConnectionFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Kernel-free checks of the single model check and of the scriptable
 * FakeModelLister behind it in tests.
 */
final class ModelAvailabilityCheckTest extends TestCase
{
    public function testEveryModelIsAvailableByDefault(): void
    {
        $lister = new FakeModelLister();

        new ModelAvailabilityCheck($lister)->assertAvailable(ProviderConnectionFixtures::ollama('local'), 'any-model:7b');

        self::assertSame(['local'], $lister->getListedConnections());
    }

    /**
     * @return iterable<string, array{list<string>, string, bool}>
     */
    public static function names(): iterable
    {
        yield 'exact' => [['llama3.2:latest'], 'llama3.2:latest', true];
        yield 'default tag added' => [['llama3.2:latest'], 'llama3.2', true];
        yield 'default tag dropped' => [['llama3.2'], 'llama3.2:latest', true];
        yield 'other tag' => [['llama3.2:1b'], 'llama3.2', false];
        yield 'prefix only' => [['llama3.2:latest'], 'llama3', false];
        yield 'anthropic id' => [['claude-haiku-4-5-20251001'], 'claude-haiku-4-5-20251001', true];
        yield 'empty model' => [['llama3.2:latest'], '', false];
    }

    /**
     * @param list<string> $offered
     */
    #[DataProvider('names')]
    public function testNameMatching(array $offered, string $model, bool $expected): void
    {
        self::assertSame($expected, ModelAvailabilityCheck::offers($offered, $model));
    }

    public function testModelNotOffered(): void
    {
        $lister = new FakeModelLister()->offer(['llama3.2:latest', 'qwen3:8b'], 'remote');

        $e = $this->unavailable(new ModelAvailabilityCheck($lister), ProviderConnectionFixtures::ollama('remote'), 'mistral');

        self::assertTrue($e->isNotOffered());
        self::assertSame(ModelUnavailableException::NOT_OFFERED, $e->reason);
        self::assertSame('remote', $e->connectionName);
        self::assertSame('mistral', $e->model);
        self::assertSame('Model "mistral" is not offered by connection "remote" (offered: llama3.2:latest, qwen3:8b).', $e->getMessage());
    }

    public function testConnectionScriptWinsOverTheGlobalOne(): void
    {
        $lister = new FakeModelLister()->offer([])->offer(['llama3.2:latest'], 'remote');
        $check = new ModelAvailabilityCheck($lister);

        $check->assertAvailable(ProviderConnectionFixtures::ollama('remote'), 'llama3.2');
        $e = $this->unavailable($check, ProviderConnectionFixtures::ollama('local'), 'llama3.2');
        self::assertSame('Model "llama3.2" is not offered by connection "local" (it offers no models).', $e->getMessage());
    }

    public function testUnreachableProvider(): void
    {
        $lister = new FakeModelLister()->unreachable('remote', 'connection refused');

        $e = $this->unavailable(new ModelAvailabilityCheck($lister), ProviderConnectionFixtures::ollama('remote'), 'llama3.2');

        self::assertTrue($e->isUnreachable());
        self::assertSame(ModelListingException::UNREACHABLE, $e->listingFailure?->reason);
        self::assertSame('Model "llama3.2" could not be verified (provider unreachable: Provider of connection "remote" is unreachable (fake): connection refused)', $e->getMessage());
    }

    public function testRejectedCredentialsCountAsUnreachable(): void
    {
        $lister = new FakeModelLister()->unauthenticated();

        $e = $this->unavailable(new ModelAvailabilityCheck($lister), ProviderConnectionFixtures::anthropic('claude'), 'claude-haiku-4-5-20251001');

        self::assertTrue($e->isUnreachable());
        self::assertSame(ModelListingException::UNAUTHENTICATED, $e->listingFailure?->reason);
        self::assertStringNotContainsString(ProviderConnectionFixtures::PLACEHOLDER_API_KEY, $e->getMessage());
    }

    public function testResetRestoresTheDefault(): void
    {
        $lister = new FakeModelLister()->unreachable();
        $lister->reset();

        new ModelAvailabilityCheck($lister)->assertAvailable(ProviderConnectionFixtures::anthropic(), 'claude-anything');
        self::assertCount(1, $lister->getListedConnections());
    }

    private function unavailable(ModelAvailabilityCheck $check, \Dmstr\ApiConfiguration\Entity\ApiConfiguration $connection, string $model): ModelUnavailableException
    {
        try {
            $check->assertAvailable($connection, $model);
        } catch (ModelUnavailableException $e) {
            return $e;
        }

        self::fail('Expected a ModelUnavailableException.');
    }
}
