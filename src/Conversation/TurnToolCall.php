<?php

// file generated with AI assistance: Claude Code - 2026-10-01 15:35:36 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Conversation;

/**
 * One executed tool call of a turn step, as {@see TurnRecorder} observed it.
 */
final readonly class TurnToolCall
{
    /**
     * @param array<string, mixed> $inputs
     * @param array<string, mixed> $payload the tool's Neuron serialization (call id, name, description, parameters, inputs, result), JSON-normalized
     */
    public function __construct(
        public string $name,
        public ?string $callId,
        public array $inputs,
        public ?string $result,
        public bool $error,
        public ?int $durationMs,
        public array $payload,
    ) {
    }
}
