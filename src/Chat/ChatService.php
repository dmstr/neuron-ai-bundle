<?php

// file generated with AI assistance: Claude Code - 2026-07-10 16:00:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Chat;

use ApiPlatform\Validator\Exception\ValidationException;
use Dmstr\ApiConfiguration\Entity\ApiConfiguration;
use Dmstr\NeuronAiBundle\Agent\TurnConfiguration;
use Dmstr\NeuronAiBundle\Agent\VersionTurnMapper;
use Dmstr\NeuronAiBundle\Conversation\ContextChunk;
use Dmstr\NeuronAiBundle\Conversation\ContextRetriever;
use Dmstr\NeuronAiBundle\Conversation\Gate\AnswerabilityCheck;
use Dmstr\NeuronAiBundle\Conversation\Gate\FollowUpGenerator;
use Dmstr\NeuronAiBundle\Conversation\Gate\GateCall;
use Dmstr\NeuronAiBundle\Conversation\Gate\GateClose;
use Dmstr\NeuronAiBundle\Conversation\Gate\GateRun;
use Dmstr\NeuronAiBundle\Conversation\Gate\InjectionGuard;
use Dmstr\NeuronAiBundle\Conversation\Gate\TurnGates;
use Dmstr\NeuronAiBundle\Conversation\ProviderSelection;
use Dmstr\NeuronAiBundle\Conversation\TerminalClose;
use Dmstr\NeuronAiBundle\Conversation\TerminalToolNames;
use Dmstr\NeuronAiBundle\Conversation\TurnRequest;
use Dmstr\NeuronAiBundle\Conversation\TurnRunner;
use Dmstr\NeuronAiBundle\Entity\Collection;
use Dmstr\NeuronAiBundle\Mcp\McpConnectionResolver;
use Dmstr\NeuronAiBundle\Mcp\McpToolFactory;
use Dmstr\NeuronAiBundle\Provider\ProviderType;
use Dmstr\NeuronAiBundle\Provider\ToolModelCheck;
use Dmstr\NeuronAiBundle\ProviderFactory;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\UserMessage;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * Stateless chat: one turn over a caller-supplied message list, on the same
 * {@see TurnRunner} as the persisted conversation turn, without storing
 * anything.
 *
 * The last message is the user message of the turn, the earlier ones are
 * the history. The model is required, there is no default: it is a
 * reference to an entry of the model catalogue, which names the provider
 * connection; {@see ProviderSelection} resolves it (an available chat
 * model of an active `ollama` or `anthropic` connection) and checks it live
 * against its connection (422 at `model`), MCP tools come from the
 * catalogue of the referenced `mcp` connections ({@see McpConnectionResolver},
 * which refuses a restricted connection without `neuronai:mcp_connection:use_restricted` with 403,
 * {@see McpToolFactory}; no MCP server is contacted unless the model calls
 * one of its tools; with tools, a model the model catalogue knows to lack
 * the tools capability, or one outside the connection's allow-list
 * `tool_models`, is a 422 at `model`, see {@see ToolModelCheck}),
 * context is retrieved from the given collections
 * ({@see ContextRetriever}, capped like a conversation turn, with the
 * bundle's `turn.context_instruction` in front). An optional
 * JSON response schema is requested via instructions and the answer is
 * validated against it. An optional tool-call bound ({@see TurnRequest::$maxToolCalls})
 * forces the answer once the model has made that many tool calls; the
 * result reports it as `limit_reached` and `forced`. Optional terminal
 * tools ({@see TerminalClose}) end the turn when the model calls one: the
 * tool's result is the answer and the result names the tool as
 * `terminal_tool`. Everything is resolved and checked before the provider
 * is contacted.
 *
 * {@see self::chatConfigured()} runs the same turn on the configuration of
 * a published agent version ({@see TurnConfiguration}, built and checked by
 * {@see VersionTurnMapper}): provider connection, model, substituted instructions,
 * response schema, collections and retrieval depth, the context limits and
 * the context instruction, the version's tool
 * subset with its terminal tools, the resolved tool-call bound and the
 * model options all come from the configuration, the
 * caller supplies only the messages. With `$gates` (the agent chat only,
 * never the judge) the version's answer gates run as in a conversation
 * turn ({@see GateRun}).
 */
class ChatService
{
    private readonly TurnGates $gates;

    /**
     * @param ProviderSelection $providerSelection resolves the model reference of {@see self::chat()} and checks the model live
     * @param TurnGates|null      $gates          the answer gates of {@see self::chatConfigured()}; null = built on $providerFactory
     *                                            without a logger and with the default deadline
     * @param ToolModelCheck|null $toolModelCheck the tools capability and allow-list check of {@see self::chat()} with tools; null = not checked
     */
    public function __construct(
        private readonly TurnRunner $runner,
        ProviderFactory $providerFactory,
        private readonly McpConnectionResolver $connectionResolver,
        private readonly McpToolFactory $toolFactory,
        private readonly ContextRetriever $contextRetriever,
        private readonly ProviderSelection $providerSelection,
        private readonly int $defaultRetrievalDepth = 4,
        ?TurnGates $gates = null,
        private readonly ?ToolModelCheck $toolModelCheck = null,
    ) {
        if (null === $gates) {
            $call = new GateCall($providerFactory);
            $gates = new TurnGates(new InjectionGuard($call), new AnswerabilityCheck($call), followUpGenerator: new FollowUpGenerator($call));
        }
        $this->gates = $gates;
    }

    /**
     * @param list<array{role: string, content: string}> $messages       user/assistant messages; the last one must be a user message
     * @param string|null                                $model          the model: IRI (`/api/refs/provider_models/<uuid>`) or full or partial
     *                                                                   UUID of an entry of the model catalogue, which names the provider
     *                                                                   connection; required (null → 422), checked live
     * @param list<Collection|string>                    $collections    Collection entities or full collection UUIDs; empty = no retrieval
     * @param list<string>|null                          $mcpConnections references to `mcp` connections (IRI, full or
     *                                                                   partial UUID, or name) whose catalogued tools
     *                                                                   the model may call; null or empty = no tools
     * @param array<string,mixed>|null                   $responseSchema JSON schema the answer must follow
     * @param int|null                                   $retrievalDepth chunks retrieved before the context cap; null = default
     * @param int|null                                   $maxToolCalls   tool calls the model may make before it is forced to answer;
     *                                                                   null = the configured default (`turn.max_tool_calls_default`),
     *                                                                   unbounded by default
     * @param list<string>|null                          $terminalTools  names of offered tools whose call ends the turn with the tool's
     *                                                                   result as the answer ({@see TerminalClose}); null or [] = none
     *
     * @return array{answer: string, provider: string, model: string, connection: string, mcp_connections: list<string>, sources: list<array<string, mixed>>, usage: array<string, int>|null, structured: mixed, schema_errors: array<string, mixed>|null, forced: bool, limit_reached: bool, terminal_tool: string|null}
     *                                                                   `provider`: the connection's type; `model`: the entry's name;
     *                                                                   `connection`: the name of the entry's connection;
     *                                                                   `mcp_connections`: names of the connections used; `sources`: the context chunks sent;
     *                                                                   `limit_reached`: the tool-call bound was reached; `forced`: the answer was forced by it;
     *                                                                   `terminal_tool`: the name of the terminal tool whose result is the answer, else null
     *
     * @throws ValidationException              at `model`: a missing, unknown or ambiguous reference, an unavailable entry,
     *                                          one of an inactive connection or one known to lack the capability `completion`,
     *                                          a model the connection does not offer or that cannot be verified; with tools, a
     *                                          model the catalogue knows to lack the tools capability
     * @throws UnprocessableEntityHttpException for an invalid message list, unknown collections, unknown, inactive, ambiguous or non-mcp connection
     *                                          references, tool name clashes between connections and terminal tool
     *                                          names that are not offered tools (also duplicates and empty names)
     * @throws AccessDeniedHttpException        for a restricted MCP connection the caller may not name directly
     *                                          ({@see McpConnectionResolver::resolveManyForDirectUse()})
     * @throws \Throwable                       the provider failure, unchanged
     */
    public function chat(
        array $messages,
        ?string $instructions = null,
        ?string $model = null,
        array $collections = [],
        ?array $mcpConnections = null,
        ?array $responseSchema = null,
        ?int $retrievalDepth = null,
        ?int $maxToolCalls = null,
        ?array $terminalTools = null,
    ): array {
        $providerModel = $this->providerSelection->select($model);
        $providerConnection = $providerModel->getApiConfiguration();
        $model = $providerModel->getName();
        $provider = ProviderType::of($providerConnection);

        [$history, $content] = self::splitMessages($messages);

        // Resolve and build first, so invalid references fail before any
        // provider is contacted.
        $collections = $this->contextRetriever->loadCollections($collections);
        // Named by the caller: a restricted connection needs neuronai:mcp_connection:use_restricted (403).
        $connections = $this->connectionResolver->resolveManyForDirectUse($mcpConnections ?? []);
        $tools = $this->toolFactory->forConnections($connections);
        $this->checkToolModel($providerConnection, $model, $tools);
        $terminalTools = TerminalToolNames::resolve($terminalTools, $tools);
        $context = $this->contextRetriever->retrieve($this->contextRetriever->query($content, $history), $collections, $retrievalDepth ?? $this->defaultRetrievalDepth);

        $result = $this->runner->run(new TurnRequest(
            provider: $provider,
            model: $model,
            content: $content,
            history: $history,
            tools: $tools,
            context: $context,
            instructions: $instructions,
            responseSchema: $responseSchema,
            maxToolCalls: $maxToolCalls,
            terminalTools: $terminalTools,
            contextInstruction: $this->contextRetriever->defaultInstruction(),
            connection: $providerConnection,
            providerModel: $providerModel,
        ));

        if (null !== $result->failure) {
            throw $result->failure;
        }

        return [
            'answer' => (string) $result->answer,
            'provider' => $provider,
            'model' => $model,
            'connection' => $providerConnection->getName(),
            'mcp_connections' => array_map(static fn (ApiConfiguration $connection): string => $connection->getName(), $connections),
            'sources' => array_map(static fn (ContextChunk $chunk): array => $chunk->toArray(), $result->context),
            'usage' => $result->totalUsage(),
            'structured' => $result->structured,
            'schema_errors' => $result->schemaErrors,
            'forced' => $result->forced,
            'limit_reached' => $result->limitReached,
            'terminal_tool' => $result->terminalCall?->name,
        ];
    }

    /**
     * One stateless turn on the configuration of an agent version; the
     * messages are split as in {@see self::chat()}. The tools are already
     * built and the terminal tool names already checked by the mapper. An
     * empty instructions text counts as no instructions, as a null
     * `instructions` does in {@see self::chat()}. The turn texts of the
     * configuration (limit reasons, forced-close fallback) apply; `frame` is
     * the configuration's frame for a text answer (normal, forced or
     * fallback) and null for a schema or terminal-tool answer, `answer`
     * stays unframed.
     *
     * With $gates the version's answer gates run ({@see GateRun}): the
     * prompt-injection guard on the content of the last (user) message
     * before the retrieval, the answerability hook between retrieval and
     * run (previous question: the user message before the last one), the
     * follow-up hook after a complete text answer. A refusal returns the
     * refusal text as `answer` with `gate` ({@see GateClose::summary()}),
     * `sources` the chunks retrieved before it (`[]` for the guard), no
     * `frame` and no answer call. `usage` includes the gate tokens and lists
     * them per gate under `usage.gates` whenever a gate ran
     * ({@see \Dmstr\NeuronAiBundle\Conversation\TurnResult::usageWithGates()}).
     * `follow_ups` is {@see \Dmstr\NeuronAiBundle\Conversation\TurnResult::followUps()}:
     * null when the follow-up gate did not run (off, gates disabled, or no
     * complete text answer), `[]` when it failed or found nothing, else
     * the suggestions.
     * Only the agent chat enables them; the judge keeps the default, so a
     * judge version with a gate on never runs it.
     *
     * @param list<array{role: string, content: string}> $messages user/assistant messages; the last one must be a user message
     * @param bool                                       $gates    whether the version's answer gates run
     *
     * @return array{answer: string, provider: string, model: string, connection: string, mcp_connections: list<string>, sources: list<array<string, mixed>>, usage: array<string, mixed>|null, structured: mixed, schema_errors: array<string, mixed>|null, forced: bool, limit_reached: bool, terminal_tool: string|null, frame: array{prefix: string, suffix: string}|null, gate: array{gate: string, kind: string, template: string}|null, follow_ups: list<string>|null}
     *                                                             the shape of {@see self::chat()} plus `frame`, `gate` and `follow_ups`; `mcp_connections` are the names from the configuration
     *
     * @throws UnprocessableEntityHttpException for an invalid message list or a collection that vanished since the mapping
     * @throws \LogicException                  for a configuration without a provider connection
     * @throws \Throwable                       the provider failure, unchanged; the deadline of the gate calls
     */
    public function chatConfigured(array $messages, TurnConfiguration $configuration, bool $gates = false): array
    {
        $connection = $configuration->getConnection() ?? throw new \LogicException('The agent configuration has no provider connection.');
        [$history, $content] = self::splitMessages($messages);

        $collections = $this->contextRetriever->loadCollections($configuration->collections);
        $request = new TurnRequest(
            provider: $configuration->provider,
            model: $configuration->model,
            content: $content,
            history: $history,
            tools: $configuration->tools,
            instructions: '' === $configuration->instructions ? null : $configuration->instructions,
            responseSchema: $configuration->responseSchema,
            maxToolCalls: $configuration->maxToolCalls,
            terminalTools: $configuration->terminalTools,
            modelOptions: $configuration->modelOptions,
            texts: $configuration->texts,
            contextInstruction: $configuration->contextInstruction,
            connection: $connection,
            providerModel: $configuration->providerModel,
        );
        $run = $gates ? GateRun::of($this->gates, $configuration) : GateRun::none();

        $result = $run->beforeRetrieval($request);
        if (null === $result) {
            $context = $this->contextRetriever->retrieve($this->contextRetriever->query($content, $request->history), $collections, $configuration->retrievalDepth, $configuration->hybrid, $configuration->hybridWeight, $configuration->contextLimits);
            $result = $run->beforeAnswer($request, $context) ?? $this->runner->run($request->withContext($context));
        }
        $result = $run->finish($result);

        if (null !== $result->failure) {
            throw $result->failure;
        }

        $refusal = $result->gateRefusal();
        // As on the final record of a conversation turn: a fallback answer
        // is a text answer even on a schema version; a refusal is none.
        $textAnswer = null === $refusal && null === $result->terminalCall && (null === $configuration->responseSchema || $result->fallbackAnswer);

        return [
            'answer' => (string) $result->answer,
            'provider' => $configuration->provider,
            'model' => $configuration->model,
            'connection' => $connection->getName(),
            'mcp_connections' => $configuration->mcpConnections,
            'sources' => array_map(static fn (ContextChunk $chunk): array => $chunk->toArray(), $result->context),
            'usage' => $result->usageWithGates(),
            'structured' => $result->structured,
            'schema_errors' => $result->schemaErrors,
            'forced' => $result->forced,
            'limit_reached' => $result->limitReached,
            'terminal_tool' => $result->terminalCall?->name,
            'frame' => $textAnswer ? $configuration->frame : null,
            'gate' => null === $refusal ? null : GateClose::summary($refusal),
            'follow_ups' => $result->followUps(),
        ];
    }

    /**
     * With tools, the model must be able to take them (unknown models pass).
     *
     * @param list<\NeuronAI\Tools\ToolInterface> $tools
     *
     * @throws ValidationException at `model`
     */
    private function checkToolModel(ApiConfiguration $connection, string $model, array $tools): void
    {
        if (null === $this->toolModelCheck || [] === $tools) {
            return;
        }

        $message = $this->toolModelCheck->violation($connection, $model, 'mcpConnections');
        if (null !== $message) {
            throw new ValidationException(new ConstraintViolationList([new ConstraintViolation($message, $message, [], null, 'model', $model)]));
        }
    }

    /**
     * @param array<mixed> $messages
     *
     * @return array{list<Message>, string} history and the content of the last (user) message
     *
     * @throws UnprocessableEntityHttpException
     */
    private static function splitMessages(array $messages): array
    {
        if ([] === $messages) {
            throw new UnprocessableEntityHttpException('messages: at least one message is required.');
        }

        $history = [];
        $messages = array_values($messages);
        foreach ($messages as $i => $message) {
            $role = \is_array($message) ? ($message['role'] ?? null) : null;
            $content = \is_array($message) ? ($message['content'] ?? null) : null;
            if (!\in_array($role, ['user', 'assistant'], true) || !\is_string($content)) {
                throw new UnprocessableEntityHttpException(sprintf('messages[%d]: expected {role: "user"|"assistant", content: string}.', $i));
            }
            $history[] = 'user' === $role ? new UserMessage($content) : new AssistantMessage($content);
        }

        $last = array_pop($history);
        if (!$last instanceof UserMessage || '' === trim((string) $last->getContent())) {
            throw new UnprocessableEntityHttpException('messages: the last message must be a non-empty user message.');
        }

        return [$history, (string) $last->getContent()];
    }
}

// - revised 2026-10-09
// - revised 2026-11-09
