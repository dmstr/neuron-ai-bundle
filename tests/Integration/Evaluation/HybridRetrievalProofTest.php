<?php

// file generated with AI assistance: Claude Code - 2026-10-04 09:18:33 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Integration\Evaluation;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use Dmstr\NeuronAiBundle\Evaluation\RetrievalStatus;
use Dmstr\NeuronAiBundle\Retrieval\CollectionRetriever;
use Dmstr\NeuronAiBundle\Tests\Kernel\TestDatabase;
use Dmstr\NeuronAiBundle\Tests\Support\AgentFixtures;
use Dmstr\NeuronAiBundle\Tests\Support\EvalRunTestTrait;
use Dmstr\NeuronPgvectorStore\PgVectorStore;
use NeuronAI\RAG\Document;
use PHPUnit\Framework\Attributes\Group;

/**
 * Proof of hybrid retrieval: two retrieval-only
 * evaluation runs over ONE corpus and ONE question set, version A vector
 * only (`hybrid` false) and version B hybrid (`hybrid` true,
 * `hybridWeight` 0.5), same collections, same depth
 * {@see self::DEPTH}. B must not lose against A overall (recall and MRR),
 * every marker question must be a miss or a lower rank in A and strictly
 * better in B, and the control question that the vector side already
 * answers at rank 1 must stay there.
 *
 * Corpus: a `german` collection of 18 chunks (the control chunk, five
 * marker chunks, twelve fillers about unrelated office topics) and an
 * `english` collection of 5 chunks (one marker chunk, four fillers), both
 * with model {@see self::EMBEDDING_MODEL}, so the vector pool of A is the
 * cosine order over all 23 chunks. Both collections are below
 * {@see CollectionRetriever::HYBRID_MIN_CANDIDATES}, so every chunk is in
 * the vector list of its collection; a chunk the keyword side finds
 * therefore scores at least 0.5/(60+20) + 0.5/(60+20) > 0.5/61 and ranks
 * above every chunk the keyword side does not find.
 *
 * Marker questions exercise exact terms: `STC 1000` asked, `STC-1000`
 * stored; `s181` asked, `S181` stored; the abbreviation `RVU`; the umlaut
 * term `Kühlraum`; the ß term `Straße`; and `M12` in the English
 * collection. They are natural-language questions, so the keyword side
 * only finds them because the store ORs the terms. Apart from stop words,
 * no word of a question occurs in any chunk but its target chunk, so the
 * keyword list of each question holds its target chunk alone (asserted
 * per question on the store before the runs).
 *
 * The precondition ({@see self::assertVectorSideMissesTheMarkers()})
 * recomputes the fake cosine order (md5-based vectors of
 * {@see \Dmstr\NeuronAiBundle\Testing\FakeEmbeddingsProvider}) and fails
 * when the vector side alone already finds a marker chunk at rank 1 or
 * the control chunk below rank 1: a changed fake, prefix or fixture text
 * must not turn this into a proof of nothing. Computed vector ranks over
 * the 23 chunks ({@see self::VECTOR_RANKS}): control 1; markers 11, 3, 5,
 * 19, 13, 14, so at depth 3 one marker is demoted to rank 3 and five are
 * misses. A measures recall 2/7 and MRR (1 + 1/3)/7; B finds every
 * marker.
 *
 * Embeddings are read from the fake provider factory of the current
 * kernel before the next request (every createClient() boots a new
 * kernel). Needs the migrated test database (CI only).
 */
#[Group('database')]
final class HybridRetrievalProofTest extends ApiTestCase
{
    use EvalRunTestTrait;

    protected static ?bool $alwaysBootKernel = true;

    private const string PREFIX = 'test-hybridproof-';
    private const string AGENT_SLUG = AgentFixtures::PREFIX.'hybridproof-';
    private const string COLLECTION_NAME = 'hybridproof-';

    /** Retrieval depth of both versions; below the corpus size, so the vector side can miss. */
    private const int DEPTH = 3;

    /** Minimum cosine distance of a target chunk to every other chunk, far above float4 rounding in pgvector. */
    private const float COSINE_MARGIN = 1e-5;

