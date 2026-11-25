<?php

// file generated with AI assistance: Claude Code - 2026-10-01 15:35:36 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Conversation;

use Dmstr\ApiConfiguration\Entity\ApiConfiguration;
use Dmstr\NeuronAiBundle\Entity\Refs\ProviderModel;
use Dmstr\NeuronAiBundle\Provider\ModelOptions;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Tools\ToolInterface;

/**
 * Input of one {@see TurnRunner::run()}: everything is resolved already
 * (model checked, tools built, context retrieved), so the run itself only
 * talks to the provider and the tools.
 */
final readonly class TurnRequest
{
    /**
     * @param string                    $model          the model on the connection, passed as is (checked at the entry point by {@see \Dmstr\NeuronAiBundle\Provider\ModelAvailabilityCheck})
     * @param list<Message>             $history        earlier messages (complete turns only)
     * @param list<ToolInterface>       $tools          tools the model may call
     * @param list<ContextChunk>        $context        retrieved context, already capped
     * @param string|null               $instructions   caller instructions (system prompt), before the context
     * @param array<string, mixed>|null $responseSchema JSON schema the answer must follow
     * @param int|null                  $maxToolCalls   executed tool calls after which the next inference must answer without tools ({@see ToolCallBoundMiddleware}); null = the runner's default (`turn.max_tool_calls_default`, unbounded when that is null too)
     * @param list<string>              $terminalTools  names of offered tools whose call ends the turn with the tool's result as the answer ({@see TerminalClose}), in request order (the first one is forced at the bound); validated by the caller; [] = none
     * @param UserTurnLimit|null        $userTurnLimit  non-null when this is the last allowed user turn of the conversation ({@see UserTurnLimit::forNextTurn()}): the runner forces it from the first inference, so it runs no tool loop ({@see UserTurnLimitMiddleware}); null = not the last turn or unbounded
     * @param ModelOptions|null         $modelOptions   the model options of the provider call (sampling, output token limit, context window, effort, thinking) ({@see \Dmstr\NeuronAiBundle\ProviderFactory::chatProvider()}); null = provider defaults
     * @param TurnTexts                 $texts          the limit reasons and the forced-close fallback of the turn ({@see TurnRecorder}, {@see TurnRunner}); the default keeps the English reasons of {@see ForcedClose} and has no fallback
     * @param string                    $contextInstruction the instruction text in front of the context block ({@see ContextRetriever::instructionsFor()}), resolved by the caller: the agent version's text, the bundle's `turn.context_instruction` without an agent; the default is {@see ContextRetriever::LEGACY_INSTRUCTION}
     * @param ApiConfiguration|null     $connection     the provider connection the turn runs on ({@see \Dmstr\NeuronAiBundle\ProviderFactory::chatProvider()}); `provider` is its type ({@see \Dmstr\NeuronAiBundle\Provider\ProviderType::of()}), kept for logs, records and the `done` event. Every production caller sets it; null only for requests that never reach a provider (unit tests), {@see TurnRunner} refuses a run without one
     * @param ProviderModel|null        $providerModel  the model catalogue entry of $connection and $model, stored on the assistant records ({@see TurnRecorder}); null only in unit tests (every stored conversation and agent version has one). The run itself never reads it
     * @param string|null               $threadId       the thread the agent of the run is bound to (neuron-ai 4.x): the conversation's UUID for a conversation turn; null = {@see TurnRunner} binds a fresh UUID (stateless chat, evaluation). The run's history is still {@see $history}: the agent is built per turn with an in-memory store
     */
    public function __construct(
        public string $provider,
        public string $model,
        public string $content,
        public array $history = [],
        public array $tools = [],
        public array $context = [],
        public ?string $instructions = null,
        public ?array $responseSchema = null,
        public ?int $maxToolCalls = null,
        public array $terminalTools = [],
        public ?UserTurnLimit $userTurnLimit = null,
        public ?ModelOptions $modelOptions = null,
        public TurnTexts $texts = new TurnTexts(),
        public string $contextInstruction = ContextRetriever::LEGACY_INSTRUCTION,
        public ?ApiConfiguration $connection = null,
        public ?ProviderModel $providerModel = null,
        public ?string $threadId = null,
    ) {
    }

    public function getConnection(): ?ApiConfiguration
    {
        return $this->connection;
    }

    /**
     * @param list<ContextChunk> $context
     */
    public function withContext(array $context): self
    {
        return new self($this->provider, $this->model, $this->content, $this->history, $this->tools, $context, $this->instructions, $this->responseSchema, $this->maxToolCalls, $this->terminalTools, $this->userTurnLimit, $this->modelOptions, $this->texts, $this->contextInstruction, $this->connection, $this->providerModel, $this->threadId);
    }
}

// - revised 2026-10-14
