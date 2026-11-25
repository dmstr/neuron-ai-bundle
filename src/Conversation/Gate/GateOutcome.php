<?php

// file generated with AI assistance: Claude Code - 2026-10-04 12:12:07 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Conversation\Gate;

/**
 * The result of one answer gate of a turn: which gate ran, its
 * verdict, the internal reason, the votes (answerability), a failure note
 * when the gate failed open, the tokens it used and, for the follow-up
 * gate, the suggestions.
 *
 * The reason and the failure note are internal: they are stored only in
 * the record's `providerPayload` under `gates.<gate>` ({@see toPayload()})
 * and never sent to a client. A gate that failed (provider error,
 * unparsable verdict, stopped before its call) passes with a `failure`.
 */
final readonly class GateOutcome
{
    /** The prompt-injection guard, before retrieval. */
    public const string INJECTION = 'injection';

    /** The answerability check, between retrieval and answer. */
    public const string ANSWERABILITY = 'answerability';

    /** The follow-up suggestions, after a complete text answer. */
    public const string FOLLOW_UPS = 'followUps';

    public const array GATES = [self::INJECTION, self::ANSWERABILITY, self::FOLLOW_UPS];

    public const string PASS = 'pass';
    public const string REFUSE = 'refuse';

    /**
     * @param string                                                   $gate      one of {@see GATES}
     * @param string                                                   $verdict   {@see PASS} or {@see REFUSE}
     * @param string|null                                              $reason    the model's reason (internal)
     * @param list<array{verdict: string, reason: ?string, failure?: string}>|null $votes the single votes of a voting gate; null for a single-call gate
     * @param string|null                                              $failure   why the gate failed open; null when it decided
     * @param array{input_tokens: int, output_tokens: int}|null        $usage     tokens of the gate's provider calls; null when none were reported
     * @param list<string>                                             $followUps the suggestions of the follow-up gate
     */
    public function __construct(
        public string $gate,
        public string $verdict,
        public ?string $reason = null,
        public ?array $votes = null,
        public ?string $failure = null,
        public ?array $usage = null,
        public array $followUps = [],
    ) {
        if (!\in_array($gate, self::GATES, true)) {
            throw new \InvalidArgumentException(\sprintf('Unknown answer gate "%s" (expected one of: %s).', $gate, implode(', ', self::GATES)));
        }
        if (self::PASS !== $verdict && self::REFUSE !== $verdict) {
            throw new \InvalidArgumentException(\sprintf('Unknown gate verdict "%s" (expected pass or refuse).', $verdict));
        }
        if (self::REFUSE === $verdict && self::FOLLOW_UPS === $gate) {
            throw new \InvalidArgumentException('The follow-up gate never refuses.');
        }
    }

    /**
     * @param array{input_tokens: int, output_tokens: int}|null $usage
     */
    public static function pass(string $gate, ?string $reason = null, ?array $usage = null, ?string $failure = null): self
    {
        return new self($gate, self::PASS, $reason, null, $failure, $usage);
    }

    /**
     * @param array{input_tokens: int, output_tokens: int}|null $usage
     */
    public static function refuse(string $gate, ?string $reason, ?array $usage = null): self
    {
        return new self($gate, self::REFUSE, $reason, null, null, $usage);
    }

    /**
     * The outcome of a single-call gate from its reply: refuse or pass as
     * the verdict says, pass with the failure note (and no suggestions)
     * when the call failed. A pass keeps the reply's suggestions
     * ({@see GateReply::$followUps}).
     */
    public static function fromReply(string $gate, GateReply $reply): self
    {
        if (null !== $reply->failure || null === $reply->refuse) {
            return self::pass($gate, null, $reply->usage, $reply->failure ?? GateReply::FAILURE_UNPARSABLE);
        }

        return $reply->refuse
            ? self::refuse($gate, $reply->reason, $reply->usage)
            : new self($gate, self::PASS, $reply->reason, null, null, $reply->usage, $reply->followUps);
    }

    /**
     * The outcome of a voting gate from the replies of all its votes, in
     * order: it refuses only when the
     * refusing votes are more than half of all votes (strict majority of
     * the configured number); a failed vote counts as pass (fail-open).
     *
     *  - `votes`: one `{verdict, reason, failure?}` per reply, a failed
     *    vote as `pass` with its failure note;
     *  - `reason`: the reason of the first decided vote on the winning side
     *    (null when that side has only failed votes);
     *  - `failure`: only when every vote failed, the note of the first one
     *    (the gate passed without a decision);
     *  - `usage`: the sum of the reported usages, null when none reported.
     *
     * @param non-empty-list<GateReply> $replies
     */
    public static function fromVotes(string $gate, array $replies): self
    {
        if ([] === $replies) {
            throw new \InvalidArgumentException('A voting gate needs at least one vote.');
        }

        $votes = [];
        $refusing = 0;
        $usage = null;
        foreach ($replies as $reply) {
            $decided = null === $reply->failure && null !== $reply->refuse;
            $vote = ['verdict' => $decided && $reply->refuse ? self::REFUSE : self::PASS, 'reason' => $decided ? $reply->reason : null];
            if (!$decided) {
                $vote['failure'] = $reply->failure ?? GateReply::FAILURE_UNPARSABLE;
            }
            $votes[] = $vote;
            $refusing += self::REFUSE === $vote['verdict'] ? 1 : 0;
            if (null !== $reply->usage) {
                $usage = [
                    'input_tokens' => ($usage['input_tokens'] ?? 0) + $reply->usage['input_tokens'],
                    'output_tokens' => ($usage['output_tokens'] ?? 0) + $reply->usage['output_tokens'],
                ];
            }
        }

        $verdict = 2 * $refusing > \count($votes) ? self::REFUSE : self::PASS;
        $reason = null;
        foreach ($votes as $vote) {
            if ($verdict === $vote['verdict'] && !isset($vote['failure'])) {
                $reason = $vote['reason'];
                break;
            }
        }
        $failures = array_column($votes, 'failure');
        $failure = \count($failures) === \count($votes) ? $failures[0] : null;

        return new self($gate, $verdict, $reason, $votes, $failure, $usage);
    }

    public function isRefusal(): bool
    {
        return self::REFUSE === $this->verdict;
    }

    /**
     * Whether the gate failed open (it passed without a decision).
     */
    public function isFailure(): bool
    {
        return null !== $this->failure;
    }

    /**
     * The tokens of the gate, zero when none were reported.
     *
     * @return array{input_tokens: int, output_tokens: int}
     */
    public function tokens(): array
    {
        return [
            'input_tokens' => $this->usage['input_tokens'] ?? 0,
            'output_tokens' => $this->usage['output_tokens'] ?? 0,
        ];
    }

    /**
     * The entry stored under `providerPayload.gates.<gate>` of the final
     * record: `verdict`, `reason`, `votes` (only for a voting gate),
     * `failure` (only when the gate failed open), `usage` ({@see tokens()}).
     *
     * @return array{verdict: string, reason: ?string, votes?: list<array{verdict: string, reason: ?string, failure?: string}>, failure?: string, usage: array{input_tokens: int, output_tokens: int}}
     */
    public function toPayload(): array
    {
        $payload = ['verdict' => $this->verdict, 'reason' => $this->reason];
        if (null !== $this->votes) {
            $payload['votes'] = $this->votes;
        }
        if (null !== $this->failure) {
            $payload['failure'] = $this->failure;
        }
        $payload['usage'] = $this->tokens();

        return $payload;
    }
}
