<?php

// file generated with AI assistance: Claude Code - 2026-10-01 21:54:19 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Anonymous;

/**
 * Release values and limits of anonymous conversations at one point in time
 * (see {@see AnonymousAccessPolicyInterface}).
 *
 *  - `collections`, `mcpConnections`: references (UUID, IRI or name) of
 *    what an anonymous conversation is created with;
 *  - `model`: reference (IRI `/api/refs/provider_models/<uuid>`, full or
 *    partial UUID) of the chat model in the model catalogue, which names the
 *    provider connection (`ollama` or `anthropic`); required for a release
 *    without `agent` (there is no default) and checked at creation, null
 *    here means not configured;
 *  - `retentionDays`: days after creation until the conversation is closed;
 *  - `creationsPerAddressAndHour`: creations per client address within a
 *    sliding hour; 0 disables anonymous creation;
 *  - `maxTurnsPerConversation`, `maxTokensPerConversation`: limits that
 *    close the conversation when reached (tokens = input plus output);
 *  - `deletionDelayDays`: days after closing until the conversation and its
 *    messages are deleted;
 *  - `agent`: reference (IRI `/api/agents/<uuid>`, full UUID or slug) of
 *    the agent whose current published version anonymous conversations are
 *    pinned to; when set, the version's collections and MCP tools replace
 *    `collections` and `mcpConnections`, and its model replaces `model`.
 *    Null: no agent.
 */
final readonly class AnonymousAccessPolicy
{
    /**
     * @param list<string> $collections
     * @param list<string> $mcpConnections
     * @param string|null  $model          model reference (IRI or UUID of a catalogue entry); null: not configured
     * @param string|null  $agent          reference (IRI, UUID or slug) of the agent whose current version anonymous conversations are pinned to
     *
     * @throws \InvalidArgumentException for a value outside its range
     */
    public function __construct(
        public array $collections,
        public array $mcpConnections,
        public ?string $model,
        public int $retentionDays,
        public int $creationsPerAddressAndHour,
        public int $maxTurnsPerConversation,
        public int $maxTokensPerConversation,
        public int $deletionDelayDays,
        public ?string $agent = null,
    ) {
        self::assertAtLeast('retentionDays', $retentionDays, 1);
        self::assertAtLeast('creationsPerAddressAndHour', $creationsPerAddressAndHour, 0);
        self::assertAtLeast('maxTurnsPerConversation', $maxTurnsPerConversation, 1);
        self::assertAtLeast('maxTokensPerConversation', $maxTokensPerConversation, 1);
        self::assertAtLeast('deletionDelayDays', $deletionDelayDays, 0);
    }

    /**
     * Whether anonymous creation is enabled at all.
     */
    public function allowsCreation(): bool
    {
        return $this->creationsPerAddressAndHour > 0;
    }

    private static function assertAtLeast(string $name, int $value, int $minimum): void
    {
        if ($value < $minimum) {
            throw new \InvalidArgumentException(\sprintf('%s must be at least %d, got %d.', $name, $minimum, $value));
        }
    }
}
