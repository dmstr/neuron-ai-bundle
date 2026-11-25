<?php

// file generated with AI assistance: Claude Code - 2026-10-03 19:07:47 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Evaluation;

use Dmstr\NeuronAiBundle\Entity\EvalQuestion;
use Dmstr\NeuronAiBundle\Validator\EvalQuestionRetrievalRules;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Imports evaluation questions from a JSON file: a list of objects `{"key", "question",
 * "expectedAnswer", "requestData"?, "status"?, "markers"?, "negative"?}`.
 *
 * The whole file is validated first: JSON syntax, the list shape, required
 * fields and their types, the key pattern of {@see EvalQuestion::KEY_PATTERN},
 * the status values of {@see EvalQuestionStatus}, unknown properties,
 * duplicate keys within the file and the retrieval fields with the rules
 * of {@see EvalQuestionRetrievalRules}, which the API applies as well:
 *  - `markers`: null or a non-empty list of at most
 *    {@see self::MAX_MARKERS} strings, each non-empty after trimming, at
 *    most {@see self::MAX_MARKER_LENGTH} characters and unique after
 *    trimming; the trimmed strings are stored;
 *  - `negative`: a boolean; `true` together with markers is an error (a
 *    question that should find nothing has nothing to look for).
 * Any problem makes the import return every problem (with the zero-based
 * index of the item) and write nothing.
 *
 * A valid file is upserted by `key` in one transaction with one flush:
 * missing questions are created, changed fields (`question`,
 * `expectedAnswer`, `requestData`, `status`, `markers`, `negative`) are
 * updated in place, the rest is counted as unchanged. The file is
 * authoritative for `requestData`, `markers` and `negative`: a missing
 * `requestData` or `markers` means null, a missing `negative` means false
 * (a stored null, from questions imported before the
 * retrieval fields existed, equals false and
 * is left as it is). A missing `status` means `active` for new questions
 * and leaves the status of existing ones unchanged. Questions missing from
 * the file are left alone (archive them explicitly with
 * `"status": "archived"`).
 *
 * Evaluation questions are not audited (no Gedmo loggable), so the import
 * needs no audit actor.
 */
final class EvalQuestionImporter
{
    private const array REQUIRED = ['key', 'question', 'expectedAnswer'];
    private const array ALLOWED = ['key', 'question', 'expectedAnswer', 'requestData', 'status', 'markers', 'negative'];

    /** Maximum number of markers of one question. */
    public const int MAX_MARKERS = EvalQuestionRetrievalRules::MAX_MARKERS;

    /** Maximum length of one marker in characters, after trimming. */
    public const int MAX_MARKER_LENGTH = EvalQuestionRetrievalRules::MAX_MARKER_LENGTH;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Validates and upserts the questions of a JSON document.
     */
    public function import(string $json): EvalQuestionImportReport
    {
        [$items, $problems] = self::parse($json);
        if ([] !== $problems) {
            return EvalQuestionImportReport::invalid($problems);
        }
        if ([] === $items) {
            return new EvalQuestionImportReport();
        }

        return $this->entityManager->wrapInTransaction(function () use ($items): EvalQuestionImportReport {
            $existing = [];
            $found = $this->entityManager->getRepository(EvalQuestion::class)
                ->findBy(['key' => array_column($items, 'key')]);
            foreach ($found as $question) {
                $existing[$question->getKey()] = $question;
            }

            $created = $updated = $unchanged = 0;
            foreach ($items as $item) {
                $question = $existing[$item['key']] ?? null;
                if (null === $question) {
                    $question = (new EvalQuestion())
                        ->setKey($item['key'])
                        ->setQuestion($item['question'])
                        ->setExpectedAnswer($item['expectedAnswer'])
                        ->setRequestData($item['requestData'])
                        ->setStatus($item['status'] ?? EvalQuestionStatus::ACTIVE)
                        ->setMarkers($item['markers'])
                        ->setNegative($item['negative']);
                    $this->entityManager->persist($question);
                    ++$created;
                    continue;
                }

                if (self::apply($question, $item)) {
                    ++$updated;
                } else {
                    ++$unchanged;
                }
            }

            $this->entityManager->flush();

            return new EvalQuestionImportReport($created, $updated, $unchanged);
        });
    }

