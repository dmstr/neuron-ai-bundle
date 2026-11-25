<?php

// file generated with AI assistance: Claude Code - 2026-10-01 15:34:45 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Dmstr\NeuronAiBundle\Agent\AgentConfiguration;
use Dmstr\NeuronAiBundle\Agent\AgentLimitDefaultsInterface;
use Dmstr\NeuronAiBundle\Agent\AgentReferenceResolver;
use Dmstr\NeuronAiBundle\Agent\AgentVersionPinner;
use Dmstr\NeuronAiBundle\Agent\LimitResolver;
use Dmstr\NeuronAiBundle\Agent\StaleReferenceException;
use Dmstr\NeuronAiBundle\Agent\VersionTurnMapper;
use Dmstr\NeuronAiBundle\Conversation\ConversationReferences;
use Dmstr\NeuronAiBundle\Conversation\ConversationStarter;
use Dmstr\NeuronAiBundle\Conversation\ConversationStatus;
use Dmstr\NeuronAiBundle\Conversation\ProviderSelection;
use Dmstr\NeuronAiBundle\Entity\Conversation;
use Dmstr\NeuronAiBundle\Mcp\McpConnectionResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * `POST /conversations`: fills the owner and the defaults of a new
 * conversation and resolves its references before it is persisted.
 *
 *  - `ownerSubject`: the user identifier of the token; no token or user
 *    denies (fail closed);
 *  - `status` is `active` and the sequence counter starts at 1.
 *
 * `provider` and `connection` in the body answer 422, with or without
 * `agent`: both are read-only, the type and the name of the connection of
 * the model ({@see self::CONNECTION_REMOVED}).
 *
 * Without `agent`:
 *  - `model` is required, there is no default ({@see ProviderSelection}):
 *    the reference (IRI `/api/refs/provider_models/<uuid>`, full or partial
 *    UUID) must resolve to an available chat model of the model catalogue
 *    on an active `ollama` or `anthropic` connection, which offers it
 *    according to the live check (422 at `model` otherwise, also when it
 *    cannot be verified because the provider is unreachable). The entry is
 *    stored as `providerModel`, its connection as `providerConnection`
 *    (`provider` becomes its type) and its name as the model name;
 *  - `retrievalDepth`: the request value or `dmstr_neuron_ai.store.top_k`;
 *  - `collections`, `mcpConnections`: resolved by {@see ConversationReferences}
 *    and stored as full UUIDs (duplicates removed, order kept); an unknown
 *    or rejected reference answers 422; a restricted MCP connection
 *    ({@see \Dmstr\NeuronAiBundle\Mcp\McpConnectionRestriction}) answers 403
 *    unless the caller holds `neuronai:mcp_connection:use_restricted` (an agent conversation may
 *    use one through its version's `mcpTools`);
 *  - `maxUserTurns`: the request value as given (null = unlimited, the
 *    settings default does not apply);
 *  - `variables` in the body answers 422 (`"variables" require an agent.`).
 *
 * With `agent` (write-only, not persisted; an agent conversation):
 *  1. a body key the agent version fixes ({@see self::VERSION_FIXED_KEYS})
 *     answers 422 naming the key, before any other processing. API Platform
 *     denormalises the conversation's own properties before this processor
 *     runs, so presence is checked on the raw decoded body of
 *     `$context['request']`; keys that are no property of the conversation
 *     (`instructions`, `responseSchema`, `terminalTools`, `rules`,
 *     `templates`, `hybrid`, `hybridWeight` and the context keys
 *     `contextMaxChunks`, `contextMaxChars`, `contextExpansionMaxChars`,
 *     `contextInstructions`, the answer gate switches and `artifactTools`)
 *     are ignored by the serializer
 *     and reach this check too;
 *  2. `variables` must be a JSON object (a list or scalar answers 422);
 *     absent or null means none (`[]`);
 *  3. the agent is resolved by {@see AgentReferenceResolver} (IRI
 *     `/api/agents/<uuid>`, full UUID or slug; partial UUIDs never); an
 *     unknown (or empty) reference, a disabled agent or one without a
 *     published version answers 422;
 *  4. {@see VersionTurnMapper::map()} checks the current published version
 *     with the given `variables` and `language` exactly as a turn will:
 *     an unsupported language, invalid variables (against the version's
 *     `startSchema`, reserved names, unfilled placeholders) and a reference
 *     of the version that no longer resolves ({@see StaleReferenceException})
 *     answer 422 at creation instead of at the first turn;
 *  5. the version values are copied into the columns for the
 *     representation by {@see AgentVersionPinner} (shared with the
 *     anonymous creation and the evaluation batch): the provider connection
 *     (and `provider`, its type), `model`, `collections`, `retrievalDepth`
 *     and the effective `language` (default: the version's
 *     `defaultLanguage`) from the mapped configuration, `mcpConnections` as
 *     the unique connection UUIDs of the version's `mcpTools` (order of first
 *     use), and `variables` as given. The turns read everything from the
 *     pinned version again;
 *  6. `agentVersion`: the version, pinned; later publishes and rollbacks do
 *     not change it;
 *  7. `maxUserTurns` through {@see LimitResolver} from the version's
 *     `maxUserTurns`, the settings default ({@see AgentLimitDefaultsInterface})
 *     and the request value, which may only lower the result.
 *
 * 0 and negative `maxUserTurns` are rejected by the validator
 * (`Assert\Positive`) before this processor runs.
 *
 * @implements ProcessorInterface<Conversation, Conversation>
 */
