<?php

// file generated with AI assistance: Claude Code - 2026-10-04 17:23:51 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\Evaluation;

use Dmstr\NeuronAiBundle\Entity\EvalQuestion;
use Dmstr\NeuronAiBundle\Evaluation\EvalQuestionImporter;
use Dmstr\NeuronAiBundle\Validator\EvalQuestionRetrieval;
use Dmstr\NeuronAiBundle\Validator\EvalQuestionRetrievalRules;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Validation;

/**
 * Kernel-free test of the retrieval rules shared by the import and the API:
 * {@see EvalQuestionRetrievalRules} directly, the import
 * ({@see EvalQuestionImporter::parse()}, the same message with
 * `item 0: `) and the entity constraint {@see EvalQuestionRetrieval} (the
 * same message on the right property path), including the trimming in
 * {@see EvalQuestion::setMarkers()} and the path of a conflict on a
 * question that was loaded as negative.
 */
final class EvalQuestionRetrievalRulesTest extends TestCase
{
    /**
     * Invalid marker lists the API can receive (an array), with the
     * expected problems in order.
     *
     * @return iterable<string, array{0: array<array-key, mixed>, 1: list<string>}>
     */
    public static function invalidMarkerLists(): iterable
    {
        yield 'empty list' => [[], ['"markers" must not be an empty list (omit it or use null for no markers).']];
        yield '51 entries' => [array_map(static fn (int $i): string => 'marker '.$i, range(1, 51)), ['"markers" must not have more than 50 entries (51 given).']];
        yield '201 characters' => [['ok', str_repeat('x', 201)], ['"markers" entry 1 must not be longer than 200 characters.']];
        yield '201 multibyte characters' => [[str_repeat('ä', 201)], ['"markers" entry 0 must not be longer than 200 characters.']];
        yield 'whitespace only' => [['a', "  \t\n"], ['"markers" entry 1 must be a non-empty string.']];
        yield 'empty string' => [[''], ['"markers" entry 0 must be a non-empty string.']];
        yield 'duplicate after trimming' => [['Hydraulik', 'Pumpe', ' Hydraulik '], ['"markers" entry 2 duplicates entry 0 ("Hydraulik").']];
        yield 'non-string entries' => [['a', 1, null, ['b'], true], [
            '"markers" entry 1 must be a non-empty string.',
            '"markers" entry 2 must be a non-empty string.',
            '"markers" entry 3 must be a non-empty string.',
            '"markers" entry 4 must be a non-empty string.',
        ]];
        yield 'several problems' => [array_merge(['x', ' x ', str_repeat('y', 201)], array_fill(0, 48, 'z')), [
            '"markers" must not have more than 50 entries (51 given).',
            '"markers" entry 1 duplicates entry 0 ("x").',
            '"markers" entry 2 must not be longer than 200 characters.',
            ...array_map(static fn (int $i): string => \sprintf('"markers" entry %d duplicates entry 3 ("z").', $i), range(4, 50)),
        ]];
    }

    /**
     * @param array<array-key, mixed> $markers
     * @param list<string>            $expected
     */
    #[DataProvider('invalidMarkerLists')]
    public function testInvalidMarkersHaveTheSameMessagesInRulesImportAndApi(array $markers, array $expected): void
    {
        self::assertSame([null, $expected], EvalQuestionRetrievalRules::markers($markers));

        [$items, $problems] = EvalQuestionImporter::parse(self::importItem(['markers' => $markers]));
        self::assertSame([], $items);
        self::assertSame(array_map(static fn (string $message): string => 'item 0: '.$message, $expected), $problems);

        $violations = self::validate((new EvalQuestion())->setMarkers($markers));
        self::assertSame($expected, self::messages($violations));
        self::assertSame(array_fill(0, \count($expected), 'markers'), self::paths($violations));
        self::assertSame(array_fill(0, \count($expected), EvalQuestionRetrieval::INVALID_MARKERS_ERROR), self::codes($violations));
    }