    /**
     * Validates the document and normalises its items: the items (empty
     * when there is any problem) and every problem found.
     *
     * @return array{0: list<array{key: string, question: string, expectedAnswer: string, requestData: array<array-key, mixed>|null, status: string|null, markers: list<string>|null, negative: bool}>, 1: list<string>}
     */
    public static function parse(string $json): array
    {
        try {
            // Objects decoded as \stdClass tell `{}` from `[]` for the shape
            // checks; the associative decoding supplies the stored values.
            $tree = json_decode($json, false, 512, \JSON_THROW_ON_ERROR);
            $values = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            return [[], ['Invalid JSON: '.$exception->getMessage().'.']];
        }

        if (!\is_array($tree) || !\is_array($values)) {
            return [[], ['The file must contain a JSON list of question objects.']];
        }

        $items = [];
        $problems = [];
        $firstIndexOfKey = [];
        foreach ($tree as $index => $entry) {
            $prefix = \sprintf('item %d', $index);
            if (!$entry instanceof \stdClass) {
                $problems[] = $prefix.': must be an object.';
                continue;
            }

            $itemProblems = [];
            $unknown = array_diff(array_keys(get_object_vars($entry)), self::ALLOWED);
            foreach ($unknown as $property) {
                $itemProblems[] = \sprintf('%s: unknown property "%s".', $prefix, $property);
            }
            foreach (self::REQUIRED as $property) {
                if (!property_exists($entry, $property)) {
                    $itemProblems[] = \sprintf('%s: missing property "%s".', $prefix, $property);
                } elseif (!\is_string($entry->{$property}) || '' === trim($entry->{$property})) {
                    $itemProblems[] = \sprintf('%s: "%s" must be a non-empty string.', $prefix, $property);
                }
            }

            $key = $entry->key ?? null;
            if (\is_string($key) && '' !== trim($key)) {
                if (1 !== preg_match(EvalQuestion::KEY_PATTERN, $key)) {
                    $itemProblems[] = \sprintf('%s: key "%s" must match %s (lowercase letters, digits, ".", "_" and "-", at most %d characters, starting with a letter or digit).', $prefix, $key, EvalQuestion::KEY_PATTERN, EvalQuestion::MAX_KEY_LENGTH);
                } elseif (isset($firstIndexOfKey[$key])) {
                    $itemProblems[] = \sprintf('%s: duplicate key "%s" (first used by item %d).', $prefix, $key, $firstIndexOfKey[$key]);
                } else {
                    $firstIndexOfKey[$key] = $index;
                }
            }

            if (property_exists($entry, 'requestData') && null !== $entry->requestData && !$entry->requestData instanceof \stdClass) {
                $itemProblems[] = \sprintf('%s: "requestData" must be an object or null.', $prefix);
            }

            $status = $entry->status ?? null;
            if (property_exists($entry, 'status') && (!\is_string($status) || !\in_array($status, EvalQuestionStatus::ALL, true))) {
                $itemProblems[] = \sprintf('%s: "status" must be one of "%s".', $prefix, implode('", "', EvalQuestionStatus::ALL));
            }

            $markers = null;
            if (property_exists($entry, 'markers')) {
                [$markers, $markerProblems] = self::parseMarkers($entry->markers, $prefix);
                array_push($itemProblems, ...$markerProblems);
            }

            $negative = $entry->negative ?? false;
            $negativeProblem = property_exists($entry, 'negative') ? EvalQuestionRetrievalRules::negative($entry->negative) : null;
            $negativeProblem ??= EvalQuestionRetrievalRules::conflict($negative, $entry->markers ?? null);
            if (null !== $negativeProblem) {
                $itemProblems[] = $prefix.': '.$negativeProblem;
            }

            if ([] !== $itemProblems) {
                array_push($problems, ...$itemProblems);
                continue;
            }

            /** @var array<string, mixed> $value */
            $value = $values[$index];
            $requestData = $value['requestData'] ?? null;
            $items[] = [
                'key' => (string) $value['key'],
                'question' => (string) $value['question'],
                'expectedAnswer' => (string) $value['expectedAnswer'],
                'requestData' => \is_array($requestData) ? $requestData : null,
                'status' => \is_string($status) ? $status : null,
                'markers' => $markers,
                'negative' => true === $negative,
            ];
        }

        return [[] === $problems ? $items : [], $problems];
    }

    /**
     * Validates the `markers` value of an item with
     * {@see EvalQuestionRetrievalRules::markers()}: the trimmed markers
     * (null for a JSON null or when there is any problem) and every
     * problem found, prefixed with the item.
     *
     * @return array{0: list<string>|null, 1: list<string>}
     */
    private static function parseMarkers(mixed $value, string $prefix): array
    {
        [$markers, $problems] = EvalQuestionRetrievalRules::markers($value);

        return [$markers, array_map(static fn (string $problem): string => $prefix.': '.$problem, $problems)];
    }

    /**
     * Writes the changed fields of an item to an existing question.
     *
     * @param array{key: string, question: string, expectedAnswer: string, requestData: array<array-key, mixed>|null, status: string|null, markers: list<string>|null, negative: bool} $item
     *
     * @return bool whether anything changed
     */
    private static function apply(EvalQuestion $question, array $item): bool
    {
        $changed = false;
        if ($question->getQuestion() !== $item['question']) {
            $question->setQuestion($item['question']);
            $changed = true;
        }
        if ($question->getExpectedAnswer() !== $item['expectedAnswer']) {
            $question->setExpectedAnswer($item['expectedAnswer']);
            $changed = true;
        }
        if (self::canonical($question->getRequestData()) !== self::canonical($item['requestData'])) {
            $question->setRequestData($item['requestData']);
            $changed = true;
        }
        if (null !== $item['status'] && $question->getStatus() !== $item['status']) {
            $question->setStatus($item['status']);
            $changed = true;
        }
        if (self::canonical($question->getMarkers()) !== self::canonical($item['markers'])) {
            $question->setMarkers($item['markers']);
            $changed = true;
        }
        // A stored null (imported before the retrieval fields existed) equals false.
        if (($question->isNegative() ?? false) !== $item['negative']) {
            $question->setNegative($item['negative']);
            $changed = true;
        }

        return $changed;
    }

    /**
     * The value with the keys of every object sorted, so a stored JSON
     * value equals the same value from the file regardless of key order.
     */
    private static function canonical(mixed $value): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }
        $value = array_map(self::canonical(...), $value);
        if (!array_is_list($value)) {
            ksort($value, \SORT_STRING);
        }

        return $value;
    }
}
