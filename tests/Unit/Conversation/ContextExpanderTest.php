<?php

// file generated with AI assistance: Claude Code - 2026-10-04 10:14:36 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\Conversation;

use Dmstr\NeuronAiBundle\Conversation\ContextChunk;
use Dmstr\NeuronAiBundle\Conversation\ContextExpander;
use Dmstr\NeuronAiBundle\Retrieval\Neighbour;
use Dmstr\NeuronAiBundle\Retrieval\NeighbourSourceInterface;
use PHPUnit\Framework\TestCase;

/**
 * Kernel-free: {@see ContextExpander} against an in-memory neighbour source.
 * Documents are lists of chunks in chunk order; a chunk id is
 * "<document>:<index>" and its content "<document><index>" padded to a given
 * length, so costs are easy to compute (length + 2 for the separator).
 */
final class ContextExpanderTest extends TestCase
{
    public function testBudgetZeroIsIdentityWithoutLookup(): void
    {
        $source = $this->source(['a' => ['a0', 'a1', 'a2']]);
        $chunks = [$this->hit('a', 1)];

        self::assertSame($chunks, (new ContextExpander($source))->expand($chunks, 0));
        self::assertSame($chunks, (new ContextExpander($source))->expand($chunks, -5));
        self::assertSame([], (new ContextExpander($source))->expand([], 1000));
        self::assertSame([], $source->lookups);
    }

    public function testNoSourceIsIdentity(): void
    {
        $chunks = [$this->hit('a', 1)];

        self::assertSame($chunks, (new ContextExpander())->expand($chunks, 1000));
    }

    public function testBeforeAndAfterAreMergedWithSeparators(): void
    {
        $source = $this->source(['a' => ['a0', 'a1', 'a2']]);

        $result = (new ContextExpander($source))->expand([$this->hit('a', 1)], 1000);

        self::assertCount(1, $result);
        self::assertSame("a0\n\na1\n\na2", $result[0]->content);
        self::assertSame(['a:1'], $source->lookups);
    }

    public function testFirstAndLastChunkOfADocumentGetOneNeighbour(): void
    {
        $source = $this->source(['a' => ['a0', 'a1', 'a2'], 'b' => ['b0', 'b1', 'b2']]);

        $result = (new ContextExpander($source))->expand([$this->hit('a', 0), $this->hit('b', 2)], 1000);

        self::assertSame("a0\n\na1", $result[0]->content);
        self::assertSame("b1\n\nb2", $result[1]->content);
    }

    public function testNeighbourThatIsItselfAHitIsNotAdded(): void
    {
        $source = $this->source(['a' => ['a0', 'a1', 'a2', 'a3']]);

        // a2 is a lower-ranked hit and the after-neighbour of a1.
        $result = (new ContextExpander($source))->expand([$this->hit('a', 1), $this->hit('a', 2)], 1000);

        self::assertSame("a0\n\na1", $result[0]->content);
        // a1 is a hit as well; only a3 is added to a2.
        self::assertSame("a2\n\na3", $result[1]->content);
    }

    public function testSameNeighbourOfTwoHitsIsAddedOnce(): void
    {
        $source = $this->source(['a' => ['a0', 'a1', 'a2']]);

        // a1 is the after-neighbour of a0 and the before-neighbour of a2.
        $result = (new ContextExpander($source))->expand([$this->hit('a', 0), $this->hit('a', 2)], 1000);

        self::assertSame("a0\n\na1", $result[0]->content);
        self::assertSame('a2', $result[1]->content);
    }

    public function testNeighbourLargerThanTheRemainingBudgetIsSkippedWhileASmallerOneStillFits(): void
    {
        // a0 is 50 characters (cost 52), a2 is 5 (cost 7); budget 20.
        $source = $this->source(['a' => [str_repeat('x', 50), 'a1', 'small']]);

        $result = (new ContextExpander($source))->expand([$this->hit('a', 1)], 20);

        self::assertSame("a1\n\nsmall", $result[0]->content);
    }

    public function testNeighbourIsNeverCutAndAnExactFitIsAdded(): void
    {
        $source = $this->source(['a' => ['a0', 'a1', str_repeat('y', 8)]]);

        // before costs 4, after costs 10: budget 13 fits only before, 14 fits both.
        $tight = (new ContextExpander($source))->expand([$this->hit('a', 1)], 13);
        $exact = (new ContextExpander($source))->expand([$this->hit('a', 1)], 14);

        self::assertSame("a0\n\na1", $tight[0]->content);
        self::assertSame("a0\n\na1\n\nyyyyyyyy", $exact[0]->content);
    }

    public function testCostCountsCharactersNotBytes(): void
    {
        // "Ölwechsel" is 9 characters but 10 bytes: cost 11 fits a budget of 11.
        $source = $this->source(['a' => ['Ölwechsel', 'a1']]);

        $result = (new ContextExpander($source))->expand([$this->hit('a', 1)], 11);

        self::assertSame("Ölwechsel\n\na1", $result[0]->content);
    }

