<?php

// file generated with AI assistance: Claude Code - 2026-10-02 21:49:46 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Validator;

use Symfony\Component\Validator\Attribute\HasNamedArguments;
use Symfony\Component\Validator\Constraint;

/**
 * Property constraint for {@see \Dmstr\NeuronAiBundle\Entity\Agent::$candidate}:
 * the candidate must match the structure of `Entity/Agent/candidate.json` (known
 * keys, types and ranges). References are not checked here but when the
 * candidate is published, so a candidate may point to things that do not exist yet.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class ValidAgentCandidate extends Constraint
{
    public const INVALID_CANDIDATE_ERROR = 'c7d2a1e4-5b3f-4c8e-9a6d-1f0e2b3c4d51';

    protected const ERROR_NAMES = [
        self::INVALID_CANDIDATE_ERROR => 'INVALID_CANDIDATE_ERROR',
    ];

    public string $message = 'The candidate does not match the agent candidate schema: {{ error }}';

    /**
     * @param string[]|null $groups
     */
    #[HasNamedArguments]
    public function __construct(
        ?string $message = null,
        ?array $groups = null,
        mixed $payload = null,
    ) {
        parent::__construct(null, $groups, $payload);

        $this->message = $message ?? $this->message;
    }
}
