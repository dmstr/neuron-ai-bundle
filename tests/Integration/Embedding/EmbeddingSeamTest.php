<?php

// file generated with AI assistance: Claude Code - 2026-09-30 05:42:49 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Integration\Embedding;

use Dmstr\NeuronAiBundle\Embeddings\EmbeddingConnectionException;
use Dmstr\NeuronAiBundle\Embeddings\EmbeddingsProviderLocator;
use Dmstr\NeuronAiBundle\Embeddings\PrefixedEmbeddingsProvider;
use Dmstr\NeuronAiBundle\ProviderFactory;
use Dmstr\NeuronAiBundle\Testing\FakeEmbeddingsProvider;
use Dmstr\NeuronAiBundle\Testing\FakeProviderFactory;
use Dmstr\NeuronAiBundle\Testing\ProviderConnectionFixtures;
use NeuronAI\Chat\Messages\UserMessage;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The when@test wiring replaces Ollama/Anthropic with fakes: the provider
 * factory hands out one deterministic fake per `ollama` connection and
 * registered model, all recording on one shared recorder, while the
 * bundle's locator still adds the task prefixes of each model and keeps
 * one provider per (connection, model). The connections are unsaved
 * fixtures; no database is involved.
 */
class EmbeddingSeamTest extends KernelTestCase
{
    public function testFactoryCreatesFakesWithTheDimensionOfEachModel(): void
    {
        $factory = $this->factory();

        $connection = ProviderConnectionFixtures::ollama();
        $nomic = $factory->embeddingsProvider($connection, 'nomic-embed-text');
        $mxbai = $factory->embeddingsProvider($connection, 'mxbai-embed-large');
        self::assertInstanceOf(FakeEmbeddingsProvider::class, $nomic);
        self::assertInstanceOf(FakeEmbeddingsProvider::class, $mxbai);

        self::assertCount(768, $nomic->embedText('hallo'));
        self::assertCount(1024, $mxbai->embedText('hallo'));

        $recorder = $factory->getEmbeddingsRecorder();
        $recorder->assertModelUsed('nomic-embed-text');
        $recorder->assertModelUsed('mxbai-embed-large');
        self::assertSame([
            ['model' => 'nomic-embed-text', 'text' => 'hallo'],
            ['model' => 'mxbai-embed-large', 'text' => 'hallo'],
        ], $recorder->getRecorded());
    }

    public function testUnknownModelIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown embedding model "unknown-model"');

        $this->factory()->embeddingsProvider(ProviderConnectionFixtures::ollama(), 'unknown-model');
    }

    public function testLocatorWrapsTheFakeWithTheModelPrefixes(): void
    {
        $factory = $this->factory();
        /** @var EmbeddingsProviderLocator $locator */
        $locator = self::getContainer()->get(EmbeddingsProviderLocator::class);

        $connection = ProviderConnectionFixtures::ollama();

        $provider = $locator->for($connection, 'nomic-embed-text');
        self::assertInstanceOf(PrefixedEmbeddingsProvider::class, $provider);
        self::assertSame($provider, $locator->for($connection, 'nomic-embed-text'));

        self::assertCount(768, $provider->embedText('hallo'));
        self::assertCount(1024, $locator->for($connection, 'mxbai-embed-large')->embedText('hallo'));

        $recorder = $factory->getEmbeddingsRecorder();
        $recorder->assertEmbeddedText('search_query: hallo', 'nomic-embed-text');
        $recorder->assertEmbeddedText('Represent this sentence for searching relevant passages: hallo', 'mxbai-embed-large');
    }

    public function testLocatorKeepsOneProviderPerConnectionAndModel(): void
    {
        $factory = $this->factory();
        /** @var EmbeddingsProviderLocator $locator */
        $locator = self::getContainer()->get(EmbeddingsProviderLocator::class);
        $local = ProviderConnectionFixtures::ollama('seam-local');
        $remote = ProviderConnectionFixtures::ollama('seam-remote', url: 'http://remote.fake.test:11434/api');

        $first = $locator->for($local, 'nomic-embed-text');
        $other = $locator->for($remote, 'nomic-embed-text');
        self::assertNotSame($first, $other);
        self::assertSame($first, $locator->for($local, 'nomic-embed-text'));

        // A changed configuration of the same connection builds a new provider.
        $local->setConfigJson(ProviderConnectionFixtures::ollamaConfig('http://moved.fake.test:11434/api'));
        self::assertNotSame($first, $locator->for($local, 'nomic-embed-text'));

        self::assertSame(['seam-local', 'seam-remote', 'seam-local'], array_column($factory->getRequestedEmbeddings(), 'connection'));
    }

    /**
     * @return iterable<string, array{\Closure(): \Dmstr\ApiConfiguration\Entity\ApiConfiguration, string}>
     */
    public static function unusableConnections(): iterable
    {
        yield 'inactive' => [static fn () => ProviderConnectionFixtures::ollama('seam-off', active: false), EmbeddingConnectionException::INACTIVE];
        yield 'anthropic' => [static fn () => ProviderConnectionFixtures::anthropic('seam-anthropic'), EmbeddingConnectionException::WRONG_TYPE];
    }

    /**
     * @param \Closure(): \Dmstr\ApiConfiguration\Entity\ApiConfiguration $connection
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('unusableConnections')]
    public function testLocatorRefusesAnUnusableConnection(\Closure $connection, string $reason): void
    {
        $factory = $this->factory();
        /** @var EmbeddingsProviderLocator $locator */
        $locator = self::getContainer()->get(EmbeddingsProviderLocator::class);

        try {
            $locator->for($connection(), 'nomic-embed-text');
            self::fail('Expected an EmbeddingConnectionException.');
        } catch (EmbeddingConnectionException $e) {
            self::assertSame($reason, $e->reason);
        }

        self::assertSame([], $factory->getRequestedEmbeddings());
    }

    public function testProviderFactoryIsFakeWithQueuedAnswers(): void
    {
        $factory = $this->factory();

        $factory->queueAnswer('Antwort');
        $answer = $factory->chatProvider(ProviderConnectionFixtures::ollama(), FakeProviderFactory::OLLAMA_MODEL)->chat(new UserMessage('Frage'));

        self::assertSame('Antwort', $answer->message()->getContent());
        $factory->getChatProvider()->assertCallCount(1);
    }

    private function factory(): FakeProviderFactory
    {
        self::bootKernel();

        $factory = self::getContainer()->get(ProviderFactory::class);
        self::assertInstanceOf(FakeProviderFactory::class, $factory);

        return $factory;
    }
}
