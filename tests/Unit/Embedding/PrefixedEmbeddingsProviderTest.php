<?php

// file generated with AI assistance: Claude Code - 2026-09-30 05:42:49 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\Embedding;

use Dmstr\NeuronAiBundle\Embeddings\EmbeddingModel;
use Dmstr\NeuronAiBundle\Embeddings\PrefixedEmbeddingsProvider;
use Dmstr\NeuronAiBundle\Testing\FakeEmbeddingsProvider;
use NeuronAI\RAG\Document;
use PHPUnit\Framework\TestCase;

class PrefixedEmbeddingsProviderTest extends TestCase
{
    public function testQueryPrefixIsPrepended(): void
    {
        $fake = new FakeEmbeddingsProvider('nomic-embed-text', 768);
        $provider = new PrefixedEmbeddingsProvider($fake, 'search_query: ', 'search_document: ');

        $vector = $provider->embedText('hallo');

        $fake->getRecorder()->assertEmbeddedText('search_query: hallo');
        self::assertCount(768, $vector);
    }

    public function testDocumentPrefixIsPrependedAndContentStaysClean(): void
    {
        $fake = new FakeEmbeddingsProvider('nomic-embed-text', 768);
        $provider = new PrefixedEmbeddingsProvider($fake, 'search_query: ', 'search_document: ');
        $document = new Document('Text');

        $result = $provider->embedDocuments([$document]);

        $fake->getRecorder()->assertEmbeddedText('search_document: Text');
        self::assertSame([$document], $result);
        self::assertCount(768, $document->getEmbedding() ?? []);
        self::assertSame('Text', $document->getContent());
    }

    public function testEmptyPrefixesPassThrough(): void
    {
        $fake = new FakeEmbeddingsProvider('nomic-embed-text', 768);
        $provider = new PrefixedEmbeddingsProvider($fake, '', '');

        $provider->embedText('hallo');
        $provider->embedDocument(new Document('Text'));

        self::assertSame(['hallo', 'Text'], $fake->getRecorder()->getRecordedTexts());
    }

    public function testForModelUsesThePrefixesOfTheModel(): void
    {
        $fake = new FakeEmbeddingsProvider('any', 8);
        $nomic = new EmbeddingModel('nomic-embed-text', 'ollama', 768, 'search_query: ', 'search_document: ');
        $plain = new EmbeddingModel('plain-model', 'ollama', 8);

        PrefixedEmbeddingsProvider::forModel($fake, $nomic)->embedText('hallo');
        PrefixedEmbeddingsProvider::forModel($fake, $nomic)->embedDocument(new Document('Text'));
        PrefixedEmbeddingsProvider::forModel($fake, $plain)->embedText('hallo');

        self::assertSame(['search_query: hallo', 'search_document: Text', 'hallo'], $fake->getRecorder()->getRecordedTexts());
    }

    public function testFakeVectorIsDeterministicWithRequestedDimension(): void
    {
        $fake = new FakeEmbeddingsProvider('nomic-embed-text', 768);

        self::assertSame($fake->embedText('a'), $fake->embedText('a'));
        self::assertNotSame($fake->embedText('a'), $fake->embedText('b'));
        self::assertCount(5, (new FakeEmbeddingsProvider('small', 5))->embedText('a'));
        self::assertCount(1024, (new FakeEmbeddingsProvider('mxbai-embed-large', 1024))->embedText('a'));
    }
}

// - revised 2026-10-14
