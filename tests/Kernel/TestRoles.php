<?php

// file generated with AI assistance: Claude Code - 2026-11-25 21:50:20 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Kernel;

use Dmstr\NeuronAiBundle\Security\Permissions;

/**
 * Roles of the test kernel and the permission map of a host application.
 *
 * The bundle names no role: it registers its permissions at the container
 * tag `za7_core.permission`, and a host maps them to roles in the parameter
 * `za7_core.permissions` (permission => roles). This class is that host
 * side for the tests: {@see self::PERMISSIONS} is the map,
 * {@see self::HIERARCHY} is `security.role_hierarchy.roles`, and
 * {@see TestPermissionVoter} grants a registered permission when one of the
 * token's reachable roles is mapped to it.
 *
 * Layout: one abstract role per area of responsibility (the map names only
 * these), bundled by the hierarchy into four login roles. A login with any
 * role always holds `ROLE_USER` ({@see TestHeaderAuthenticator}).
 *
 * | Login role       | Reaches                                                                                      |
 * |------------------|----------------------------------------------------------------------------------------------|
 * | `ROLE_USER`      | `ROLE_READER`                                                                                |
 * | `ROLE_EDITOR`    | `ROLE_USER`, `ROLE_CONTENT_WRITER`, `ROLE_EVAL_REVIEWER`, `ROLE_ARTIFACT_WRITER`             |
 * | `ROLE_DEVELOPER` | `ROLE_USER`, `ROLE_AGENT_ADMIN`, `ROLE_MCP_ADMIN`, `ROLE_EVAL_ADMIN`, `ROLE_MODEL_ADMIN`     |
 * | `ROLE_ADMIN`     | `ROLE_EDITOR`, `ROLE_DEVELOPER`, `ROLE_CONVERSATION_ADMIN`, `ROLE_COLLECTION_UNLOCK`         |
 *
 * Permission => abstract role:
 *
 * | Permission                                        | Role(s)                                   |
 * |---------------------------------------------------|-------------------------------------------|
 * | `neuronai:conversation:list`, `:create`           | `ROLE_READER`                             |
 * | `neuronai:conversation:read_any`, `:delete_any`   | `ROLE_CONVERSATION_ADMIN`                 |
 * | `neuronai:conversation:read_payload`              | `ROLE_CONVERSATION_ADMIN`                 |
 * | `neuronai:conversation:read_eval`                 | `ROLE_EVAL_ADMIN`, `ROLE_EVAL_REVIEWER`   |
 * | `neuronai:artifact:write`                         | `ROLE_ARTIFACT_WRITER`                    |
 * | `neuronai:collection:read`                        | `ROLE_READER`                             |
 * | `neuronai:collection:write`, `:lock`              | `ROLE_CONTENT_WRITER`                     |
 * | `neuronai:collection:unlock`                      | `ROLE_COLLECTION_UNLOCK`                  |
 * | `neuronai:agent:read`, `:chat`                    | `ROLE_READER`                             |
 * | `neuronai:agent:write`, `:chat_candidate`         | `ROLE_AGENT_ADMIN`                        |
 * | `neuronai:mcp_connection:use_restricted`          | `ROLE_AGENT_ADMIN`                        |
 * | `neuronai:eval:read`                              | `ROLE_READER`                             |
 * | `neuronai:eval:write`                             | `ROLE_EVAL_ADMIN`                         |
 * | `neuronai:eval:rate`                              | `ROLE_EVAL_REVIEWER`                      |
 * | `neuronai:eval:compare`                           | `ROLE_EVAL_REVIEWER`, `ROLE_EVAL_ADMIN`   |
 * | `neuronai:feedback:read`, `:review`               | `ROLE_EVAL_ADMIN`, `ROLE_EVAL_REVIEWER`   |
 * | `neuronai:feedback:submit_reviewer`               | `ROLE_EVAL_REVIEWER`                      |
 * | `neuronai:mcp_tool:read`                          | `ROLE_READER`                             |
 * | `neuronai:mcp_tool:write`                         | `ROLE_MCP_ADMIN`                          |
 * | `neuronai:provider_model:read`                    | `ROLE_READER`                             |
 * | `neuronai:provider_model:write`                   | `ROLE_MODEL_ADMIN`                        |
 * | `neuronai:knowledge:read`                         | `ROLE_READER`                             |
 * | `apiconfig:*`, `job:*`, `audit:read`              | `ROLE_ADMIN`                              |
 *
 * Translating role names of tests written against a host application with
 * prefixed abstract roles (`ROLE_<PREFIX>_<AREA>`): drop the prefix, the
 * area name stays. The login roles keep their names.
 *
 * | Host role                        | Test kernel role          |
 * |----------------------------------|---------------------------|
 * | `ROLE_<PREFIX>_READER`           | `ROLE_READER`             |
 * | `ROLE_<PREFIX>_CONTENT_WRITER`   | `ROLE_CONTENT_WRITER`     |
 * | `ROLE_<PREFIX>_EVAL_REVIEWER`    | `ROLE_EVAL_REVIEWER`      |
 * | `ROLE_<PREFIX>_ARTIFACT_WRITER`  | `ROLE_ARTIFACT_WRITER`    |
 * | `ROLE_<PREFIX>_AGENT_ADMIN`      | `ROLE_AGENT_ADMIN`        |
 * | `ROLE_<PREFIX>_MCP_ADMIN`        | `ROLE_MCP_ADMIN`          |
 * | `ROLE_<PREFIX>_EVAL_ADMIN`       | `ROLE_EVAL_ADMIN`         |
 * | `ROLE_<PREFIX>_MODEL_ADMIN`      | `ROLE_MODEL_ADMIN`        |
 * | `ROLE_<PREFIX>_CONVERSATION_ADMIN` | `ROLE_CONVERSATION_ADMIN` |
 * | `ROLE_<PREFIX>_COLLECTION_UNLOCK`  | `ROLE_COLLECTION_UNLOCK`  |
 * | `ROLE_USER`, `ROLE_EDITOR`, `ROLE_DEVELOPER`, `ROLE_ADMIN` | unchanged |
 * | `ROLE_CONVERSATION_TOKEN` (capability token, no `ROLE_USER`) | unchanged |
 *
 * Areas of other packages (crawling, process engine, settings) have no
 * role here: no permission of this kernel needs them.
 */
