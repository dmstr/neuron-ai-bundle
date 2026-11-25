<?php

// file generated with AI assistance: Claude Code - 2026-10-04 17:23:51 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Validator;

/**
 * The rules of the retrieval fields `markers` and `negative` of an
 * {@see \Dmstr\NeuronAiBundle\Entity\EvalQuestion} ,
 * shared by the import
 * ({@see \Dmstr\NeuronAiBundle\Evaluation\EvalQuestionImporter}, which
 * prefixes each problem with `item N: `) and the API
 * ({@see EvalQuestionRetrievalValidator}, which reports each problem as a
 * violation with the message unchanged):
 *  - `markers`: null or a non-empty list of at most {@see self::MAX_MARKERS}
 *    strings, each non-empty after trimming, at most
 *    {@see self::MAX_MARKER_LENGTH} characters and unique after trimming;
 *    the trimmed strings are stored;
 *  - `negative`: a boolean;
 *  - `negative: true` together with markers that are not null is a
 *    conflict (a question that should find nothing has nothing to look
 *    for).
 *
 * Problems are plain English sentences that name the property in double
 * quotes, e.g. `"markers" entry 2 duplicates entry 0 ("Hydraulik").`
 */
final class EvalQuestionRetrievalRules
{
    /** Maximum number of markers of one question. */
    public const int MAX_MARKERS = 50;

    /** Maximum length of one marker in characters, after trimming. */
    public const int MAX_MARKER_LENGTH = 200;

    public const string NEGATIVE_NOT_BOOLEAN = '"negative" must be a boolean.';
    public const string NEGATIVE_WITH_MARKERS = '"negative": true cannot be combined with "markers" (a negative question expects no hits).';

    private function __construct()
    {
    }

    /**
     * Validates a `markers` value: the trimmed markers (null for null or
     * when there is any problem) and every problem found, in the order of
     * the entries.
     *
     * @return array{0: list<string>|null, 1: list<string>}
     */
    public static function markers(mixed $value): array
    {
        if (null === $value) {
            return [null, []];
        }
        // A JSON object decodes to \stdClass in the import and to a
        // non-list array in the API; neither is a list.
        if (!\is_array($value) || !array_is_list($value)) {
            return [null, ['"markers" must be a list of strings or null.']];
        }
        if ([] === $value) {
            return [null, ['"markers" must not be an empty list (omit it or use null for no markers).']];
        }

        $problems = [];
        if (\count($value) > self::MAX_MARKERS) {
            $problems[] = \sprintf('"markers" must not have more than %d entries (%d given).', self::MAX_MARKERS, \count($value));
        }

        $markers = [];
        $firstIndexOfMarker = [];
        foreach ($value as $index => $marker) {
            if (!\is_string($marker) || '' === trim($marker)) {
                $problems[] = \sprintf('"markers" entry %d must be a non-empty string.', $index);
                continue;
            }
            $marker = trim($marker);
            if (mb_strlen($marker) > self::MAX_MARKER_LENGTH) {
                $problems[] = \sprintf('"markers" entry %d must not be longer than %d characters.', $index, self::MAX_MARKER_LENGTH);
                continue;
            }
            if (isset($firstIndexOfMarker[$marker])) {
                $problems[] = \sprintf('"markers" entry %d duplicates entry %d ("%s").', $index, $firstIndexOfMarker[$marker], $marker);
                continue;
            }
            $firstIndexOfMarker[$marker] = $index;
            $markers[] = $marker;
        }

        return [[] === $problems ? $markers : null, $problems];
    }

    /**
     * The problem of a `negative` value that is not a boolean, or null.
     */
    public static function negative(mixed $value): ?string
    {
        return \is_bool($value) ? null : self::NEGATIVE_NOT_BOOLEAN;
    }

    /**
     * The conflict of `negative: true` with markers that are not null, or
     * null. Any other `negative` value (false, null, a non-boolean that
     * {@see self::negative()} reports) never conflicts.
     */
    public static function conflict(mixed $negative, mixed $markers): ?string
    {
        return true === $negative && null !== $markers ? self::NEGATIVE_WITH_MARKERS : null;
    }

    /**
     * The value with every string entry trimmed and everything else left
     * as it is, so {@see self::markers()} still reports the invalid
     * entries at their index. Trimming is idempotent: validating the
     * trimmed value yields the same problems as validating the original.
     *
     * @param array<array-key, mixed>|null $value
     *
     * @return array<array-key, mixed>|null
     */
    public static function trimMarkers(?array $value): ?array
    {
        if (null === $value) {
            return null;
        }

        return array_map(static fn (mixed $marker): mixed => \is_string($marker) ? trim($marker) : $marker, $value);
    }
}
