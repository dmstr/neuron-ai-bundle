<?php

// file generated with AI assistance: Claude Code - 2026-10-03 20:34:44 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Evaluation;

use Dmstr\NeuronAiBundle\Entity\AgentVersion;
use Dmstr\NeuronAiBundle\Entity\Refs\ProviderModel;

/**
 * The verdict of the AI judge on one evaluation result
 * ({@see Judge::judge()}), valid against {@see VerdictSchema}, with the
 * measurements of the judge call.
 */
final readonly class JudgeVerdict
{
    /** Longest error text (stored as the evaluation's `errorMessage`). */
    public const int MAX_ERROR_LENGTH = 2000;

    /**
     * @param string                $verdict     one of {@see Verdict::ALL}
     * @param list<JudgeSuggestion> $suggestions
     * @param AgentVersion          $judgeVersion the published judge version that answered
     * @param string                $model        the model name the judge was called with ('' when unknown)
     * @param int|null              $tokensInput  null when the provider reported no usage
     * @param ProviderModel|null    $providerModel the judge version's model catalogue entry; null when the version has none
     */
    public function __construct(
        public string $verdict,
        public string $reasoning,
        public array $suggestions,
        public AgentVersion $judgeVersion,
        public string $model,
        public ?int $tokensInput,
        public ?int $tokensOutput,
        public int $durationMs,
        public ?ProviderModel $providerModel = null,
    ) {
    }

    /**
     * Reads the result of {@see \Dmstr\NeuronAiBundle\Chat\ChatService::chatConfigured()}.
     *
     * 1. `schema_errors` (the answer violates the judge version's own
     *    response schema, or is no JSON while one is declared) fail;
     * 2. the answer text is decoded (code fences stripped) and validated
     *    against {@see VerdictSchema}, independent of the version's
     *    response schema: `structured` is that same JSON decoded as arrays,
     *    the text keeps the distinction between an empty object and an
     *    empty list, and it is also there when the version declares no
     *    response schema (then `structured` is null);
     * 3. tokens come from `usage` (`input_tokens`, `output_tokens`), null
     *    without usage.
     *
     * $providerModel is the catalogue entry of the judge version
     * ({@see \Dmstr\NeuronAiBundle\Agent\TurnConfiguration::$providerModel}),
     * kept next to the model name of the result.
     *
     * @param array<string, mixed> $result
     *
     * @throws JudgeException with the errors as JSON (pointer => message)
     */
    public static function fromChatResult(array $result, AgentVersion $judgeVersion, int $durationMs, ?ProviderModel $providerModel = null): self
    {
        $schemaErrors = $result['schema_errors'] ?? null;
        if (\is_array($schemaErrors) && [] !== $schemaErrors) {
            throw new JudgeException(self::limit(\sprintf(
                'The judge answer does not match the response schema of version %d of judge agent "%s": %s',
                $judgeVersion->getVersionNumber(),
                $judgeVersion->getAgent()->getSlug(),
                self::encode($schemaErrors),
            )));
        }

        ['value' => $value, 'errors' => $errors] = VerdictSchema::decode(\is_string($result['answer'] ?? null) ? $result['answer'] : '');
        $errors ??= VerdictSchema::errors($value);
        if (null !== $errors) {
            throw new JudgeException(self::limit('The judge answer violates the verdict schema: '.self::encode($errors)));
        }

        /** @var \stdClass $value valid against the verdict schema */
        $suggestions = [];
        foreach ($value->suggestions as $suggestion) {
            $suggestions[] = new JudgeSuggestion(
                type: $suggestion->type,
                category: $suggestion->category,
                description: $suggestion->description,
                action: $suggestion->action,
                priority: $suggestion->priority,
            );
        }

        $usage = \is_array($result['usage'] ?? null) ? $result['usage'] : null;

        return new self(
            verdict: $value->verdict,
            reasoning: $value->reasoning,
            suggestions: $suggestions,
            judgeVersion: $judgeVersion,
            model: \is_string($result['model'] ?? null) ? $result['model'] : '',
            tokensInput: null !== $usage && \is_int($usage['input_tokens'] ?? null) ? $usage['input_tokens'] : null,
            tokensOutput: null !== $usage && \is_int($usage['output_tokens'] ?? null) ? $usage['output_tokens'] : null,
            durationMs: $durationMs,
            providerModel: $providerModel,
        );
    }

    /**
     * Cuts an error text to {@see self::MAX_ERROR_LENGTH} characters.
     */
    public static function limit(string $message): string
    {
        return mb_substr($message, 0, self::MAX_ERROR_LENGTH);
    }

    /**
     * @param array<array-key, mixed> $errors
     */
    private static function encode(array $errors): string
    {
        return json_encode($errors, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '{}';
    }
}
