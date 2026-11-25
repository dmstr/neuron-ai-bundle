<?php

// file generated with AI assistance: Claude Code - 2026-10-02 05:54:24 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Testing;

use Dmstr\NeuronAiBundle\Provider\ToolChoice;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\SystemMessage;
use NeuronAI\Testing\RequestRecord;
use NeuronAI\Tools\ToolInterface;

/**
 * A recorded provider call plus the tool choice that was set at call time
 * (null = none), written by {@see RecordingFakeAIProvider}.
 */
final class ToolChoiceRequestRecord extends RequestRecord
{
    /**
     * @param string               $method           'chat', 'stream' or 'structured'
     * @param Message[]            $messages         the messages passed to the provider
     * @param SystemMessage|null   $systemPrompt     the system prompt set at call time
     * @param array<ToolInterface> $tools            the tools configured at call time
     * @param string|null          $structuredClass  the output class (structured only)
     * @param array<string, mixed> $structuredSchema the response schema (structured only)
     * @param ToolChoice|null      $toolChoice       the tool choice set at call time
     */
    public function __construct(
        string $method,
        array $messages,
        ?SystemMessage $systemPrompt,
        array $tools,
        ?string $structuredClass = null,
        array $structuredSchema = [],
        public readonly ?ToolChoice $toolChoice = null,
    ) {
        parent::__construct(
            method: $method,
            messages: $messages,
            systemPrompt: $systemPrompt,
            tools: $tools,
            structuredClass: $structuredClass,
            structuredSchema: $structuredSchema,
        );
    }
}

// - revised 2026-10-14