    public function testTotalAddedNeverExceedsTheBudgetForManyHits(): void
    {
        $documents = [];
        $chunks = [];
        for ($d = 0; $d < 10; ++$d) {
            $parts = [];
            for ($i = 0; $i < 5; ++$i) {
                $parts[] = str_repeat(\chr(97 + $d), 3 + (($d * 7 + $i * 13) % 40));
            }
            $documents['d'.$d] = $parts;
            $chunks[] = $this->hit('d'.$d, 1 + ($d % 3));
        }
        $source = $this->source($documents);

        foreach ([1, 7, 25, 60, 150, 400, 10_000] as $budget) {
            $result = (new ContextExpander($source))->expand($chunks, $budget);

            $added = 0;
            foreach ($result as $i => $chunk) {
                $added += mb_strlen($chunk->content) - mb_strlen($chunks[$i]->content);
            }
            self::assertLessThanOrEqual($budget, $added, 'budget '.$budget);
        }

        // With an ample budget every hit gets both neighbours.
        $all = (new ContextExpander($source))->expand($chunks, 10_000);
        foreach ($all as $i => $chunk) {
            self::assertSame(2, substr_count($chunk->content, "\n\n"), 'hit '.$i);
        }
    }

    public function testBudgetGoesToTheBestRankedHitFirst(): void
    {
        $source = $this->source(['a' => ['a0', 'a1', 'a2'], 'b' => ['b0', 'b1', 'b2']]);

        // Budget 8 = two neighbours of cost 4: both go to the first hit.
        $result = (new ContextExpander($source))->expand([$this->hit('b', 1), $this->hit('a', 1)], 8);

        self::assertSame("b0\n\nb1\n\nb2", $result[0]->content);
        self::assertSame('a1', $result[1]->content);
    }

    public function testOrderScoreAndMetadataAreUnchanged(): void
    {
        $source = $this->source(['a' => ['a0', 'a1', 'a2'], 'b' => ['b0', 'b1']]);
        $chunks = [$this->hit('b', 0, 0.9), $this->hit('a', 1, 0.7), $this->hit('a', 2, 0.4)];

        $result = (new ContextExpander($source))->expand($chunks, 1000);

        self::assertCount(3, $result);
        foreach ($chunks as $i => $chunk) {
            $expected = $chunk->toArray();
            $actual = $result[$i]->toArray();
            unset($expected['content'], $actual['content']);
            self::assertSame($expected, $actual, 'hit '.$i);
        }
    }

    public function testHitsWithoutChunkIdAreUntouched(): void
    {
        $source = $this->source(['a' => ['a0', 'a1', 'a2']]);
        $crawled = new ContextChunk('crawled', 0.8, sourceUrl: 'https://example.org/');

        $result = (new ContextExpander($source))->expand([$crawled, $this->hit('a', 1)], 1000);

        self::assertSame($crawled, $result[0]);
        self::assertSame("a0\n\na1\n\na2", $result[1]->content);
        self::assertSame(['a:1'], $source->lookups);
    }

    public function testHitWithoutNeighboursIsReturnedAsIs(): void
    {
        $source = $this->source([]);
        $chunk = new ContextChunk('ingest', 0.5, chunkId: 'ingest-1');

        $result = (new ContextExpander($source))->expand([$chunk], 1000);

        self::assertSame($chunk, $result[0]);
        self::assertSame(['ingest-1'], $source->lookups);
    }

    private function hit(string $document, int $index, float $score = 0.5): ContextChunk
    {
        return new ContextChunk(
            content: $document.$index,
            score: $score,
            collectionId: 'collection-1',
            collectionName: 'docs',
            topic: 'topic-'.$document,
            chunkId: $document.':'.$index,
            chunkNumber: 0,
            documentId: $document,
            filename: $document.'.md',
            breadcrumb: 'Heading',
        );
    }

    /**
     * @param array<string, list<string>> $documents document => contents in chunk order
     */
    private function source(array $documents): FakeNeighbourSource
    {
        return new FakeNeighbourSource($documents);
    }
}

/**
 * In-memory neighbour source: chunk "<document>:<index>" has the
 * neighbours at index - 1 and + 1 of the same document; unknown ids have
 * none. Records every lookup.
 */
final class FakeNeighbourSource implements NeighbourSourceInterface
{
    /** @var list<string> */
    public array $lookups = [];

    /**
     * @param array<string, list<string>> $documents
     */
    public function __construct(private readonly array $documents)
    {
    }

    public function neighbours(string $chunkId): array
    {
        $this->lookups[] = $chunkId;
        $parts = explode(':', $chunkId, 2);
        if (2 !== \count($parts) || !isset($this->documents[$parts[0]])) {
            return ['before' => null, 'after' => null];
        }
        [$document, $index] = [$parts[0], (int) $parts[1]];

        return [
            'before' => $this->at($document, $index - 1),
            'after' => $this->at($document, $index + 1),
        ];
    }

    private function at(string $document, int $index): ?Neighbour
    {
        $content = $this->documents[$document][$index] ?? null;

        return null === $content ? null : new Neighbour($document.':'.$index, $content);
    }
}
