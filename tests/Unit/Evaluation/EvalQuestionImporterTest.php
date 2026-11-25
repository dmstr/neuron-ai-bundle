<?php

// file generated with AI assistance: Claude Code - 2026-10-03 19:07:47 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\Evaluation;

use Dmstr\NeuronAiBundle\Entity\EvalQuestion;
use Dmstr\NeuronAiBundle\Evaluation\EvalQuestionImporter;
use Dmstr\NeuronAiBundle\Evaluation\EvalQuestionImportReport;
use Dmstr\NeuronAiBundle\Evaluation\EvalQuestionStatus;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Kernel-free test of the import of evaluation questions: the
 * validation of the whole file (every problem with its item index, nothing
 * written) and the upsert by key against an in-memory repository (created,
 * updated, unchanged; a missing status keeps the stored one). The
 * error matrix of `markers` and `negative`, every problem of a file
 * reported at once, and their create/update/unchanged behaviour (the file
 * is authoritative: a missing field resets to null/false).
 */
final class EvalQuestionImporterTest extends TestCase
{
    /** @var list<EvalQuestion> persisted by the importer */
    private array $persisted = [];

    private int $flushes = 0;

    public function testFixtureFileIsValid(): void
    {
        [$items, $problems] = EvalQuestionImporter::parse((string) file_get_contents(__DIR__.'/../../Fixtures/evaluation/questions.json'));

        self::assertSame([], $problems);
        self::assertCount(4, $items);
        self::assertNull($items[0]['status']);
        self::assertNull($items[0]['requestData']);
        self::assertSame(['vehicle' => 'Typ 4', 'axles' => 2], $items[2]['requestData']);
        self::assertSame(EvalQuestionStatus::ARCHIVED, $items[3]['status']);
    }

    /**
     * @return iterable<string, array{0: string, 1: list<string>}>
     */
    public static function invalidDocuments(): iterable
    {
        yield 'syntax error' => ['[{"key": ', ['Invalid JSON: Syntax error.']];
        yield 'object instead of a list' => ['{"key": "a"}', ['The file must contain a JSON list of question objects.']];
        yield 'scalar' => ['"a"', ['The file must contain a JSON list of question objects.']];
        yield 'item not an object' => ['[1]', ['item 0: must be an object.']];
        yield 'missing fields' => ['[{"key": "a"}]', [
            'item 0: missing property "question".',
            'item 0: missing property "expectedAnswer".',
        ]];
        yield 'wrong types' => ['[{"key": 1, "question": "", "expectedAnswer": ["x"]}]', [
            'item 0: "key" must be a non-empty string.',
            'item 0: "question" must be a non-empty string.',
            'item 0: "expectedAnswer" must be a non-empty string.',
        ]];
        yield 'unknown property' => ['[{"key": "a", "question": "q", "expectedAnswer": "e", "retrievalOnly": true}]', [
            'item 0: unknown property "retrievalOnly".',
        ]];
        yield 'invalid key' => ['[{"key": "Upper Case", "question": "q", "expectedAnswer": "e"}]', [
            'item 0: key "Upper Case" must match /^[a-z0-9][a-z0-9._-]{0,99}$/ (lowercase letters, digits, ".", "_" and "-", at most 100 characters, starting with a letter or digit).',
        ]];
        yield 'invalid status' => ['[{"key": "a", "question": "q", "expectedAnswer": "e", "status": "deleted"}]', [
            'item 0: "status" must be one of "active", "archived".',
        ]];
        yield 'null status' => ['[{"key": "a", "question": "q", "expectedAnswer": "e", "status": null}]', [
            'item 0: "status" must be one of "active", "archived".',
        ]];
        yield 'requestData list' => ['[{"key": "a", "question": "q", "expectedAnswer": "e", "requestData": [1]}]', [
            'item 0: "requestData" must be an object or null.',
        ]];
    }