final class ConversationCreateProcessor implements ProcessorInterface
{
    /** Body keys the agent version fixes; rejected when creating with `agent`. */
    public const array VERSION_FIXED_KEYS = [
        'instructions',
        'model',
        'collections',
        'mcpConnections',
        'retrievalDepth',
        'responseSchema',
        'terminalTools',
        'rules',
        'templates',
        'hybrid',
        'hybridWeight',
        'contextMaxChunks',
        'contextMaxChars',
        'contextExpansionMaxChars',
        'contextInstructions',
        'injectionGuard',
        'answerabilityCheck',
        'answerabilityVotes',
        'followUps',
        'artifactTools',
    ];

    /** The 422 message of `connection` in the body. */
    public const string CONNECTION_REMOVED = '"connection" is read-only: it is the name of the connection of the model; "model" (the IRI or UUID of an entry of /api/refs/provider_models) names the connection.';

    private readonly ConversationReferences $references;

    private readonly AgentReferenceResolver $agentResolver;

    /** Null without a turn mapper: creations with `agent` throw a \LogicException. */
    private readonly ?AgentVersionPinner $pinner;

    /**
     * @param ProcessorInterface<Conversation, Conversation> $persistProcessor
     * @param ProviderSelection                              $providerSelection resolves and checks `model` without `agent`
     * @param ConversationReferences|null                    $references       null builds one from the entity manager and the connection resolver
     * @param AgentLimitDefaultsInterface|null               $limitDefaults    the settings step of the limits; null skips it
     * @param AgentReferenceResolver|null                    $agentResolver    null builds one from the entity manager
     * @param VersionTurnMapper|null                         $turnMapper       required for creations with `agent` (null throws a \LogicException there)
     * @param ConversationStarter|null                       $starter          starts the first turn of a version with a `startMessage`; null never starts one
     */
    public function __construct(
        private readonly ProcessorInterface $persistProcessor,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly ProviderSelection $providerSelection,
        McpConnectionResolver $connectionResolver,
        private readonly EntityManagerInterface $entityManager,
        private readonly int $defaultRetrievalDepth,
        ?ConversationReferences $references = null,
        LimitResolver $limitResolver = new LimitResolver(),
        ?AgentLimitDefaultsInterface $limitDefaults = null,
        ?AgentReferenceResolver $agentResolver = null,
        ?VersionTurnMapper $turnMapper = null,
        private readonly ?ConversationStarter $starter = null,
    ) {
        $this->references = $references ?? new ConversationReferences($entityManager, $connectionResolver);
        $this->agentResolver = $agentResolver ?? new AgentReferenceResolver($entityManager);
        $this->pinner = null !== $turnMapper ? new AgentVersionPinner($turnMapper, $limitResolver, $limitDefaults) : null;
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Conversation
    {
        if (!$data instanceof Conversation) {
            throw new \LogicException(\sprintf('%s expects a %s.', self::class, Conversation::class));
        }

        $token = $this->tokenStorage->getToken();
        $identifier = null !== $token?->getUser() ? $token->getUserIdentifier() : '';
        if ('' === $identifier) {
            throw new AccessDeniedException('Creating a conversation requires an authenticated user.');
        }

        $body = self::rawBody($context);
        if (null !== $body && property_exists($body, 'provider')) {
            throw new UnprocessableEntityHttpException('"provider" is read-only: it is the type of the provider connection; set "model" instead (without an agent).');
        }
        if (null !== $body && property_exists($body, 'connection')) {
            throw new UnprocessableEntityHttpException(self::CONNECTION_REMOVED);
        }
        $agent = $data->getAgent();

        if (null !== $agent) {
            $this->applyAgent($data, $agent, $body);
        } else {
            $this->applyRequest($data, $body);
        }

        $data
            ->setOwnerSubject($identifier)
            ->setStatus(ConversationStatus::ACTIVE)
            ->setNextSequence(1);

        $result = $this->persistProcessor->process($data, $operation, $uriVariables, $context);
        $conversation = $result instanceof Conversation ? $result : $data;

        // An agent version with a start message: the first turn is prepared
        // now and runs after the answer ({@see ConversationStarter}).
        if (null !== $agent) {
            $this->starter?->start($conversation);
        }

        return $conversation;
    }

    /**
     * A conversation without agent: the request values and defaults; the
     * model is required.
     */
    private function applyRequest(Conversation $data, ?\stdClass $body): void
    {
        $variablesGiven = null !== $body
            ? null !== ($body->variables ?? null)
            : null !== $data->getVariables();
        if ($variablesGiven) {
            throw new UnprocessableEntityHttpException('"variables" require an agent.');
        }

        $model = $this->providerSelection->select($data->getModelReference());

        $data
            ->setProviderModel($model)
            ->setModelReference(null)
            ->setRetrievalDepth($data->getRetrievalDepth() ?? $this->defaultRetrievalDepth)
            ->setCollections($this->references->collectionIds($data->getCollections()))
            ->setMcpConnections($this->references->connectionIdsForDirectUse($data->getMcpConnections()));
    }

    /**
     * An agent conversation: the values of the agent's current published
     * version, checked by the mapper with the given variables and language.
     */
    private function applyAgent(Conversation $data, string $agent, ?\stdClass $body): void
    {
        if (null !== $body) {
            foreach (self::VERSION_FIXED_KEYS as $key) {
                if (property_exists($body, $key)) {
                    throw new UnprocessableEntityHttpException(\sprintf('"%s" is fixed by the agent version and cannot be set when creating a conversation with an agent.', $key));
                }
            }
        }

        $variables = self::variables($data, $body);

        $pinner = $this->pinner
            ?? throw new \LogicException(\sprintf('%s needs a %s to create a conversation with an agent.', self::class, VersionTurnMapper::class));

        $version = $this->agentResolver->publishedVersion($agent);
        $pinner->pin($data, $version, $variables, $data->getLanguage(), $data->getMaxUserTurns());
        $data->setAgent(null)->setModelReference(null);
    }

    /**
     * The caller's `variables`: a JSON object, `[]` when absent or null.
     * PHP decodes `{}` and `[]` alike, so the shape is read from the raw
     * body; without a request (internal calls) a non-empty list is refused.
     *
     * @return array<array-key, mixed>
     *
     * @throws UnprocessableEntityHttpException for a list or a scalar
     */
    private static function variables(Conversation $data, ?\stdClass $body): array
    {
        if (null !== $body) {
            $raw = $body->variables ?? null;
            if (null === $raw) {
                return [];
            }
            if (!$raw instanceof \stdClass) {
                throw new UnprocessableEntityHttpException('"variables" must be a JSON object.');
            }
        }

        $variables = $data->getVariables() ?? [];
        if ([] !== $variables && array_is_list($variables)) {
            throw new UnprocessableEntityHttpException('"variables" must be a JSON object.');
        }

        return $variables;
    }

    /**
     * Unique connection UUIDs of the version's `mcpTools`, in the order of
     * first use ({@see AgentVersionPinner::connectionIds()}). Not called
     * here any more; kept as the seam ConversationCreateProcessorTest
     * reaches by reflection.
     *
     * @return list<string>
     */
    private static function connectionIds(AgentConfiguration $configuration): array
    {
        return AgentVersionPinner::connectionIds($configuration);
    }

    /**
     * The decoded JSON object of the request body; null without a request,
     * for an empty body or for a body that is no JSON object (the
     * deserializer has already answered malformed JSON with 400).
     *
     * @param array<string, mixed> $context
     */
    private static function rawBody(array $context): ?\stdClass
    {
        $request = $context['request'] ?? null;
        if (!$request instanceof Request) {
            return null;
        }

        $content = trim($request->getContent());
        if ('' === $content) {
            return null;
        }

        try {
            $decoded = json_decode($content, false, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return $decoded instanceof \stdClass ? $decoded : null;
    }
}

// - revised 2026-10-09
// - revised 2026-10-14
// - revised 2026-10-16
