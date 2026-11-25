<?php

// file generated with AI assistance: Claude Code - 2026-10-14 13:30:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\Retrieval;

use Dmstr\NeuronAiBundle\Embeddings\EmbeddingDocumentSchema;
use Dmstr\NeuronPgvectorStore\PgVectorStore;
use NeuronAI\RAG\Schema\DocumentSchemaException;
use NeuronAI\RAG\VectorStore\Filter\Filter;
use NeuronAI\RAG\VectorStore\SearchRequest;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;
use PHPUnit\Framework\TestCase;

/**
 * Kernel-free checks of the store as the neuron-ai-bundle uses it: the
 * document schema of the embeddings (`EmbeddingDocumentSchema`) declares
 * `collection_id` and `topic` filterable, the store adds `model`, and a
 * filter or scope outside that schema is refused. The generic filter
 * contract of dmstr/neuron-pgvector-store (SQL compilation, raw filters,
 * hydration) and its database tests are in that package. The in-memory
 * SQLite connection is never used.
 */
final class PgVectorStoreFilterTest extends TestCase
{
    private const MODEL = 'nomic-embed-text';

    public function testSchemaDeclaresTheEmbeddingFieldsAndTheModel(): void
    {
        $schema = $this->store()->getSchema();

        self::assertInstanceOf(VectorStoreInterface::class, $this->store());
        foreach (['collection_id', 'topic', 'model'] as $field) {
            self::assertTrue($schema->getField($field)?->isFilterable(), $field.' is filterable');
        }
        self::assertTrue((new PgVectorStore(new \PDO('sqlite::memory:')))->getSchema()->getField('model')?->isFilterable(), 'the store declares model without a schema too');
    }

    public function testRequestFilterOnAnUndeclaredFieldIsRefused(): void
    {
        $this->expectException(DocumentSchemaException::class);
        $this->expectExceptionMessage('Filter field "chunk_id" is not declared');

        $this->scoped()->searchSql(new SearchRequest([1.0], Filter::eq('chunk_id', 'x')));
    }

    public function testScopeWithAValueOfTheWrongTypeIsRefused(): void
    {
        $this->expectException(DocumentSchemaException::class);

        $this->store()->deleteByModel(self::MODEL, ['collection_id' => 42]);
    }

    private function store(): PgVectorStore
    {
        return new PgVectorStore(new \PDO('sqlite::memory:'), 'embedding', schema: EmbeddingDocumentSchema::schema());
    }

    private function scoped(): PgVectorStore
    {
        return $this->store()->forModel(self::MODEL, 3);
    }
}
// - revised 2026-11-25