    /** Chunks of the `german` collection; index = corpus index. */
    private const array GERMAN_CHUNKS = [
        'Die Pumpe hat eine Nachlaufzeit von sechs Minuten.',
        'Die Steuerung STC-1000 regelt die Lagertemperatur.',
        'Das Gerät S181 meldet Störungen an die Leitwarte.',
        'Die RVU prüft freitags die Notbeleuchtung.',
        'Im Kühlraum gilt eine Höchstlast von achthundert Kilogramm pro Regal.',
        'Die Anlieferung erfolgt über die Straße hinter Halle Nord.',
        'Kantine: Mittagessen gibt es montags bis freitags ab elf Uhr.',
        'Besucher tragen sich am Empfang ins Gästebuch ein.',
        'Parkplätze für Fahrräder befinden sich neben dem Haupteingang.',
        'Urlaubsanträge reicht man spätestens vier Wochen vorher ein.',
        'Druckerpapier liegt im Schrank neben dem Kopierer.',
        'Erste-Hilfe-Kästen hängen in jedem Flur gut sichtbar.',
        'Der Betriebsausflug findet jedes Jahr im Juni statt.',
        'Schlüssel für Spinde gibt die Hausverwaltung aus.',
        'Die Kaffeemaschine wird abends von Reinigungskräften entkalkt.',
        'Feueralarmübungen finden zweimal jährlich statt.',
        'Dienstreisen bucht das Sekretariat über das Reiseportal.',
        'Telefonkonferenzen laufen über den zentralen Besprechungsraum.',
    ];

    /** Chunks of the `english` collection; corpus index = 18 + index. */
    private const array ENGLISH_CHUNKS = [
        'Tighten the M12 flange bolts with eighty newton metres.',
        'Visitors sign in at the front desk.',
        'Spare keyboards are stored in room four.',
        'The canteen opens at noon.',
        'Printer toner is ordered every month.',
    ];

    /** Per question key (without prefix): question, marker, corpus index of the only chunk containing the marker. */
    private const array QUESTIONS = [
        'a-control' => ['Wie lang ist die Nachlaufzeit bei der Pumpe?', 'Nachlaufzeit', 0],
        'b-code-space' => ['Was regelt die STC 1000?', 'STC-1000', 1],
        'c-code-lower' => ['Wohin meldet das s181 Störungen?', 'S181', 2],
        'd-abbreviation' => ['Wann prüft eine RVU?', 'RVU', 3],
        'e-umlaut' => ['Welche Höchstlast gilt im Kühlraum?', 'Kühlraum', 4],
        'f-sharp-s' => ['Über welche Straße erfolgt die Anlieferung?', 'Straße', 5],
        'g-english' => ['What torque applies to the M12 flange bolts?', 'M12', 18],
    ];

    /** The question the vector side already answers at rank 1. */
    private const string CONTROL = 'a-control';

    /** Rank of the target chunk in the fake cosine order over the whole corpus (1-based), per question key. */
    private const array VECTOR_RANKS = [
        'a-control' => 1,
        'b-code-space' => 11,
        'c-code-lower' => 3,
        'd-abbreviation' => 5,
        'e-umlaut' => 19,
        'f-sharp-s' => 13,
        'g-english' => 14,
    ];

    protected function setUp(): void
    {
        // Counts or scans all rows: start from an empty database.
        TestDatabase::migrate(static::bootKernel());
        TestDatabase::purge(TestDatabase::connection(static::$kernel));
        self::cleanUp();
        self::bootKernel();
    }

    protected function tearDown(): void
    {
        self::cleanUp();
        parent::tearDown();
    }