    /**
     * Invalid `markers` and `negative` values of a single otherwise valid
     * item, with the expected problems.
     *
     * @return iterable<string, array{0: string, 1: list<string>}>
     */
    public static function invalidRetrievalFields(): iterable
    {
        $item = static fn (array $fields): string => json_encode([['key' => 'a', 'question' => 'q', 'expectedAnswer' => 'e'] + $fields], \JSON_THROW_ON_ERROR);

        yield 'markers string' => [$item(['markers' => 'Hydraulikpumpe']), [
            'item 0: "markers" must be a list of strings or null.',
        ]];
        yield 'markers number' => [$item(['markers' => 5]), [
            'item 0: "markers" must be a list of strings or null.',
        ]];
        yield 'markers object' => ['[{"key": "a", "question": "q", "expectedAnswer": "e", "markers": {"0": "x"}}]', [
            'item 0: "markers" must be a list of strings or null.',
        ]];
        yield 'markers empty list' => [$item(['markers' => []]), [
            'item 0: "markers" must not be an empty list (omit it or use null for no markers).',
        ]];
        yield 'markers non-string entries' => [$item(['markers' => ['a', 1, null, ['b'], true]]), [
            'item 0: "markers" entry 1 must be a non-empty string.',
            'item 0: "markers" entry 2 must be a non-empty string.',
            'item 0: "markers" entry 3 must be a non-empty string.',
            'item 0: "markers" entry 4 must be a non-empty string.',
        ]];
        yield 'markers empty and whitespace entries' => [$item(['markers' => ['', '   ', "\t\n", 'a']]), [
            'item 0: "markers" entry 0 must be a non-empty string.',
            'item 0: "markers" entry 1 must be a non-empty string.',
            'item 0: "markers" entry 2 must be a non-empty string.',
        ]];
        yield 'markers duplicate after trimming' => [$item(['markers' => ['Hydraulik', 'Pumpe', ' Hydraulik ']]), [
            'item 0: "markers" entry 2 duplicates entry 0 ("Hydraulik").',
        ]];
        yield 'marker longer than 200 characters' => [$item(['markers' => [str_repeat('x', 201)]]), [
            'item 0: "markers" entry 0 must not be longer than 200 characters.',
        ]];
        yield 'marker of 201 multibyte characters' => [$item(['markers' => [str_repeat('ä', 201)]]), [
            'item 0: "markers" entry 0 must not be longer than 200 characters.',
        ]];
        yield 'more than 50 markers' => [$item(['markers' => array_map(static fn (int $i): string => 'marker '.$i, range(1, 51))]), [
            'item 0: "markers" must not have more than 50 entries (51 given).',
        ]];
        yield 'negative string' => [$item(['negative' => 'yes']), [
            'item 0: "negative" must be a boolean.',
        ]];
        yield 'negative number' => [$item(['negative' => 1]), [
            'item 0: "negative" must be a boolean.',
        ]];
        yield 'negative null' => [$item(['negative' => null]), [
            'item 0: "negative" must be a boolean.',
        ]];
        yield 'negative with markers' => [$item(['negative' => true, 'markers' => ['Hydraulikpumpe']]), [
            'item 0: "negative": true cannot be combined with "markers" (a negative question expects no hits).',
        ]];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('invalidRetrievalFields')]
    public function testInvalidRetrievalFieldsReportEveryProblemAndWriteNothing(string $json, array $expected): void
    {
        $report = $this->importer([], expectWrites: false)->import($json);

        self::assertFalse($report->isValid());
        self::assertSame($expected, $report->problems);
        self::assertSame([], $this->persisted);
    }

    public function testEveryProblemOfOneFileIsReportedTogether(): void
    {
        $json = json_encode([
            ['key' => 'a', 'question' => 'q', 'expectedAnswer' => 'e', 'markers' => []],
            ['key' => 'b', 'question' => 'q', 'expectedAnswer' => 'e', 'markers' => ['x', ' x ', str_repeat('y', 201)], 'negative' => 'no'],
            ['key' => 'c', 'question' => 'q', 'expectedAnswer' => 'e', 'markers' => ['ok']],
            ['key' => 'd', 'question' => 'q', 'expectedAnswer' => 'e', 'negative' => true, 'markers' => ['z'], 'status' => 'gone'],
            ['key' => 'e', 'question' => 'q', 'expectedAnswer' => 'e', 'markers' => 'z', 'retrievalOnly' => true],
        ], \JSON_THROW_ON_ERROR);

        $report = $this->importer([], expectWrites: false)->import($json);

        self::assertSame([
            'item 0: "markers" must not be an empty list (omit it or use null for no markers).',
            'item 1: "markers" entry 1 duplicates entry 0 ("x").',
            'item 1: "markers" entry 2 must not be longer than 200 characters.',
            'item 1: "negative" must be a boolean.',
            'item 3: "status" must be one of "active", "archived".',
            'item 3: "negative": true cannot be combined with "markers" (a negative question expects no hits).',
            'item 4: unknown property "retrievalOnly".',
            'item 4: "markers" must be a list of strings or null.',
        ], $report->problems);
        self::assertSame([], $this->persisted);
    }