final class TestRoles
{
    // Login roles
    public const string USER = 'ROLE_USER';
    public const string EDITOR = 'ROLE_EDITOR';
    public const string DEVELOPER = 'ROLE_DEVELOPER';
    public const string ADMIN = 'ROLE_ADMIN';

    // Abstract roles, one per area
    public const string READER = 'ROLE_READER';
    public const string CONTENT_WRITER = 'ROLE_CONTENT_WRITER';
    public const string EVAL_REVIEWER = 'ROLE_EVAL_REVIEWER';
    public const string ARTIFACT_WRITER = 'ROLE_ARTIFACT_WRITER';
    public const string AGENT_ADMIN = 'ROLE_AGENT_ADMIN';
    public const string MCP_ADMIN = 'ROLE_MCP_ADMIN';
    public const string EVAL_ADMIN = 'ROLE_EVAL_ADMIN';
    public const string MODEL_ADMIN = 'ROLE_MODEL_ADMIN';
    public const string CONVERSATION_ADMIN = 'ROLE_CONVERSATION_ADMIN';
    public const string COLLECTION_UNLOCK = 'ROLE_COLLECTION_UNLOCK';

    /** `security.role_hierarchy.roles` of the test kernel */
    public const array HIERARCHY = [
        self::USER => [self::READER],
        self::EDITOR => [self::USER, self::CONTENT_WRITER, self::EVAL_REVIEWER, self::ARTIFACT_WRITER],
        self::DEVELOPER => [self::USER, self::AGENT_ADMIN, self::MCP_ADMIN, self::EVAL_ADMIN, self::MODEL_ADMIN],
        self::ADMIN => [self::EDITOR, self::DEVELOPER, self::CONVERSATION_ADMIN, self::COLLECTION_UNLOCK],
    ];

    /** Permission => roles that hold it (`za7_core.permissions`) */
    public const array PERMISSIONS = [
        Permissions::CONVERSATION_LIST => [self::READER],
        Permissions::CONVERSATION_CREATE => [self::READER],
        Permissions::READ_ANY => [self::CONVERSATION_ADMIN],
        Permissions::DELETE_ANY => [self::CONVERSATION_ADMIN],
        Permissions::READ_PAYLOAD => [self::CONVERSATION_ADMIN],
        Permissions::READ_EVAL => [self::EVAL_ADMIN, self::EVAL_REVIEWER],
        Permissions::ARTIFACT_WRITE => [self::ARTIFACT_WRITER],
        Permissions::COLLECTION_READ => [self::READER],
        Permissions::COLLECTION_WRITE => [self::CONTENT_WRITER],
        Permissions::COLLECTION_LOCK => [self::CONTENT_WRITER],
        Permissions::COLLECTION_UNLOCK => [self::COLLECTION_UNLOCK],
        Permissions::AGENT_READ => [self::READER],
        Permissions::AGENT_WRITE => [self::AGENT_ADMIN],
        Permissions::AGENT_CHAT => [self::READER],
        Permissions::AGENT_CHAT_CANDIDATE => [self::AGENT_ADMIN],
        Permissions::MCP_CONNECTION_USE_RESTRICTED => [self::AGENT_ADMIN],
        Permissions::EVAL_READ => [self::READER],
        Permissions::EVAL_WRITE => [self::EVAL_ADMIN],
        Permissions::EVAL_RATE => [self::EVAL_REVIEWER],
        Permissions::EVAL_COMPARE => [self::EVAL_REVIEWER, self::EVAL_ADMIN],
        Permissions::FEEDBACK_READ => [self::EVAL_ADMIN, self::EVAL_REVIEWER],
        Permissions::FEEDBACK_SUBMIT_REVIEWER => [self::EVAL_REVIEWER],
        Permissions::FEEDBACK_REVIEW => [self::EVAL_ADMIN, self::EVAL_REVIEWER],
        Permissions::MCP_TOOL_READ => [self::READER],
        Permissions::MCP_TOOL_WRITE => [self::MCP_ADMIN],
        Permissions::PROVIDER_MODEL_READ => [self::READER],
        Permissions::PROVIDER_MODEL_WRITE => [self::MODEL_ADMIN],
        Permissions::KNOWLEDGE_READ => [self::READER],
        // Platform permissions of dmstr/api-configuration-bundle,
        // dmstr/symfony-job-queue-bundle and dmstr/doctrine-audit-log-bundle
        'apiconfig:read' => [self::ADMIN],
        'apiconfig:write' => [self::ADMIN],
        'apiconfig:authorize' => [self::ADMIN],
        'job:read' => [self::ADMIN],
        'job:prune' => [self::ADMIN],
        'audit:read' => [self::ADMIN],
    ];

    private function __construct()
    {
    }
}