    public function testHybridFindsTheExactTermsTheVectorSideMissesWithoutLosingTheControl(): void
    {
        $vectorOnly = $this->assertVectorSideMissesTheMarkers();

        $german = $this->collectionIn('de', 'german');
        $english = $this->collectionIn('en', 'english');
        $this->addChunks($german, self::GERMAN_CHUNKS);
        $this->addChunks($english, self::ENGLISH_CHUNKS);
        $this->assertKeywordSideFindsEveryTarget([$german => 'german', $english => 'english']);

        $collections = ['collections' => [$german, $english], 'retrievalDepth' => self::DEPTH];
        $versionA = $this->publishedVersion('vector', $collections + ['hybrid' => false]);
        $versionB = $this->publishedVersion('hybrid', $collections + ['hybrid' => true, 'hybridWeight' => 0.5]);
        $this->persistQuestions(self::questionEntries());

        $runA = $this->startRetrievalOnly($versionA);
        self::assertSame(7, $runA['questionsTotal']);
        $this->runJob($runA['jobId']);
        $this->assertOneQueryEmbeddingPerQuestion('A');

        $runB = $this->startRetrievalOnly($versionB);
        self::assertSame(7, $runB['questionsTotal']);
        $this->runJob($runB['jobId']);
        // Keyword search embeds nothing: still one query embedding per
        // question for the one model of both collections.
        $this->assertOneQueryEmbeddingPerQuestion('B');

        $a = $this->retrievalOf($this->results($runA['id']));
        $b = $this->retrievalOf($this->results($runB['id']));

        // A measures exactly what the fake vectors predict: the store and
        // the precondition agree.
        self::assertSame($vectorOnly, $a, 'Version A differs from the fake cosine order.');

        foreach (array_keys(self::QUESTIONS) as $key) {
            [$statusA, $hitA, $rankA] = $a[$key];
            [$statusB, $hitB, $rankB] = $b[$key];
            self::assertSame([RetrievalStatus::MEASURED, RetrievalStatus::MEASURED], [$statusA, $statusB], $key);
            self::assertTrue($hitB, $key.': hybrid misses the target chunk.');

            if (self::CONTROL === $key) {
                self::assertSame([true, 1], [$hitA, $rankA], $key);
                self::assertSame(1, $rankB, $key.': hybrid regresses the control question.');

                continue;
            }

            self::assertTrue(false === $hitA || $rankA > 1, $key.': the vector side alone already ranks the marker first.');
            self::assertTrue(false === $hitA || $rankB < $rankA, \sprintf('%s: hybrid rank %d is not better than vector rank %d.', $key, $rankB, $rankA));
        }

        [$recallA, $mrrA, $measuredA] = $this->aggregates($runA['id']);
        [$recallB, $mrrB, $measuredB] = $this->aggregates($runB['id']);
        self::assertSame([7, 7], [$measuredA, $measuredB]);
        self::assertEqualsWithDelta(2 / 7, $recallA, 1e-9);
        self::assertEqualsWithDelta((1 + 1 / 3) / 7, $mrrA, 1e-9);
        self::assertNotNull($recallB);
        self::assertNotNull($mrrB);
        self::assertGreaterThanOrEqual($recallA, $recallB, 'Overall recall of B is below A.');
        self::assertGreaterThanOrEqual($mrrA, $mrrB, 'Overall MRR of B is below A.');
        self::assertEqualsWithDelta(1.0, $recallB, 1e-9, 'B finds every marker.');
    }

    // --- helpers --------------------------------------------------------

