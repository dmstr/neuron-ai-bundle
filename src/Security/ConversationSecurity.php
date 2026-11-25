<?php

// file generated with AI assistance: Claude Code - 2026-10-31 20:00:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Security;

/**
 * Security expressions of the conversation operations. The "own or any"
 * linking lives here once, as text: API Platform evaluates the constants as
 * the `security` of the operations, and the imperative call sites evaluate
 * the same text with `isGranted(new Expression(...), $conversation)`.
 * {@see ConversationVoter} decides only the `*_own` attributes; the mapped
 * permissions (`read_any`, `delete_any`, `read_eval`) are decided by the
 * core voter, which ignores the subject.
 *
 * The Hydra operation filter evaluates item expressions with a `null`
 * object; `object?.…` keeps that a plain answer instead of an exception.
 */
final class ConversationSecurity
{
    /** Read a conversation: owner, holder of read_any, or holder of read_eval on an evaluation or replay conversation. */
    public const string READ = "is_granted('neuronai:conversation:read_own', object) or is_granted('neuronai:conversation:read_any') or (is_granted('neuronai:conversation:read_eval') and object?.isEvaluationOrigin())";

    /** {@see self::READ} for a resource that belongs to a conversation (message, replay record). */
    public const string READ_PARENT = "is_granted('neuronai:conversation:read_own', object?.getConversation()) or is_granted('neuronai:conversation:read_any') or (is_granted('neuronai:conversation:read_eval') and object?.getConversation()?.isEvaluationOrigin())";

    /** Delete a conversation: owner or holder of delete_any. */
    public const string DELETE = "is_granted('neuronai:conversation:delete_own', object) or is_granted('neuronai:conversation:delete_any')";

    /** Run a turn: the owner only, there is no `turn_any`. */
    public const string TURN = "is_granted('neuronai:conversation:turn_own', object)";
}