    public function testValidRetrievalFieldsAreNormalised(): void
    {
        $fifty = array_map(static fn (int $i): string => 'marker '.$i, range(1, 50));
        $json = json_encode([
            ['key' => 'a', 'question' => 'q', 'expectedAnswer' => 'e', 'markers' => ['  Hydraulikpumpe ', '500 h', ' '.str_repeat('ä', 200).' ']],
            ['key' => 'b', 'question' => 'q', 'expectedAnswer' => 'e', 'markers' => null, 'negative' => true],
            ['key' => 'c', 'question' => 'q', 'expectedAnswer' => 'e', 'markers' => $fifty, 'negative' => false],
            ['key' => 'd', 'question' => 'q', 'expectedAnswer' => 'e'],
            // markers that differ only in case are distinct
            ['key' => 'e', 'question' => 'q', 'expectedAnswer' => 'e', 'markers' => ['Pumpe', 'pumpe']],
        ], \JSON_THROW_ON_ERROR);

        [$items, $problems] = EvalQuestionImporter::parse($json);

        self::assertSame([], $problems);
        self::assertSame(['Hydraulikpumpe', '500 h', str_repeat('ä', 200)], $items[0]['markers']);
        self::assertFalse($items[0]['negative']);
        self::assertNull($items[1]['markers']);
        self::assertTrue($items[1]['negative']);
        self::assertSame($fifty, $items[2]['markers']);
        self::assertFalse($items[2]['negative']);
        self::assertNull($items[3]['markers']);
        self::assertFalse($items[3]['negative']);
        self::assertSame(['Pumpe', 'pumpe'], $items[4]['markers']);
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('invalidDocuments')]
    public function testInvalidDocumentsReportEveryProblemAndWriteNothing(string $json, array $expected): void
    {
        $report = $this->importer([], expectWrites: false)->import($json);

        self::assertFalse($report->isValid());
        self::assertSame($expected, $report->problems);
        self::assertSame(0, $report->created + $report->updated + $report->unchanged);
    }

    public function testDuplicateKeyInOneFileFailsTheWholeFile(): void
    {
        $json = json_encode([
            ['key' => 'a', 'question' => 'q1', 'expectedAnswer' => 'e1'],
            ['key' => 'b', 'question' => 'q2', 'expectedAnswer' => 'e2'],
            ['key' => 'a', 'question' => 'q3', 'expectedAnswer' => 'e3'],
            ['key' => 'c', 'question' => 'q4', 'expectedAnswer' => 'e4', 'status' => 'gone'],
        ], \JSON_THROW_ON_ERROR);

        $report = $this->importer([], expectWrites: false)->import($json);

        self::assertSame([
            'item 2: duplicate key "a" (first used by item 0).',
            'item 3: "status" must be one of "active", "archived".',
        ], $report->problems);
        self::assertSame([], $this->persisted);
    }

    public function testEmptyListImportsNothing(): void
    {
        $report = $this->importer([], expectWrites: false)->import('[]');

        self::assertTrue($report->isValid());
        self::assertSame('created: 0, updated: 0, unchanged: 0', (string) $report);
    }

    public function testCreatesMissingQuestions(): void
    {
        $report = $this->importer([])->import((string) file_get_contents(__DIR__.'/../../Fixtures/evaluation/questions.json'));

        self::assertSame('created: 4, updated: 0, unchanged: 0', (string) $report);
        self::assertSame(1, $this->flushes);
        self::assertSame(
            ['test-eval.maintenance-interval', 'test-eval.safety-training', 'test-eval.wheel-nut-torque', 'test-eval.warranty'],
            array_map(static fn (EvalQuestion $question): string => $question->getKey(), $this->persisted),
        );
        self::assertSame(EvalQuestionStatus::ACTIVE, $this->persisted[0]->getStatus());
        self::assertSame(EvalQuestionStatus::ARCHIVED, $this->persisted[3]->getStatus());
        self::assertSame(['vehicle' => 'Typ 4', 'axles' => 2], $this->persisted[2]->getRequestData());
    }

