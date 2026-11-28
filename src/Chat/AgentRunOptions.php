<?php

// file generated with AI assistance: Claude Code - 2026-11-27 22:41:45 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Chat;

use Dmstr\NeuronAiBundle\Agent\VersionTurnMapper;

/**
 * The per-run options of {@see AgentRunner}: the caller values the version
 * is mapped with and the switches of the run. The defaults are those of
 * `POST /agents/{id}/chat` without `variables`, `language` and
 * `maxToolCalls`: default language, no variables, the resolved tool-call
 * bound, pre-answer rules and answer gates on.
 *
 * `variables`, `language` and `maxToolCalls` are passed to
 * {@see VersionTurnMapper::map()}; an entry that starts from an already
 * mapped configuration ignores them. `toolGuard` applies to every entry:
 * with a guard, every tool of the run is checked by it
 * ({@see ToolArgumentGuard}); null, the default, runs the tools unchanged.
 */
final readonly class AgentRunOptions
{
    /**
     * @param array<string, mixed> $variables    placeholder values of the instructions, validated against the version's `startSchema`
     * @param string|null          $language     null: the version's default language
     * @param int|null             $maxToolCalls request bound; may only lower the resolved limit
     * @param bool                 $rules        whether the version's pre-answer rules run on the last user message
     * @param bool                 $gates        whether the version's answer gates run ({@see ChatService::chatConfigured()})
     * @param ToolArgumentGuard|null $toolGuard  checks one argument of every tool call of the run; null = no guard
     */
    public function __construct(
        public array $variables = [],
        public ?string $language = null,
        public ?int $maxToolCalls = null,
        public bool $rules = true,
        public bool $gates = true,
        public ?ToolArgumentGuard $toolGuard = null,
    ) {
    }
}

// - revised 2026-11-27 (tool argument guard)
