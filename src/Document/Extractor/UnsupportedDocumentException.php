<?php

// file generated with AI assistance: Claude Code - 2026-10-01 17:14:46 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Document\Extractor;

/**
 * No registered extractor supports the file's MIME type.
 */
final class UnsupportedDocumentException extends ExtractionException
{
    public static function forMimeType(string $mimeType): self
    {
        return new self(sprintf('unsupported file type %s', $mimeType));
    }
}
