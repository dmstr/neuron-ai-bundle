<?php

// file generated with AI assistance: Claude Code - 2026-10-04 09:02:36 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\Retrieval;

use Dmstr\NeuronAiBundle\Retrieval\RankFusion;
use NeuronAI\RAG\Document;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Kernel-free: the weighted reciprocal rank fusion,
 * score(d) = Σ w / (k + rank) over the lists containing d.
 */
final class RankFusionTest extends TestCase
{
    public function testFormulaWithKSixty(): void
    {
        $fused = RankFusion::fuse([
            [[self::doc('a'), self::doc('b'), self::doc('c')], 0.5],
            [[self::doc('c'), self::doc('a')], 0.5],
        ]);

        self::assertSame(['a', 'c', 'b'], self::ids($fused));
        self::assertEqualsWithDelta(0.5 / 61 + 0.5 / 62, $fused[0]->getScore(), 1e-15);
        self::assertEqualsWithDelta(0.5 / 63 + 0.5 / 61, $fused[1]->getScore(), 1e-15);
        self::assertEqualsWithDelta(0.5 / 62, $fused[2]->getScore(), 1e-15);
        self::assertSame(60, RankFusion::K);
    }

    public function testOtherK(): void
    {
        $fused = RankFusion::fuse([[[self::doc('a'), self::doc('b')], 1.0]], 0);

        self::assertSame([1.0, 0.5], array_map(static fn (Document $d): float => $d->getScore(), $fused));
    }

    /**
     * @return iterable<string, array{float, list<string>}>
     */
    public static function weights(): iterable
    {
        // keyword list: k1, k2, v3; vector list: v1, v2, v3, k2
        yield 'weight 0: vector ranks only, keyword-only hits dropped' => [0.0, ['v1', 'v2', 'v3', 'k2']];
        yield 'weight 1: keyword ranks only, vector-only hits dropped' => [1.0, ['k1', 'k2', 'v3']];
        yield 'weight 0.5: both' => [0.5, ['k2', 'v3', 'k1', 'v1', 'v2']];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('weights')]
    public function testWeights(float $weight, array $expected): void
    {
        $keyword = [self::doc('k1'), self::doc('k2'), self::doc('v3')];
        $vector = [self::doc('v1'), self::doc('v2'), self::doc('v3'), self::doc('k2')];

        $fused = RankFusion::fuse([[$vector, 1.0 - $weight], [$keyword, $weight]]);

        self::assertSame($expected, self::ids($fused));
        foreach ($fused as $document) {
            self::assertGreaterThan(0.0, $document->getScore());
        }
    }

    public function testWeightZeroEqualsTheVectorOrderAndWeightOneTheKeywordOrder(): void
    {
        $vector = [self::doc('d'), self::doc('b'), self::doc('a')];
        $keyword = [self::doc('a'), self::doc('c'), self::doc('d')];

        self::assertSame(['d', 'b', 'a'], self::ids(RankFusion::fuse([[$vector, 1.0], [$keyword, 0.0]])));
        self::assertSame(['a', 'c', 'd'], self::ids(RankFusion::fuse([[$vector, 0.0], [$keyword, 1.0]])));
    }

    public function testTiesAreBrokenByIdAscending(): void
    {
        // b and a both sit at rank 1 of one list and rank 2 of the other:
        // identical score, the id decides; same for single-list hits.
        $fused = RankFusion::fuse([
            [[self::doc('b'), self::doc('a'), self::doc('z')], 0.5],
            [[self::doc('a'), self::doc('b'), self::doc('y')], 0.5],
        ]);

        self::assertSame(['a', 'b', 'y', 'z'], self::ids($fused));
        self::assertSame($fused[0]->getScore(), $fused[1]->getScore());
        self::assertSame($fused[2]->getScore(), $fused[3]->getScore());
    }