    /**
     * @return iterable<string, array{0: mixed}>
     */
    public static function nonListMarkers(): iterable
    {
        yield 'string' => ['Hydraulikpumpe'];
        yield 'number' => [5];
        yield 'object' => [new \stdClass()];
        yield 'map' => [['a' => 'x']];
        yield 'list with a gap' => [[0 => 'x', 2 => 'y']];
    }

    #[DataProvider('nonListMarkers')]
    public function testMarkersThatAreNoListAreRejected(mixed $markers): void
    {
        self::assertSame([null, ['"markers" must be a list of strings or null.']], EvalQuestionRetrievalRules::markers($markers));
    }

    public function testAMapIsRejectedByTheConstraint(): void
    {
        $violations = self::validate((new EvalQuestion())->setMarkers(['a' => 'x']));

        self::assertSame(['"markers" must be a list of strings or null.'], self::messages($violations));
        self::assertSame(['markers'], self::paths($violations));
    }

    public function testValidMarkersAreTrimmed(): void
    {
        $markers = [' Hydraulikpumpe ', "\tÖlwechsel\n", str_repeat('ä', 200)];
        $trimmed = ['Hydraulikpumpe', 'Ölwechsel', str_repeat('ä', 200)];

        self::assertSame([$trimmed, []], EvalQuestionRetrievalRules::markers($markers));
        self::assertSame($trimmed, EvalQuestionRetrievalRules::trimMarkers($markers));

        [$items, $problems] = EvalQuestionImporter::parse(self::importItem(['markers' => $markers]));
        self::assertSame([], $problems);
        self::assertSame($trimmed, $items[0]['markers']);

        $question = (new EvalQuestion())->setMarkers($markers);
        self::assertSame($trimmed, $question->getMarkers());
        self::assertCount(0, self::validate($question));
    }

    public function testFiftyMarkersAndNullAreValid(): void
    {
        $fifty = array_map(static fn (int $i): string => 'marker '.$i, range(1, 50));

        self::assertSame([$fifty, []], EvalQuestionRetrievalRules::markers($fifty));
        self::assertSame([null, []], EvalQuestionRetrievalRules::markers(null));
        self::assertNull(EvalQuestionRetrievalRules::trimMarkers(null));
        self::assertCount(0, self::validate((new EvalQuestion())->setMarkers($fifty)));
        self::assertCount(0, self::validate((new EvalQuestion())->setMarkers(null)->setNegative(true)));
    }

    public function testTrimmingKeepsInvalidEntriesAtTheirIndex(): void
    {
        self::assertSame(['a', 1, null, '', ['b']], EvalQuestionRetrievalRules::trimMarkers([' a ', 1, null, '  ', ['b']]));
    }

    /**
     * @return iterable<string, array{0: mixed}>
     */
    public static function nonBooleans(): iterable
    {
        yield 'string' => ['yes'];
        yield 'string true' => ['true'];
        yield 'number' => [1];
        yield 'zero' => [0];
        yield 'null' => [null];
    }

    #[DataProvider('nonBooleans')]
    public function testNegativeMustBeABoolean(mixed $negative): void
    {
        self::assertSame('"negative" must be a boolean.', EvalQuestionRetrievalRules::negative($negative));

        [, $problems] = EvalQuestionImporter::parse(self::importItem(['negative' => $negative]));
        self::assertSame(['item 0: "negative" must be a boolean.'], $problems);
    }

    public function testBooleansAreValidNegatives(): void
    {
        self::assertNull(EvalQuestionRetrievalRules::negative(true));
        self::assertNull(EvalQuestionRetrievalRules::negative(false));
    }

    public function testConflictOfNegativeAndMarkers(): void
    {
        $message = '"negative": true cannot be combined with "markers" (a negative question expects no hits).';

        self::assertSame($message, EvalQuestionRetrievalRules::conflict(true, ['x']));
        self::assertSame($message, EvalQuestionRetrievalRules::conflict(true, []));
        self::assertNull(EvalQuestionRetrievalRules::conflict(true, null));
        self::assertNull(EvalQuestionRetrievalRules::conflict(false, ['x']));
        self::assertNull(EvalQuestionRetrievalRules::conflict(null, ['x']));
        self::assertNull(EvalQuestionRetrievalRules::conflict('yes', ['x']));

        [, $problems] = EvalQuestionImporter::parse(self::importItem(['negative' => true, 'markers' => ['x']]));
        self::assertSame(['item 0: '.$message], $problems);
    }

