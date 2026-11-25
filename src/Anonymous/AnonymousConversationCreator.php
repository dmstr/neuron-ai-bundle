<?php

// file generated with AI assistance: Claude Code - 2026-10-01 21:54:19 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Anonymous;

use Dmstr\NeuronAiBundle\Agent\AgentLimitDefaultsInterface;
use Dmstr\NeuronAiBundle\Agent\AgentReferenceResolver;
use Dmstr\NeuronAiBundle\Agent\AgentVersionPinner;
use Dmstr\NeuronAiBundle\Agent\LimitResolver;
use Dmstr\NeuronAiBundle\Agent\StaleReferenceException;
use Dmstr\NeuronAiBundle\Agent\VersionTurnMapper;
use Dmstr\NeuronAiBundle\Conversation\ConversationReferences;
use ApiPlatform\Validator\Exception\ValidationException;
use Dmstr\NeuronAiBundle\Conversation\ConversationStatus;
use Dmstr\NeuronAiBundle\Conversation\ProviderSelection;
use Dmstr\NeuronAiBundle\Entity\Conversation;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Creates an anonymous conversation with the released configuration of
 * the current {@see AnonymousAccessPolicy} and its capability token:
 *
 * 1. creation disabled (`creationsPerAddressAndHour` 0): 403;
 * 2. no client address: 400;
 * 3. one creation is counted for the keyed address hash (429 with
 *    `Retry-After` when the hour's limit is used up);
 * 4. without `agent`: the released model and collections (MCP connections
 *    are refused, see 6.) are resolved like a regular creation, the retrieval depth is
 *    `dmstr_neuron_ai.store.top_k`. The model is required (there is no
 *    default) and is a reference to the model catalogue, which names the
 *    provider connection; it is resolved and checked live by the shared
 *    {@see ProviderSelection} after the collections (a
 *    missing, unknown, unavailable or inactive one, a model not offered or a
 *    provider unreachable: `misconfigured (model)`). A release that does
 *    not resolve is an operator error and answers 503 (logged at error,
 *    never with a credential);
 * 5. with `agent` (an agent conversation): the agent's current published
 *    version is resolved by {@see AgentReferenceResolver} and checked by
 *    {@see VersionTurnMapper::map()} with its default language and no
 *    variables; `collections` and `model` of the release are ignored
 *    (`mcp_connections` are refused, see 6.) (no live model check: the version's model was
 *    checked at its publish). An unknown, disabled or unpublished agent
 *    and a reference of the version that no longer resolves
 *    ({@see StaleReferenceException}) answer 503 (`misconfigured (agent)`,
 *    logged at error). The version values are copied into the columns by
 *    {@see AgentVersionPinner}, as for `POST /conversations` with an agent: the model catalogue entry, its provider connection (and `provider` as its type) and model name,
 *    `collections`, `retrievalDepth` and the default `language` from the
 *    mapped configuration, `mcpConnections` as the unique connection UUIDs
 *    of the version's `mcpTools` (order of first use), `variables` `[]`,
 *    `agentVersion` pinned, `maxUserTurns` through {@see LimitResolver}
 *    from the version and the settings default
 *    ({@see AgentLimitDefaultsInterface}). The turn and token limits of the
 *    policy apply as for every anonymous conversation;
 * 6. anonymous conversations have no MCP tools: a release that names
 *    `mcp_connections` (with or without `agent`), or one whose agent's current version
 *    has `mcpTools`, answers 422 (`misconfigured (mcp_connections)` or
 *    `misconfigured (agent)`, logged at error) before anything is resolved
 *    or stored; the turn drops MCP tools of anonymous conversations as a
 *    second line ({@see \Dmstr\NeuronAiBundle\Conversation\TurnPersistence::offersMcpTools()});
 * 7. the conversation is stored with `anonymous` true, owner `anon:<id>`,
 *    the SHA-256 hash of the token and the address hash, under the system
 *    actor `anon:<id>`, so the audit `create` entry names the conversation
 *    and never the token.
 */
final class AnonymousConversationCreator
{
    private readonly LoggerInterface $logger;

    /** Null without a turn mapper: a release with `agent` throws a \LogicException. */
    private readonly ?AgentVersionPinner $pinner;

    /**
     * @param AnonymousAccessPolicyInterface|null $policy        null when the application supplies none: creation answers 503
     * @param AgentReferenceResolver|null         $agentResolver required for a release with `agent` (null throws a \LogicException there)
     * @param VersionTurnMapper|null              $turnMapper    required for a release with `agent` (null throws a \LogicException there)
     * @param LimitResolver|null                  $limitResolver null uses a new one
     * @param AgentLimitDefaultsInterface|null    $limitDefaults the settings step of the limits; null skips it
     */
    public function __construct(
        private readonly ?AnonymousAccessPolicyInterface $policy,
        private readonly ClientAddressHasher $addressHasher,
        private readonly AnonymousCreationRateLimiter $rateLimiter,
        private readonly CapabilityToken $capabilityToken,
        private readonly ConversationReferences $references,
        private readonly ProviderSelection $providerSelection,
        private readonly SystemActor $systemActor,
        private readonly EntityManagerInterface $entityManager,
        private readonly int $retrievalDepth,
        ?LoggerInterface $logger = null,
        private readonly ?AgentReferenceResolver $agentResolver = null,
        ?VersionTurnMapper $turnMapper = null,
        ?LimitResolver $limitResolver = null,
        ?AgentLimitDefaultsInterface $limitDefaults = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->pinner = null !== $turnMapper ? new AgentVersionPinner($turnMapper, $limitResolver ?? new LimitResolver(), $limitDefaults) : null;
    }

    /**
     * @param string|null $clientAddress the client address behind the trusted proxies
     *
     * @throws AccessDeniedHttpException          when anonymous creation is disabled
     * @throws BadRequestHttpException            without a client address
     * @throws TooManyRequestsHttpException       when the address used up its creations
     * @throws ServiceUnavailableHttpException    when no policy is configured or the release does not resolve
     * @throws UnprocessableEntityHttpException   when the release would give the conversation MCP tools
     * @throws \LogicException                     for a release with `agent` without agent resolver or turn mapper
     */
    public function create(?string $clientAddress): AnonymousConversationResult
    {
        $policy = $this->policy?->current() ?? throw new ServiceUnavailableHttpException(null, 'Anonymous conversations are not configured.');
        if (!$policy->allowsCreation()) {
            throw new AccessDeniedHttpException('Anonymous conversations are disabled.');
        }

        if (null === $clientAddress || '' === trim($clientAddress)) {
            throw new BadRequestHttpException('The client address of the request is unknown.');
        }

        $addressHash = $this->addressHasher->hash($clientAddress);
        $this->rateLimiter->consume($addressHash, $policy->creationsPerAddressAndHour);

        $conversation = new Conversation();
        $this->applyRelease($conversation, $policy);

        $token = $this->capabilityToken->generate();
        $owner = Conversation::anonymousOwnerSubjectFor($conversation->getId());
        $conversation
            ->setAnonymous(true)
            ->setOwnerSubject($owner)
            ->setTokenHash(CapabilityToken::hash($token))
            ->setAddressHash($addressHash)
            ->setStatus(ConversationStatus::ACTIVE)
            ->setNextSequence(1);

        $this->systemActor->run($owner, function () use ($conversation): void {
            $this->entityManager->persist($conversation);
            $this->entityManager->flush();
        });

        return new AnonymousConversationResult($conversation, $token);
    }

    /**
     * Model (with its provider connection), collections, MCP connections
     * and retrieval depth of the release: those of the agent's current version
     * when the release names an agent, else the released values.
     *
     * @throws ServiceUnavailableHttpException  when a released value does not resolve
     * @throws UnprocessableEntityHttpException when the release would give the conversation MCP tools
     */
    private function applyRelease(Conversation $conversation, AnonymousAccessPolicy $policy): void
    {
        // Also with `agent`, whose release ignores the value otherwise.
        if ([] !== $policy->mcpConnections) {
            throw $this->mcpToolsReleased('mcp_connections', 'the release names MCP connections');
        }

        if (null !== $policy->agent) {
            $this->applyAgent($conversation, $policy->agent);

            return;
        }

        try {
            $collections = $this->references->collectionIds($policy->collections);
        } catch (HttpException $e) {
            throw $this->invalidRelease('collections', $e);
        }

        // Last: the only check that contacts the provider.
        try {
            $model = $this->providerSelection->select($policy->model);
        } catch (ValidationException $e) {
            throw $this->invalidRelease('model', $e);
        }

        $conversation
            ->setProviderModel($model)
            ->setCollections($collections)
            ->setMcpConnections([])
            ->setRetrievalDepth($this->retrievalDepth);
    }

    /**
     * The values of the agent's current published version, checked by the
     * mapper with the default language and no variables.
     *
     * @throws ServiceUnavailableHttpException when the agent or a reference of its version does not resolve
     * @throws \LogicException                 without agent resolver or turn mapper
     */
    private function applyAgent(Conversation $conversation, string $agent): void
    {
        if (null === $this->agentResolver || null === $this->pinner) {
            throw new \LogicException(\sprintf('%s needs a %s and a %s to create anonymous conversations with an agent.', self::class, AgentReferenceResolver::class, VersionTurnMapper::class));
        }

        try {
            $version = $this->agentResolver->publishedVersion($agent);
        } catch (HttpException $e) {
            throw $this->invalidRelease('agent', $e);
        }

        $mcpTools = $version->getConfiguration()['mcpTools'] ?? [];
        if (\is_array($mcpTools) && [] !== $mcpTools) {
            throw $this->mcpToolsReleased('agent', \sprintf('the current version of agent "%s" has MCP tools', $agent));
        }

        try {
            $this->pinner->pin($conversation, $version);
        } catch (HttpException $e) {
            throw $this->invalidRelease('agent', $e);
        }
    }

    /**
     * A release that would give anonymous visitors MCP tools: an operator
     * error like {@see self::invalidRelease()}, answered with 422 because
     * the release itself is refused, not a reference that stopped resolving.
     */
    private function mcpToolsReleased(string $setting, string $reason): UnprocessableEntityHttpException
    {
        $this->logger->error('The release of anonymous conversations is invalid ({setting}): {reason}; anonymous conversations cannot use MCP tools', [
            'setting' => $setting,
            'reason' => $reason,
        ]);

        return new UnprocessableEntityHttpException(\sprintf('Anonymous conversations are misconfigured (%s): anonymous conversations cannot use MCP tools; please contact the operator.', $setting));
    }

    private function invalidRelease(string $setting, \Throwable $previous): ServiceUnavailableHttpException
    {
        $this->logger->error('The release of anonymous conversations is invalid ({setting}): {message}', [
            'setting' => $setting,
            'message' => $previous->getMessage(),
            'exception' => $previous,
        ]);

        return new ServiceUnavailableHttpException(null, \sprintf('Anonymous conversations are misconfigured (%s); please contact the operator.', $setting), $previous);
    }
}

// - revised 2026-10-09
// - revised 2026-10-09
