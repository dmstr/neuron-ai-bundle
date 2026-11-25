<?php

// file generated with AI assistance: Claude Code - 2026-10-01 15:21:48 UTC

declare(strict_types=1);

/*
 * Services of the conversation turn and the stateless chat, imported by
 * DmstrNeuronAiBundle::loadExtension(). Scalars come from the
 * dmstr_neuron_ai.* container parameters the bundle sets from its
 * configuration; bundle services get no autoconfiguration, so every tag is
 * explicit.
 */

use Dmstr\ApiConfiguration\Security\ConfigSecrets;
use Dmstr\NeuronAiBundle\Agent\AgentLimitDefaultsInterface;
use Dmstr\NeuronAiBundle\Agent\LimitResolver;
use Dmstr\NeuronAiBundle\Agent\VersionTurnMapper;
use Dmstr\NeuronAiBundle\Anonymous\AnonymousAccessPolicyInterface;
use Dmstr\NeuronAiBundle\Anonymous\ConversationCloser;
use Dmstr\NeuronAiBundle\Chat\ChatService;
use Dmstr\NeuronAiBundle\Conversation\ContextExpander;
use Dmstr\NeuronAiBundle\Conversation\ContextRetriever;
use Dmstr\NeuronAiBundle\Conversation\ConversationTurnService;
use Dmstr\NeuronAiBundle\Conversation\Gate\AnswerabilityCheck;
use Dmstr\NeuronAiBundle\Conversation\Gate\FollowUpGenerator;
use Dmstr\NeuronAiBundle\Conversation\Gate\GateCall;
use Dmstr\NeuronAiBundle\Conversation\Gate\InjectionGuard;
use Dmstr\NeuronAiBundle\Conversation\Gate\TurnGates;
use Dmstr\NeuronAiBundle\Conversation\HistoryReconstructor;
use Dmstr\NeuronAiBundle\Conversation\ProviderSelection;
use Dmstr\NeuronAiBundle\Conversation\StreamingTurnService;
use Dmstr\NeuronAiBundle\Conversation\ConversationStarter;
use Dmstr\NeuronAiBundle\Conversation\StreamResumer;
use Dmstr\NeuronAiBundle\Conversation\StructuredAnswerValidator;
use Dmstr\NeuronAiBundle\Conversation\TurnLock;
use Dmstr\NeuronAiBundle\Conversation\TurnRunner;
use Dmstr\NeuronAiBundle\Embeddings\EmbeddingModelRegistry;
use Dmstr\NeuronAiBundle\Mcp\McpConnectionResolver;
use Dmstr\NeuronAiBundle\Mcp\McpToolFactory;
use Dmstr\NeuronAiBundle\Provider\Auth\OllamaHttpClientFactory;
use Dmstr\NeuronAiBundle\Provider\ModelAvailabilityCheck;
use Dmstr\NeuronAiBundle\Provider\ProviderModelResolver;
use Dmstr\NeuronAiBundle\Provider\ToolModelCheck;
use Dmstr\NeuronAiBundle\ProviderFactory;
use Dmstr\NeuronAiBundle\Retrieval\CollectionRetriever;
use Dmstr\NeuronAiBundle\Retrieval\NeighbourSourceInterface;
use Dmstr\NeuronAiBundle\State\ConversationCancelProcessor;
use Dmstr\NeuronAiBundle\Stream\StreamEventStore;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    // Chat and embeddings providers, built from provider connections
    // (ApiConfiguration of type `ollama` or `anthropic`); there is no
    // configured endpoint or default model. ConfigSecrets decrypts the
    // `api_key` of a connection; the turn deadline caps the HTTP timeout;
    // OllamaHttpClientFactory (DmstrNeuronAiBundle) authenticates `ollama`
    // connections with an `auth_type`. Apps may replace this service (e.g.
    // with Testing\FakeProviderFactory under when@test).
    $services->set(ProviderFactory::class)
        ->args([
            service(EmbeddingModelRegistry::class),
            service(ConfigSecrets::class),
            param('dmstr_neuron_ai.turn.deadline_seconds'),
            service(OllamaHttpClientFactory::class),
        ]);

    // --- Turn runtime ---------------------------------------------------------
    // JSON-schema instruction and answer validation (persisted turn and chat).
    $services->set(StructuredAnswerValidator::class);

    // Neighbour passages of hits from uploaded documents; a no-op unless the
    // application aliases a NeighbourSourceInterface.
    $services->set(ContextExpander::class)
        ->args([
            service(NeighbourSourceInterface::class)->nullOnInvalid(),
        ]);

    // Retrieval of a turn's context with the context cap (bundle cap or the
    // agent version's limits), the expansion and the bundle's instruction
    // text for paths without an agent.
    $services->set(ContextRetriever::class)
        ->args([
            service(CollectionRetriever::class),
            service('doctrine.orm.entity_manager'),
            param('dmstr_neuron_ai.turn.context_max_chunks'),
            param('dmstr_neuron_ai.turn.context_max_chars'),
            service(ContextExpander::class),
            param('dmstr_neuron_ai.turn.context_instruction'),
            param('dmstr_neuron_ai.turn.retrieval_history_messages'),
        ]);

    // The agent run shared by the conversation turn and the stateless chat.
    $services->set(TurnRunner::class)
        ->args([
            service(ProviderFactory::class),
            service(StructuredAnswerValidator::class),
            param('dmstr_neuron_ai.turn.deadline_seconds'),
            param('dmstr_neuron_ai.turn.history_context_window'),
            param('dmstr_neuron_ai.turn.max_tool_calls_default'),
            service('logger')->nullOnInvalid(),
        ]);

    $services->set(HistoryReconstructor::class);

    // Answer gates of agent turns: one provider call per gate
    // (per vote for the answerability check) on the version's provider and
    // model, failing open with a logged warning; TurnGates is the entry
    // point of every turn path.
    $services->set(GateCall::class)
        ->args([
            service(ProviderFactory::class),
            service('logger')->nullOnInvalid(),
        ]);

    $services->set(InjectionGuard::class)
        ->args([
            service(GateCall::class),
        ]);

    $services->set(FollowUpGenerator::class)
        ->args([
            service(GateCall::class),
        ]);

    $services->set(AnswerabilityCheck::class)
        ->args([
            service(GateCall::class),
        ]);

    // The gate calls of one turn share the turn deadline.
    $services->set(TurnGates::class)
        ->args([
            service(InjectionGuard::class),
            service(AnswerabilityCheck::class),
            param('dmstr_neuron_ai.turn.deadline_seconds'),
            '$followUpGenerator' => service(FollowUpGenerator::class),
        ]);

    // Advisory lock per conversation on the default connection (the
    // running flag of a conversation reads pg_locks with the same key).
    $services->set(TurnLock::class)
        ->args([
            service('doctrine.dbal.default_connection'),
        ]);

    // One turn of a persisted conversation (POST /conversations/{id}/messages).
    // The access policy of anonymous conversations comes from the app (alias
    // of AnonymousAccessPolicyInterface); without it the bundle still
    // compiles and anonymous turns answer 503. The tool-call bound of a
    // conversation created with an agent comes from the LimitResolver over
    // the version, the settings default (alias of
    // AgentLimitDefaultsInterface, optional) and the bundle default. An agent
    // conversation takes its whole turn configuration from the pinned
    // version through the VersionTurnMapper (registered in agent.php).
    $services->set(ConversationTurnService::class)
        ->args([
            service('doctrine.orm.entity_manager'),
            service(TurnLock::class),
            service(TurnRunner::class),
            service(HistoryReconstructor::class),
            service(ContextRetriever::class),
            service(McpConnectionResolver::class),
            service(McpToolFactory::class),
            service(ConversationCloser::class),
            service('clock'),
            service(AnonymousAccessPolicyInterface::class)->nullOnInvalid(),
            service('logger')->nullOnInvalid(),
            service(LimitResolver::class),
            service(AgentLimitDefaultsInterface::class)->nullOnInvalid(),
            param('dmstr_neuron_ai.turn.max_tool_calls_default'),
            '$versionTurnMapper' => service(VersionTurnMapper::class),
            '$gates' => service(TurnGates::class),
        ]);

    // --- Streaming turn -------------------------------------------------------
    // Stored stream events for resumption (DBAL only, autocommit).
    $services->set(StreamEventStore::class)
        ->args([
            service('doctrine.dbal.default_connection'),
        ]);

    // The start turn of a new agent conversation whose version has a
    // startMessage: begin() in the create request, run() on kernel.terminate.
    $services->set(ConversationStarter::class)
        ->args([
            service(VersionTurnMapper::class),
            service(StreamingTurnService::class),
            service('logger')->nullOnInvalid(),
        ])
        ->tag('kernel.event_subscriber');

    // One streamed turn (POST /conversations/{id}/stream): begin() is the
    // preflight, StreamingTurn::run() streams and stores. Same gate as the
    // turn above, so the same closer, clock, optional access policy, limit
    // resolution and mapper of agent conversations.
    $services->set(StreamingTurnService::class)
        ->args([
            service('doctrine.orm.entity_manager'),
            service(TurnLock::class),
            service(TurnRunner::class),
            service(HistoryReconstructor::class),
            service(ContextRetriever::class),
            service(McpConnectionResolver::class),
            service(McpToolFactory::class),
            service(StreamEventStore::class),
            service('doctrine.dbal.default_connection'),
            service(ConversationCloser::class),
            service('clock'),
            service(AnonymousAccessPolicyInterface::class)->nullOnInvalid(),
            service('logger')->nullOnInvalid(),
            // Named: the cancel poll interval before them keeps its default.
            '$limitResolver' => service(LimitResolver::class),
            '$limitDefaults' => service(AgentLimitDefaultsInterface::class)->nullOnInvalid(),
            '$maxToolCallsDefault' => param('dmstr_neuron_ai.turn.max_tool_calls_default'),
            '$versionTurnMapper' => service(VersionTurnMapper::class),
            '$gates' => service(TurnGates::class),
        ]);

    // Resumption of a stream (GET /conversations/{id}/stream).
    $services->set(StreamResumer::class)
        ->args([
            service(StreamEventStore::class),
            service(TurnLock::class),
        ]);

    // POST /conversations/{id}/cancel (conversation_cancel).
    $services->set(ConversationCancelProcessor::class)
        ->args([
            service('doctrine.dbal.default_connection'),
            service(TurnLock::class),
        ])
        ->tag('api_platform.state_processor');

    // Model of a request without an agent version (POST /chat,
    // POST /conversations without agent, anonymous creation without agent):
    // a required reference to the model catalogue, checked live.
    $services->set(ProviderSelection::class)
        ->args([
            service(ProviderModelResolver::class),
            service(ModelAvailabilityCheck::class),
        ]);

    // Stateless chat (POST /chat of the app) on the same runner; with
    // mcpConnections the model must have the tools capability (catalogue).
    $services->set(ChatService::class)
        ->args([
            service(TurnRunner::class),
            service(ProviderFactory::class),
            service(McpConnectionResolver::class),
            service(McpToolFactory::class),
            service(ContextRetriever::class),
            service(ProviderSelection::class),
            param('dmstr_neuron_ai.store.top_k'),
            '$gates' => service(TurnGates::class),
            '$toolModelCheck' => service(ToolModelCheck::class),
        ]);
};
// - revised 2026-10-14
// - revised 2026-11-09