    public function testConflictOnANewQuestionPointsAtNegative(): void
    {
        $violations = self::validate((new EvalQuestion())->setMarkers(['x'])->setNegative(true));

        self::assertSame(['"negative": true cannot be combined with "markers" (a negative question expects no hits).'], self::messages($violations));
        self::assertSame(['negative'], self::paths($violations));
        self::assertSame([EvalQuestionRetrieval::NEGATIVE_WITH_MARKERS_ERROR], self::codes($violations));
    }

    public function testNegativeOnAStoredQuestionWithMarkersPointsAtNegative(): void
    {
        $question = (new EvalQuestion())->setMarkers(['x'])->setNegative(false);
        $question->onPostLoad();
        self::assertFalse($question->wasLoadedNegative());

        $question->setNegative(true);

        self::assertSame(['negative'], self::paths(self::validate($question)));
    }

    public function testMarkersOnAStoredNegativeQuestionPointAtMarkers(): void
    {
        $question = (new EvalQuestion())->setNegative(true);
        $question->onPostLoad();
        self::assertTrue($question->wasLoadedNegative());

        $question->setMarkers(['x']);

        $violations = self::validate($question);
        self::assertSame(['"negative": true cannot be combined with "markers" (a negative question expects no hits).'], self::messages($violations));
        self::assertSame(['markers'], self::paths($violations));
    }

    public function testEmptyMarkersWithNegativeReportBothProblemsLikeTheImport(): void
    {
        $violations = self::validate((new EvalQuestion())->setMarkers([])->setNegative(true));
        [, $problems] = EvalQuestionImporter::parse(self::importItem(['negative' => true, 'markers' => []]));

        self::assertSame(array_map(static fn (string $message): string => 'item 0: '.$message, self::messages($violations)), $problems);
        self::assertSame(['markers', 'negative'], self::paths($violations));
    }

    public function testImporterLimitsAreTheSharedOnes(): void
    {
        self::assertSame(EvalQuestionRetrievalRules::MAX_MARKERS, EvalQuestionImporter::MAX_MARKERS);
        self::assertSame(EvalQuestionRetrievalRules::MAX_MARKER_LENGTH, EvalQuestionImporter::MAX_MARKER_LENGTH);
        self::assertSame(50, EvalQuestionRetrievalRules::MAX_MARKERS);
        self::assertSame(200, EvalQuestionRetrievalRules::MAX_MARKER_LENGTH);
    }

    /**
     * One import document with a single otherwise valid item.
     *
     * @param array<string, mixed> $fields
     */
    private static function importItem(array $fields): string
    {
        return json_encode([['key' => 'a', 'question' => 'q', 'expectedAnswer' => 'e'] + $fields], \JSON_THROW_ON_ERROR);
    }

    /**
     * Validates only the retrieval constraint (the entity's other
     * constraints, e.g. UniqueEntity, need the container).
     */
    private static function validate(EvalQuestion $question): ConstraintViolationListInterface
    {
        return Validation::createValidator()->validate($question, new EvalQuestionRetrieval());
    }

    /**
     * @return list<string>
     */
    private static function messages(ConstraintViolationListInterface $violations): array
    {
        $messages = [];
        foreach ($violations as $violation) {
            $messages[] = (string) $violation->getMessage();
        }

        return $messages;
    }

    /**
     * @return list<string>
     */
    private static function paths(ConstraintViolationListInterface $violations): array
    {
        $paths = [];
        foreach ($violations as $violation) {
            $paths[] = $violation->getPropertyPath();
        }

        return $paths;
    }

    /**
     * @return list<string|null>
     */
    private static function codes(ConstraintViolationListInterface $violations): array
    {
        $codes = [];
        foreach ($violations as $violation) {
            $codes[] = $violation->getCode();
        }

        return $codes;
    }
}
