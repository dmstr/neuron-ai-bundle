<?php

// file generated with AI assistance: Claude Code - 2026-10-01 17:14:46 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Document\Extractor;

use Dmstr\NeuronAiBundle\Document\ExtractedDocument;

/**
 * Turns a local file of a supported MIME type into text sections.
 *
 * Implementations are registered as services tagged
 * `dmstr_neuron_ai.document_extractor` and collected by
 * {@see ExtractorRegistry}; the first one whose supports() returns true
 * for a MIME type is used.
 */
interface ExtractorInterface
{
    /**
     * Short, stable name, recorded with the document (e.g. `text`).
     */
    public function name(): string;

    /**
     * @param string $mimeType lower-case MIME type without parameters
     */
    public function supports(string $mimeType): bool;

    /**
     * Returns an empty document (no sections) when the file has no text;
     * the caller decides how to treat that.
     *
     * @param string $path readable local file
     *
     * @throws ExtractionException when the file cannot be read or decoded
     */
    public function extract(string $path, ExtractionOptions $options): ExtractedDocument;
}
