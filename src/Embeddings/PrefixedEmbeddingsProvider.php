<?php

// file generated with AI assistance: Claude Code - 2026-09-30 05:42:49 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Embeddings;

use NeuronAI\RAG\Document;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;

/**
 * Decorator that prepends model-specific task prefixes before embedding.
 *
 * Some embedding models (e.g. nomic-embed-text) are trained with task
 * prefixes such as "search_query: " and "search_document: " and produce
 * noticeably worse retrieval without them. Queries go through
 * {@see embedText()} and get the query prefix; documents go through
 * {@see embedDocument()}/{@see embedDocuments()} and get the document prefix.
 *
 * The prefix is only sent to the model: a document's `content` is never
 * modified, so stored content stays clean. Empty prefixes mean pass-through.
 * The embedded text is always the document content, as neuron-ai 4.x
 * providers embed it.
 */
final class PrefixedEmbeddingsProvider implements EmbeddingsProviderInterface
{
    public function __construct(
        private readonly EmbeddingsProviderInterface $inner,
        private readonly string $queryPrefix,
        private readonly string $documentPrefix,
    ) {
    }

    /**
     * Wraps $inner with the task prefixes of a registered embedding model.
     * Empty prefixes mean pass-through.
     */
    public static function forModel(EmbeddingsProviderInterface $inner, EmbeddingModel $model): self
    {
        return new self($inner, $model->queryPrefix, $model->documentPrefix);
    }

    /**
     * @return float[]
     */
    public function embedText(string $text): array
    {
        return $this->inner->embedText($this->queryPrefix.$text);
    }

    public function embedDocument(Document $document): Document
    {
        return $document->setEmbedding($this->inner->embedText($this->documentPrefix.$document->getContent()));
    }

    /**
     * @param Document[] $documents
     *
     * @return Document[]
     */
    public function embedDocuments(array $documents): array
    {
        foreach ($documents as $document) {
            $this->embedDocument($document);
        }

        return $documents;
    }
}

// - revised 2026-10-14
