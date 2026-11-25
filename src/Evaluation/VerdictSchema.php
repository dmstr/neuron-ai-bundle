<?php

// file generated with AI assistance: Claude Code - 2026-10-03 20:34:44 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Evaluation;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Helper;
use Opis\JsonSchema\Validator;

/**
 * The bundle's verdict schema (`verdict.schema.json`, JSON Schema draft
 * 2020-12) and its validation with opis/json-schema: `verdict` (one of
 * {@see Verdict::ALL}), `reasoning`, `suggestions[]` with `type`
 * ({@see SuggestionType::ALL}), `category` (at most 100 characters),
 * `description`, `action` and `priority` ({@see SuggestionPriority::ALL}),
 * no further properties anywhere.
 *
 * The judge's answer is checked against this schema whatever response
 * schema the judge agent version declares itself; that runtime check is
 * the guarantee that a stored evaluation is well-formed.
 */
final class VerdictSchema
{
    public const string FILE = __DIR__.'/verdict.schema.json';

    /** Errors reported per validation. */
    private const int MAX_ERRORS = 20;

    private static ?\stdClass $schema = null;

    /**
     * The schema as a JSON value (objects as \stdClass).
     */
    public static function schema(): \stdClass
    {
        if (null === self::$schema) {
            $json = file_get_contents(self::FILE);
            if (false === $json) {
                throw new \LogicException(\sprintf('The verdict schema "%s" cannot be read.', self::FILE));
            }
            $schema = json_decode($json, false, 512, \JSON_THROW_ON_ERROR);
            if (!$schema instanceof \stdClass) {
                throw new \LogicException(\sprintf('The verdict schema "%s" is not a JSON object.', self::FILE));
            }
            self::$schema = $schema;
        }

        return self::$schema;
    }

    /**
     * The schema as compact JSON text, for the judge prompt.
     */
    public static function json(): string
    {
        return json_encode(self::schema(), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
    }

    /**
     * Decodes an answer text (code fences are stripped, as the structured
     * answer validator of the turn does) with JSON objects kept as objects,
     * so an empty object is not mistaken for an empty list.
     *
     * @return array{value: mixed, errors: array<string, string>|null} `errors`: `invalid_json` when the text is no JSON
     */
    public static function decode(string $answer): array
    {
        $json = trim($answer);
        $json = preg_replace('/^```(?:json)?\s*|\s*```$/', '', $json) ?? $json;

        try {
            return ['value' => json_decode($json, false, 512, \JSON_THROW_ON_ERROR), 'errors' => null];
        } catch (\JsonException $e) {
            return ['value' => null, 'errors' => ['invalid_json' => $e->getMessage()]];
        }
    }

    /**
     * Validates a JSON value (objects as \stdClass; associative arrays are
     * converted, an empty array then counts as a list).
     *
     * @return array<string, string>|null JSON pointer => message, null when valid
     */
    public static function errors(mixed $value): ?array
    {
        if (\is_array($value)) {
            $value = Helper::toJSON($value);
        }

        $validator = new Validator();
        $validator->setMaxErrors(self::MAX_ERRORS);
        $result = $validator->validate($value, self::schema());
        if ($result->isValid()) {
            return null;
        }

        $errors = [];
        foreach (new ErrorFormatter()->format($result->error(), false) as $pointer => $message) {
            $errors['' === $pointer ? '/' : (string) $pointer] = (string) $message;
        }

        return $errors;
    }
}