    public function testCreatesQuestionsWithMarkersAndNegative(): void
    {
        $report = $this->importer([])->import((string) file_get_contents(__DIR__.'/../../Fixtures/evaluation/retrieval-import-questions.json'));

        self::assertSame('created: 3, updated: 0, unchanged: 0', (string) $report);
        self::assertSame(['500 Betriebsstunden', 'Hydraulikpumpe'], $this->persisted[0]->getMarkers());
        self::assertFalse($this->persisted[0]->isNegative());
        self::assertNull($this->persisted[1]->getMarkers());
        self::assertTrue($this->persisted[1]->isNegative());
        self::assertNull($this->persisted[2]->getMarkers());
        self::assertFalse($this->persisted[2]->isNegative());
    }

    public function testRetrievalFieldsCountUpdatedAndUnchanged(): void
    {
        $same = self::question('same', 'q', 'e', null, EvalQuestionStatus::ACTIVE)->setMarkers(['a', 'b'])->setNegative(false);
        $sameNegative = self::question('same-negative', 'q', 'e', null, EvalQuestionStatus::ACTIVE)->setNegative(true);
        // stored before `negative` existed: null equals false
        $legacy = self::question('legacy', 'q', 'e', null, EvalQuestionStatus::ACTIVE);
        $reordered = self::question('reordered', 'q', 'e', null, EvalQuestionStatus::ACTIVE)->setMarkers(['a', 'b'])->setNegative(false);
        $markersAdded = self::question('markers-added', 'q', 'e', null, EvalQuestionStatus::ACTIVE)->setNegative(false);
        $markersDropped = self::question('markers-dropped', 'q', 'e', null, EvalQuestionStatus::ACTIVE)->setMarkers(['a'])->setNegative(false);
        $negativeDropped = self::question('negative-dropped', 'q', 'e', null, EvalQuestionStatus::ACTIVE)->setNegative(true);
        $negativeSet = self::question('negative-set', 'q', 'e', null, EvalQuestionStatus::ACTIVE)->setNegative(false);

        $json = json_encode([
            // trimmed markers equal the stored ones
            ['key' => 'same', 'question' => 'q', 'expectedAnswer' => 'e', 'markers' => [' a', 'b '], 'negative' => false],
            ['key' => 'same-negative', 'question' => 'q', 'expectedAnswer' => 'e', 'negative' => true],
            ['key' => 'legacy', 'question' => 'q', 'expectedAnswer' => 'e'],
            // the order of markers is part of the value
            ['key' => 'reordered', 'question' => 'q', 'expectedAnswer' => 'e', 'markers' => ['b', 'a']],
            ['key' => 'markers-added', 'question' => 'q', 'expectedAnswer' => 'e', 'markers' => ['a']],
            // missing markers: reset to null
            ['key' => 'markers-dropped', 'question' => 'q', 'expectedAnswer' => 'e'],
            // missing negative: reset to false
            ['key' => 'negative-dropped', 'question' => 'q', 'expectedAnswer' => 'e'],
            ['key' => 'negative-set', 'question' => 'q', 'expectedAnswer' => 'e', 'negative' => true],
        ], \JSON_THROW_ON_ERROR);

        $report = $this->importer([$same, $sameNegative, $legacy, $reordered, $markersAdded, $markersDropped, $negativeDropped, $negativeSet])->import($json);

        self::assertSame('created: 0, updated: 5, unchanged: 3', (string) $report);
        self::assertSame(['a', 'b'], $same->getMarkers());
        self::assertTrue($sameNegative->isNegative());
        self::assertNull($legacy->getMarkers());
        self::assertNull($legacy->isNegative());
        self::assertSame(['b', 'a'], $reordered->getMarkers());
        self::assertSame(['a'], $markersAdded->getMarkers());
        self::assertNull($markersDropped->getMarkers());
        self::assertFalse($negativeDropped->isNegative());
        self::assertTrue($negativeSet->isNegative());
        self::assertSame([], $this->persisted);
        self::assertSame(1, $this->flushes);
    }

