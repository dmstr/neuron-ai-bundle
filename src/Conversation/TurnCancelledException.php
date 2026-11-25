<?php

// file generated with AI assistance: Claude Code - 2026-10-01 22:14:12 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Conversation;

/**
 * The failure of a turn that was cancelled on request
 * ({@see TurnCancelObserver}, or the cancel poll of the streaming run);
 * {@see TurnResult::isAborted()} is true for it.
 */
final class TurnCancelledException extends \RuntimeException
{
}
