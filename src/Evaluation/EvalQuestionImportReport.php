<?php

// file generated with AI assistance: Claude Code - 2026-10-03 19:07:47 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Evaluation;

/**
 * Outcome of one import of evaluation questions
 * ({@see EvalQuestionImporter}): either the problems of an invalid file
 * (nothing was written) or the counts of created, updated and unchanged
 * questions.
 */
final class EvalQuestionImportReport
{
    /**
     * @param list<string> $problems every validation problem of the file;
     *                               non-empty means nothing was written
     */
    public function __construct(
        public readonly int $created = 0,
        public readonly int $updated = 0,
        public readonly int $unchanged = 0,
        public readonly array $problems = [],
    ) {
    }

    /**
     * @param list<string> $problems
     */
    public static function invalid(array $problems): self
    {
        return new self(problems: $problems);
    }

    public function isValid(): bool
    {
        return [] === $this->problems;
    }

    /**
     * The summary line, e.g. `created: 2, updated: 1, unchanged: 0`.
     */
    public function __toString(): string
    {
        return \sprintf('created: %d, updated: %d, unchanged: %d', $this->created, $this->updated, $this->unchanged);
    }
}
