<?php

// file generated with AI assistance: Claude Code - 2026-10-04 12:12:07 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Conversation\Gate;

/**
 * The reply to one gate call ({@see GateCall::ask()}): the mapped verdict
 * (refuse or not, with the model's reason, and the suggestions of the
 * follow-up gate), or the failure note when the call failed open, plus the
 * tokens the call reported.
 */
final readonly class GateReply
{
    /** Failure note prefix of a provider error. */
    public const string FAILURE_PROVIDER = 'provider_error';

    /** Failure note of a reply that holds no usable verdict. */
    public const string FAILURE_UNPARSABLE = 'unparsable_verdict';

    /** Failure note of a call that was not made because the turn asked to stop. */
    public const string FAILURE_STOPPED = 'stopped';

    /**
     * @param bool|null                                         $refuse    the verdict; null when the call failed
     * @param array{input_tokens: int, output_tokens: int}|null $usage
     * @param list<string>                                      $followUps the normalised suggestions of the follow-up gate ({@see FollowUpGenerator}); [] for every other gate and on failure
     */
    public function __construct(
        public ?bool $refuse,
        public ?string $reason = null,
        public ?string $failure = null,
        public ?array $usage = null,
        public array $followUps = [],
    ) {
    }

    /**
     * @param array{input_tokens: int, output_tokens: int}|null $usage
     */
    public static function failed(string $failure, ?array $usage = null): self
    {
        return new self(null, null, $failure, $usage);
    }

    public function isFailure(): bool
    {
        return null !== $this->failure;
    }
}
