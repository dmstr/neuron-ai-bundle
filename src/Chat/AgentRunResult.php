<?php

// file generated with AI assistance: Claude Code - 2026-11-27 22:41:45 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Chat;

use Dmstr\NeuronAiBundle\Agent\Rule\RuleOutcome;
use Dmstr\NeuronAiBundle\Agent\TurnConfiguration;

/**
 * The raw outcome of one run of {@see AgentRunner::execute()} and
 * {@see AgentRunner::executeConfigured()}, before it is shaped into an
 * {@see AgentChatResult}: the configuration the run used, and either the
 * pre-answer rule that answered or refused (`rule`, no provider called,
 * `chat` null) or the array of {@see ChatService::chatConfigured()}
 * (`chat`, `rule` null).
 */
final readonly class AgentRunResult
{
    /**
     * @param array{answer: string, provider: string, model: string, connection: string, mcp_connections: list<string>, sources: list<array<string, mixed>>, usage: array<string, mixed>|null, structured: mixed, schema_errors: array<string, mixed>|null, forced: bool, limit_reached: bool, terminal_tool: string|null, frame: array{prefix: string, suffix: string}|null, gate: array{gate: string, kind: string, template: string}|null, follow_ups: list<string>|null}|null $chat
     */
    public function __construct(
        public TurnConfiguration $configuration,
        public ?RuleOutcome $rule = null,
        public ?array $chat = null,
    ) {
    }
}
