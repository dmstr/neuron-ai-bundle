<?php

// file generated with AI assistance: Claude Code - 2026-10-01 17:14:46 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Document\Extractor;

use Dmstr\NeuronAiBundle\Document\ExtractedDocument;

/**
 * All registered extractors (services tagged
 * `dmstr_neuron_ai.document_extractor`, in tag priority order); the first
 * extractor that supports a MIME type wins.
 */
final class ExtractorRegistry
{
    /** @var list<ExtractorInterface> */
    private array $extractors = [];

    /**
     * @param iterable<ExtractorInterface> $extractors
     */
    public function __construct(iterable $extractors)
    {
        foreach ($extractors as $extractor) {
            $this->extractors[] = $extractor;
        }
    }

    /**
     * The extractor that would handle the MIME type, or null.
     */
    public function find(string $mimeType): ?ExtractorInterface
    {
        $mimeType = self::normalize($mimeType);
        foreach ($this->extractors as $extractor) {
            if ($extractor->supports($mimeType)) {
                return $extractor;
            }
        }

        return null;
    }

    /**
     * Extracts the file with the first supporting extractor; the result
     * carries that extractor's name() as extractorName.
     *
     * @throws UnsupportedDocumentException when no extractor supports the type
     * @throws ExtractionException          when the extractor fails
     */
    public function extract(string $mimeType, string $path, ExtractionOptions $options): ExtractedDocument
    {
        $extractor = $this->find($mimeType);
        if ($extractor === null) {
            throw UnsupportedDocumentException::forMimeType(self::normalize($mimeType));
        }

        return $extractor->extract($path, $options)->withExtractorName($extractor->name());
    }

    /**
     * Lower case, parameters such as `; charset=utf-8` removed.
     */
    private static function normalize(string $mimeType): string
    {
        return strtolower(trim(explode(';', $mimeType, 2)[0]));
    }
}