    /**
     * Precondition: recomputes the vector-only order of the corpus from the
     * fake vectors and asserts that it matches {@see self::VECTOR_RANKS},
     * that the control chunk is rank 1 and that the vector side alone
     * misses or demotes every marker chunk (rank > 1, and at least one
     * beyond {@see self::DEPTH}). Each marker must occur in its target
     * chunk only, and each target's cosine must be clear of every other
     * chunk's by {@see self::COSINE_MARGIN}. Fails instead of skipping.
     *
     * @return array<string, array{string, bool, int|null}> the measurement version A must store, per question key
     */
    private function assertVectorSideMissesTheMarkers(): array
    {
        $corpus = [...self::GERMAN_CHUNKS, ...self::ENGLISH_CHUNKS];
        $expected = [];
        $misses = 0;

        foreach (self::QUESTIONS as $key => [$question, $marker, $target]) {
            $containing = array_keys(array_filter($corpus, static fn (string $chunk): bool => false !== mb_stripos($chunk, $marker)));
            self::assertSame([$target], $containing, $key.': the marker must occur in its target chunk only.');

            $scores = $this->fakeVectorScores($question, $corpus);
            $rank = array_search($target, array_keys($scores), true);
            self::assertIsInt($rank, $key);
            ++$rank;
            self::assertSame(self::VECTOR_RANKS[$key], $rank, $key.': the pinned vector rank differs from the fake vectors.');
            foreach ($scores as $index => $score) {
                if ($index !== $target) {
                    self::assertGreaterThan(self::COSINE_MARGIN, abs($scores[$target] - $score), $key.': cosine too close to chunk '.$index.'.');
                }
            }

            if (self::CONTROL === $key) {
                self::assertSame(1, $rank, 'The control question must be a vector hit at rank 1.');
            } else {
                self::assertGreaterThan(1, $rank, $key.': the vector side alone already ranks the marker first; the proof would be vacuous.');
                if ($rank > self::DEPTH) {
                    ++$misses;
                }
            }

            $expected[$key] = $rank <= self::DEPTH
                ? [RetrievalStatus::MEASURED, true, $rank]
                : [RetrievalStatus::MEASURED, false, null];
        }

        self::assertGreaterThan(0, $misses, 'At least one marker must be a vector miss.');

        return $expected;
    }

    /**
     * The keyword list of each question in the collection of its target
     * chunk ranks that chunk first (with the candidates of hybrid search),
     * so B's advantage comes from the keyword side and not from chance.
     *
     * @param array<string, string> $languages keyword language per collection id
     */
    private function assertKeywordSideFindsEveryTarget(array $languages): void
    {
        /** @var PgVectorStore $store */
        $store = static::getContainer()->get(PgVectorStore::class);
        $scoped = $store->forModel(self::EMBEDDING_MODEL, $this->registry()->get(self::EMBEDDING_MODEL)->dimension)
            ->withTopK(CollectionRetriever::candidates(self::DEPTH));
        [$german, $english] = array_keys($languages);

        foreach (self::QUESTIONS as $key => [$question, , $target]) {
            $collection = $target < \count(self::GERMAN_CHUNKS) ? $german : $english;
            $hits = [...$scoped->withFilters(['collection_id' => $collection])->keywordSearch($question, $languages[$collection])];
            $contents = array_map(static fn (Document $document): string => $document->getContent(), $hits);
            self::assertSame([...self::GERMAN_CHUNKS, ...self::ENGLISH_CHUNKS][$target], $contents[0] ?? null, $key.': the keyword side does not rank the target first.');
        }
    }

    /**
     * A collection of {@see self::EMBEDDING_MODEL} with an explicit keyword
     * language.
     *
     * @return string the collection id
     */
    private function collectionIn(string $name, string $language): string
    {
        $collection = AgentFixtures::collection(self::COLLECTION_NAME.$name)->setKeywordLanguage($language);
        $this->entityManager()->flush();

        return $collection->getId()->toRfc4122();
    }

    /**
     * @return list<array{key: string, question: string, expectedAnswer: string, markers: list<string>}>
     */
    private static function questionEntries(): array
    {
        $entries = [];
        foreach (self::QUESTIONS as $key => [$question, $marker, $target]) {
            $entries[] = [
                'key' => $key,
                'question' => $question,
                'expectedAnswer' => [...self::GERMAN_CHUNKS, ...self::ENGLISH_CHUNKS][$target],
                'markers' => [$marker],
            ];
        }

        return $entries;
    }

    /**
     * One query embedding per question with the one model of both
     * collections, nothing else; counted before the next request.
     */
    private function assertOneQueryEmbeddingPerQuestion(string $version): void
    {
        foreach (self::QUESTIONS as $key => [$question]) {
            self::assertSame(1, $this->queryEmbeddings($question), $version.' '.$key);
        }
        self::assertCount(\count(self::QUESTIONS), $this->factory()->getEmbeddingsRecorder()->getRecorded(), $version);
    }
}