    public function testUpsertCountsUpdatedAndUnchanged(): void
    {
        $same = self::question('same', 'q', 'e', ['b' => 2, 'a' => 1], EvalQuestionStatus::ACTIVE);
        $archived = self::question('archived', 'q', 'e', null, EvalQuestionStatus::ARCHIVED);
        $changed = self::question('changed', 'old question', 'e', null, EvalQuestionStatus::ACTIVE);
        $reactivated = self::question('reactivated', 'q', 'e', null, EvalQuestionStatus::ARCHIVED);

        $json = json_encode([
            // key order of requestData differs from the stored value
            ['key' => 'same', 'question' => 'q', 'expectedAnswer' => 'e', 'requestData' => ['a' => 1, 'b' => 2]],
            // no status: the stored `archived` is kept
            ['key' => 'archived', 'question' => 'q', 'expectedAnswer' => 'e'],
            ['key' => 'changed', 'question' => 'new question', 'expectedAnswer' => 'e'],
            ['key' => 'reactivated', 'question' => 'q', 'expectedAnswer' => 'e', 'status' => 'active'],
            ['key' => 'new', 'question' => 'q', 'expectedAnswer' => 'e'],
        ], \JSON_THROW_ON_ERROR);

        $report = $this->importer([$same, $archived, $changed, $reactivated])->import($json);

        self::assertInstanceOf(EvalQuestionImportReport::class, $report);
        self::assertSame([1, 2, 2], [$report->created, $report->updated, $report->unchanged]);
        self::assertSame(['b' => 2, 'a' => 1], $same->getRequestData());
        self::assertSame(EvalQuestionStatus::ARCHIVED, $archived->getStatus());
        self::assertSame('new question', $changed->getQuestion());
        self::assertSame(EvalQuestionStatus::ACTIVE, $reactivated->getStatus());
        self::assertCount(1, $this->persisted);
        self::assertSame('new', $this->persisted[0]->getKey());
        self::assertSame(1, $this->flushes);
    }

    public function testMissingRequestDataClearsAStoredValue(): void
    {
        $question = self::question('a', 'q', 'e', ['x' => 1], EvalQuestionStatus::ACTIVE);

        $report = $this->importer([$question])->import('[{"key": "a", "question": "q", "expectedAnswer": "e"}]');

        self::assertSame('created: 0, updated: 1, unchanged: 0', (string) $report);
        self::assertNull($question->getRequestData());
    }

    /**
     * @param list<EvalQuestion> $stored questions the repository knows
     */
    private function importer(array $stored, bool $expectWrites = true): EvalQuestionImporter
    {
        $repository = self::createStub(EntityRepository::class);
        $repository->method('findBy')->willReturnCallback(
            static fn (array $criteria): array => array_values(array_filter(
                $stored,
                static fn (EvalQuestion $question): bool => \in_array($question->getKey(), (array) $criteria['key'], true),
            )),
        );

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturnCallback(static function (string $class) use ($repository): EntityRepository {
            self::assertSame(EvalQuestion::class, $class);

            return $repository;
        });
        $entityManager->expects($expectWrites ? self::once() : self::never())
            ->method('wrapInTransaction')
            ->willReturnCallback(static fn (callable $action): mixed => $action($entityManager));
        $entityManager->method('persist')->willReturnCallback(function (object $entity): void {
            self::assertInstanceOf(EvalQuestion::class, $entity);
            $this->persisted[] = $entity;
        });
        $entityManager->method('flush')->willReturnCallback(function (): void {
            ++$this->flushes;
        });

        return new EvalQuestionImporter($entityManager);
    }

    /**
     * @param array<string, mixed>|null $requestData
     */
    private static function question(string $key, string $question, string $expectedAnswer, ?array $requestData, string $status): EvalQuestion
    {
        return (new EvalQuestion())
            ->setKey($key)
            ->setQuestion($question)
            ->setExpectedAnswer($expectedAnswer)
            ->setRequestData($requestData)
            ->setStatus($status);
    }
}
