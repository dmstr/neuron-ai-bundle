<?php

// file generated with AI assistance: Claude Code - 2026-11-06 12:45:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\Retrieval;

use Dmstr\NeuronAiBundle\Retrieval\CollectionRetriever;
use NeuronAI\RAG\Document;
use PHPUnit\Framework\TestCase;

/**
 * Kernel-free: identical text in a ranked hit list is kept once, the
 * best-ranked copy, also when the copies differ only in whitespace.
 */
final class DistinctPassagesTest extends TestCase
{
    public function testTheBestRankedCopyOfATextIsKept(): void
    {
        $hits = [
            self::doc('a', "Logo and claim\n\nUse the logo."),
            self::doc('b', 'Colours'),
            self::doc('c', 'Logo and claim Use the logo.'),
            self::doc('d', '  Colours '),
        ];

        $ids = array_map(static fn (Document $d): string => (string) $d->getId(), CollectionRetriever::distinct($hits));

        self::assertSame(['a', 'b'], $ids);
    }

    private static function doc(string $id, string $content): Document
    {
        return (new Document($content))->setId($id);
    }
}
