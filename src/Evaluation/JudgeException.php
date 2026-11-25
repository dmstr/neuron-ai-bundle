<?php

// file generated with AI assistance: Claude Code - 2026-10-03 20:34:44 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Evaluation;

/**
 * The AI judge could not produce a verdict ({@see Judge::judge()}): the
 * judge agent is not configured or not runnable, the provider failed, the
 * answer violated the judge version's own response schema
 * (`schema_errors`) or the bundle's verdict schema
 * ({@see VerdictSchema}). The message is the text stored as the
 * evaluation's `errorMessage`.
 */
final class JudgeException extends \RuntimeException
{
}