    public function testEqualContributionsInAnyListOrderGiveIdenticalScores(): void
    {
        // Three lists: x at ranks 1, 2, 3 and w at ranks 3, 1, 2 must score
        // bit-identically, so the tie-break is by id and not by rounding.
        $fused = RankFusion::fuse([
            [[self::doc('x'), self::doc('p'), self::doc('w')], 0.3],
            [[self::doc('w'), self::doc('x')], 0.3],
            [[self::doc('r'), self::doc('w'), self::doc('x')], 0.3],
        ]);

        self::assertSame(['w', 'x'], \array_slice(self::ids($fused), 0, 2));
        self::assertSame($fused[0]->getScore(), $fused[1]->getScore());
    }

    public function testDocumentsInOneListOnly(): void
    {
        $fused = RankFusion::fuse([
            [[self::doc('a')], 0.4],
            [[self::doc('b')], 0.6],
        ]);

        self::assertSame(['b', 'a'], self::ids($fused));
        self::assertEqualsWithDelta(0.6 / 61, $fused[0]->getScore(), 1e-15);
        self::assertEqualsWithDelta(0.4 / 61, $fused[1]->getScore(), 1e-15);
    }

    public function testZeroScoresAreDroppedAndEmptyInputGivesNothing(): void
    {
        self::assertSame([], RankFusion::fuse([]));
        self::assertSame([], RankFusion::fuse([[[], 0.5]]));
        self::assertSame([], RankFusion::fuse([[[self::doc('a')], 0.0]]));
        self::assertSame(['b'], self::ids(RankFusion::fuse([[[self::doc('a')], 0.0], [[self::doc('b')], 0.1]])));
    }

    /**
     * Two collections with two models: four lists (vector and keyword per
     * collection); ids are distinct per row, so nothing merges across
     * collections, and the metadata of the first occurrence is kept.
     */
    public function testMultipleListsOfTwoCollectionsAndTwoModels(): void
    {
        $de = static fn (string $id): Document => self::doc($id, ['collection_id' => 'de', 'model' => 'nomic-embed-text']);
        $en = static fn (string $id): Document => self::doc($id, ['collection_id' => 'en', 'model' => 'mxbai-embed-large']);

        $fused = RankFusion::fuse([
            [[$de('d1'), $de('d2')], 0.5],          // vector, de
            [[$de('d2'), $de('d3')], 0.5],          // keyword, de
            [[$en('e1'), $en('e2')], 0.5],          // vector, en
            [[$en('e2')], 0.5],                     // keyword, en
        ]);

        self::assertSame(['d2', 'e2', 'd1', 'e1', 'd3'], self::ids($fused));
        self::assertSame(['de', 'en', 'de', 'en', 'de'], array_map(static fn (Document $d): string => $d->getMetadata()['collection_id'], $fused));
        self::assertSame('mxbai-embed-large', $fused[1]->getMetadata()['model']);
        self::assertEqualsWithDelta(0.5 / 62 + 0.5 / 61, $fused[0]->getScore(), 1e-15);
        self::assertSame($fused[0]->getScore(), $fused[1]->getScore());
    }

    public function testInputDocumentsAreNotChangedAndDuplicatesInOneListCountOnce(): void
    {
        $a = self::doc('a');
        $a->setScore(0.93);

        $fused = RankFusion::fuse([[[$a, self::doc('a'), self::doc('b')], 1.0]]);

        self::assertSame(0.93, $a->getScore());
        self::assertNotSame($a, $fused[0]);
        self::assertSame(['a', 'b'], self::ids($fused));
        self::assertEqualsWithDelta(1 / 62, $fused[1]->getScore(), 1e-15, 'b is rank 2, the duplicate a is skipped');
    }

    public function testNegativeWeightIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        RankFusion::fuse([[[self::doc('a')], -0.1]]);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private static function doc(string $id, array $metadata = []): Document
    {
        return (new Document('content '.$id))->setId($id)->setMetadata($metadata);
    }

    /**
     * @param list<Document> $documents
     *
     * @return list<string>
     */
    private static function ids(array $documents): array
    {
        return array_map(static fn (Document $d): string => (string) $d->getId(), $documents);
    }
}

// - revised 2026-10-14
