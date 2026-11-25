<?php

// file generated with AI assistance: Claude Code - 2026-10-01 15:35:36 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Conversation;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Helper;
use Opis\JsonSchema\Validator;

/**
 * JSON-schema answers: the instruction that asks the model for a JSON
 * object, and the validation of the answer with opis/json-schema. Used by
 * the persisted turn (kind `schema`) and by the stateless chat.
 */
final class StructuredAnswerValidator
{
    /**
     * The system-prompt section that asks for a JSON answer.
     *
     * @param array<string, mixed> $schema
     */
    public function instructionsFor(array $schema): string
    {
        // Without the second sentence models answered after a tool loop in
        // prose or called a made-up tool `json`.
        return "Respond ONLY with a single JSON object that validates against this JSON schema, no prose:\n"
            ."Write the JSON object as the text of your final message, also after tool calls; there is no tool for it.\n".json_encode($schema, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Parses the answer (code fences are stripped) and validates it.
     *
     * @param array<string, mixed> $schema
     *
     * @return array{structured: mixed, errors: array<string, mixed>|null}
     *                                                                     `structured`: the decoded answer when valid, else null;
     *                                                                     `errors`: `invalid_json` or the opis errors by JSON pointer, null when valid
     */
    public function validate(string $answer, array $schema): array
    {
        // Models occasionally wrap JSON in code fences — strip them.
        $json = trim($answer);
        $json = preg_replace('/^```(?:json)?\s*|\s*```$/', '', $json) ?? $json;

        try {
            $decoded = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return ['structured' => null, 'errors' => ['invalid_json' => $e->getMessage()]];
        }

        $validation = new Validator()->validate($decoded, Helper::toJSON($schema));
        if (!$validation->isValid()) {
            return ['structured' => null, 'errors' => new ErrorFormatter()->format($validation->error())];
        }

        return ['structured' => json_decode($json, true), 'errors' => null];
    }
}

// - revised 2026-11-09
