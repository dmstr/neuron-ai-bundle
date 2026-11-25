<?php

// file generated with AI assistance: Claude Code - 2026-10-08 20:34:12 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Retrieval;

use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * A collection reference (UUID or name) matches no collection.
 *
 * HTTP 422 by itself, like {@see \Dmstr\NeuronAiBundle\Embeddings\EmbeddingConnectionException},
 * so a search or chat request naming an unknown collection answers 422 with
 * the detail `Collection "<reference>" not found`. Callers that need
 * another answer (a scope check reporting the field, an MCP tool reporting
 * "not found") catch this class and read {@see $reference}.
 */
final class UnknownCollectionReferenceException extends UnprocessableEntityHttpException
{
    public function __construct(
        /** The reference as given by the caller. */
        public readonly string $reference,
    ) {
        parent::__construct(\sprintf('Collection "%s" not found', $reference));
    }
}
