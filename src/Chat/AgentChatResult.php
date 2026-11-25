<?php

// file generated with AI assistance: Claude Code - 2026-10-03 10:41:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Chat;

/**
 * Response of `POST /agents/{id}/chat` (`agent_chat`): the fields of the
 * app's stateless `/chat` result in the same order, plus the agent version
 * that answered with its kind, the effective language, the pre-answer rule that
 * decided the call, if any, the frame of the answer, the answer gate
 * that refused the call, if any, and the follow-up suggestions.
 */
final class AgentChatResult
{
    public function __construct(
        public readonly string $answer,
        /** Provider type of the agent version's connection (`ollama`, `anthropic`). */
        public readonly string $provider,
        /** Model of the agent version: the name of its model catalogue entry. */
        public readonly string $model,
        /** Name of the provider connection of the agent version's model entry. */
        public readonly string $connection = '',
        /** @var list<string> names of the MCP connections whose tools the version offers */
        public readonly array $mcpConnections = [],
        /**
         * The retrieved context chunks that were sent to the model
         * (`content`, `score`, `collectionId`, `collectionName`, `topic`,
         * `sourceUrl`, `chunkId`, `chunkNumber`, `documentId`,
         * `filename`, `breadcrumb`, `title`, `ext`, `page`, `quote`, `link`),
         * each with `checkedAt` and `changedAt` of its document (ISO 8601,
         * null when unknown).
         *
         * @var list<array<string, mixed>>
         */
        public readonly array $sources = [],
        /**
         * Token usage summed over the provider calls of the turn
         * (`input_tokens`, `output_tokens`, `cached_input_tokens`,
         * `reasoning_tokens`), the answer gates included; with gates, their
         * share per gate under `gates` (`{<gate>: {input_tokens,
         * output_tokens}}`, {@see \Dmstr\NeuronAiBundle\Conversation\TurnResult::usageWithGates()});
         * null when the provider reports none and no gate ran.
         *
         * @var array<string, int|array<string, array{input_tokens: int, output_tokens: int}>>|null
         */
        public readonly ?array $usage = null,
        /** Parsed answer when the version has a responseSchema and the answer validated. */
        public readonly mixed $structured = null,
        /**
         * Validation errors when the schema-constrained answer was invalid.
         *
         * @var array<string, mixed>|null
         */
        public readonly ?array $schemaErrors = null,
        /** The tool-call bound was reached and the answer was forced. */
        public readonly bool $forced = false,
        /** The tool-call bound was reached. */
        public readonly bool $limitReached = false,
        /** Name of the terminal tool whose result is the answer; null when the model answered itself. */
        public readonly ?string $terminalTool = null,
        /** IRI of the agent version that answered (`/api/agent_versions/<uuid>`); a preview for `target` `candidate`. */
        public readonly string $agentVersion = '',
        /** Kind of that version: `published`, or `preview` for `target` `candidate`. */
        public readonly string $agentVersionKind = 'published',
        /** Effective language tag of the instructions. */
        public readonly string $language = '',
        /**
         * The pre-answer rule that answered or refused the call
         * (`index`, `name`, `match`, `outcome`, `template`); null when no
         * rule decided and the model answered.
         *
         * @var array{index: int, name: ?string, match: string, outcome: string, template: string}|null
         */
        public readonly ?array $rule = null,
        /**
         * The agent version's `frame` template, rendered and split at its
         * `{{answer}}` (`prefix`, `suffix`); clients show
         * `prefix + answer + suffix`, `answer` itself stays unframed. Null
         * without a `frame` template and for schema answers, terminal tool
         * answers and rule outcomes.
         *
         * @var array{prefix: string, suffix: string}|null
         */
        public readonly ?array $frame = null,
        /**
         * The answer gate that refused the call: `gate`
         * (`injection` or `answerability`), `kind` (`injection_refusal` or
         * `unanswerable_refusal`) and `template` (the refusal slot); the
         * gate's reason is never returned
         * ({@see \Dmstr\NeuronAiBundle\Conversation\Gate\GateClose::summary()}).
         * `answer` is then the refusal text, `frame` null. Null when no
         * gate refused.
         *
         * @var array{gate: string, kind: string, template: string}|null
         */
        public readonly ?array $gate = null,
        /**
         * Suggested follow-up questions of a complete text answer (at most 3, each at most 200 characters) when the version has
         * `followUps` on. Null when the follow-up gate did not run (off, a
         * rule or gate decided, a schema, terminal-tool or fallback answer),
         * `[]` when it failed or produced nothing.
         *
         * @var list<string>|null
         */
        public readonly ?array $followUps = null,
    ) {
    }
}

// - revised 2026-10-13
// - revised 2026-11-19
