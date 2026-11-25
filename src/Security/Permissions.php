<?php

// file generated with AI assistance: Claude Code - 2026-10-01 15:21:48 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Security;

/**
 * The permissions the bundle checks, all of the form
 * `neuronai:<resource>:<action>`. The bundle names no role: it registers
 * the permissions under the container tag `za7_core.permission` (see
 * `config/services/security.php`) and the application maps them to roles in
 * `za7_core.permissions`. A permission without a map entry is granted to
 * nobody.
 *
 * The three `*_OWN` attributes are different: they are not registered and
 * not mapped, {@see ConversationVoter} decides them from the owner of the
 * conversation. The expressions that combine "own" with the mapped "any"
 * permissions are in {@see ConversationSecurity}.
 */
final class Permissions
{
    /** Read a conversation and its messages (owner only; decided by the voter). */
    public const string READ_OWN = 'neuronai:conversation:read_own';

    /** Run a turn on a conversation (owner only; decided by the voter). */
    public const string TURN_OWN = 'neuronai:conversation:turn_own';

    /** Delete a conversation (owner only; decided by the voter). */
    public const string DELETE_OWN = 'neuronai:conversation:delete_own';

    /** List conversations and messages (own ones; see the owner query extension). */
    public const string CONVERSATION_LIST = 'neuronai:conversation:list';

    /** Create a conversation. */
    public const string CONVERSATION_CREATE = 'neuronai:conversation:create';

    /** Read conversations of every owner. */
    public const string READ_ANY = 'neuronai:conversation:read_any';

    /** Delete conversations of every owner. */
    public const string DELETE_ANY = 'neuronai:conversation:delete_any';

    /** See the provider payload of a message (item GET only). */
    public const string READ_PAYLOAD = 'neuronai:conversation:read_payload';

    /**
     * Read evaluation conversations (origin = eval) and replay conversations
     * (origin = replay) of every owner, with their messages and
     * the record of a replay (`GET /conversations/{id}/replay_run`).
     */
    public const string READ_EVAL = 'neuronai:conversation:read_eval';

    /**
     * Write and edit artifact files in own agent conversations:
     * whether a turn offers `write_file` and `edit_file` next to the read
     * tools. Decided once when the turn's tools are built, never when a
     * tool runs.
     */
    public const string ARTIFACT_WRITE = 'neuronai:artifact:write';

    public const string COLLECTION_READ = 'neuronai:collection:read';
    public const string COLLECTION_WRITE = 'neuronai:collection:write';
    public const string COLLECTION_LOCK = 'neuronai:collection:lock';
    public const string COLLECTION_UNLOCK = 'neuronai:collection:unlock';

    public const string AGENT_READ = 'neuronai:agent:read';
    public const string AGENT_WRITE = 'neuronai:agent:write';
    public const string AGENT_CHAT = 'neuronai:agent:chat';

    /** Chat with the candidate version of an agent (`target` `candidate`). */
    public const string AGENT_CHAT_CANDIDATE = 'neuronai:agent:chat_candidate';

    /** Name a restricted MCP connection directly in a chat request. */
    public const string MCP_CONNECTION_USE_RESTRICTED = 'neuronai:mcp_connection:use_restricted';

    public const string EVAL_READ = 'neuronai:eval:read';
    public const string EVAL_WRITE = 'neuronai:eval:write';
    public const string EVAL_RATE = 'neuronai:eval:rate';
    public const string EVAL_COMPARE = 'neuronai:eval:compare';

    public const string FEEDBACK_READ = 'neuronai:feedback:read';

    /** Submit feedback as `reviewer` (otherwise as `enduser`). */
    public const string FEEDBACK_SUBMIT_REVIEWER = 'neuronai:feedback:submit_reviewer';

    /** Set the review status and note of feedback. */
    public const string FEEDBACK_REVIEW = 'neuronai:feedback:review';

    public const string MCP_TOOL_READ = 'neuronai:mcp_tool:read';
    public const string MCP_TOOL_WRITE = 'neuronai:mcp_tool:write';

    public const string PROVIDER_MODEL_READ = 'neuronai:provider_model:read';
    public const string PROVIDER_MODEL_WRITE = 'neuronai:provider_model:write';

    public const string KNOWLEDGE_READ = 'neuronai:knowledge:read';

    /**
     * Every permission the bundle registers at the tag `za7_core.permission`;
     * the `*_OWN` attributes are not part of it.
     */
    public const array REGISTERED = [
        self::CONVERSATION_LIST,
        self::CONVERSATION_CREATE,
        self::READ_ANY,
        self::DELETE_ANY,
        self::READ_PAYLOAD,
        self::READ_EVAL,
        self::ARTIFACT_WRITE,
        self::COLLECTION_READ,
        self::COLLECTION_WRITE,
        self::COLLECTION_LOCK,
        self::COLLECTION_UNLOCK,
        self::AGENT_READ,
        self::AGENT_WRITE,
        self::AGENT_CHAT,
        self::AGENT_CHAT_CANDIDATE,
        self::MCP_CONNECTION_USE_RESTRICTED,
        self::EVAL_READ,
        self::EVAL_WRITE,
        self::EVAL_RATE,
        self::EVAL_COMPARE,
        self::FEEDBACK_READ,
        self::FEEDBACK_SUBMIT_REVIEWER,
        self::FEEDBACK_REVIEW,
        self::MCP_TOOL_READ,
        self::MCP_TOOL_WRITE,
        self::PROVIDER_MODEL_READ,
        self::PROVIDER_MODEL_WRITE,
        self::KNOWLEDGE_READ,
    ];
}
