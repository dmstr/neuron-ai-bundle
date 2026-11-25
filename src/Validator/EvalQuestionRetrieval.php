<?php

// file generated with AI assistance: Claude Code - 2026-10-04 17:23:51 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Validator;

use Symfony\Component\Validator\Attribute\HasNamedArguments;
use Symfony\Component\Validator\Constraint;

/**
 * Class-level constraint for {@see \Dmstr\NeuronAiBundle\Entity\EvalQuestion}:
 * the retrieval fields `markers` and `negative` follow
 * {@see EvalQuestionRetrievalRules}, the same rules the import applies.
 *
 * The messages are the rules' problems, not templates, so the API and the
 * import report a problem in the same words (the import adds `item N: `).
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class EvalQuestionRetrieval extends Constraint
{
    public const string INVALID_MARKERS_ERROR = '3b8f1d52-6c0e-4a7b-9f2d-8e5c1a4b7d93';
    public const string NEGATIVE_WITH_MARKERS_ERROR = 'c71e9a04-2d5b-4f8e-b3a6-0f9d2e7c5b18';

    protected const ERROR_NAMES = [
        self::INVALID_MARKERS_ERROR => 'INVALID_MARKERS_ERROR',
        self::NEGATIVE_WITH_MARKERS_ERROR => 'NEGATIVE_WITH_MARKERS_ERROR',
    ];

    /**
     * @param string[]|null $groups
     */
    #[HasNamedArguments]
    public function __construct(
        ?array $groups = null,
        mixed $payload = null,
    ) {
        parent::__construct(null, $groups, $payload);
    }

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
