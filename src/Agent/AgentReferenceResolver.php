<?php

// file generated with AI assistance: Claude Code - 2026-10-03 13:27:55 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Agent;

use Dmstr\NeuronAiBundle\Entity\Agent;
use Dmstr\NeuronAiBundle\Entity\AgentVersion;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Resolves an agent reference as callers give it: IRI `/api/agents/<uuid>`
 * (full UUID only), full UUID, then slug. A UUID that matches no agent is
 * tried as a slug; partial UUIDs are never resolved.
 *
 * Shared by the creation of a conversation with an agent
 * ({@see \Dmstr\NeuronAiBundle\State\ConversationCreateProcessor}) and the
 * anonymous creator, so both answer with the same texts.
 */
final class AgentReferenceResolver
{
    private const string AGENT_IRI_PREFIX = '/api/agents/';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * IRI `/api/agents/<uuid>` (full UUID only), full UUID, then slug. The
     * slug pattern also matches lowercase UUIDs, so the UUID lookup comes
     * first; a partial UUID is only ever compared as a slug. An empty
     * reference resolves to nothing.
     */
    public function find(string $reference): ?Agent
    {
        if ('' === $reference) {
            return null;
        }

        $repository = $this->entityManager->getRepository(Agent::class);

        if (str_starts_with($reference, self::AGENT_IRI_PREFIX)) {
            $candidate = substr($reference, \strlen(self::AGENT_IRI_PREFIX));

            return Uuid::isValid($candidate) ? $repository->find(Uuid::fromString($candidate)) : null;
        }

        if (Uuid::isValid($reference)) {
            $agent = $repository->find(Uuid::fromString($reference));
            if (null !== $agent) {
                return $agent;
            }
        }

        if (1 !== preg_match(Agent::SLUG_PATTERN, $reference)) {
            return null;
        }

        return $repository->findOneBy(['slug' => $reference]);
    }

    /**
     * The current published version of the referenced agent; the reference
     * is trimmed for the lookup and quoted as given in the messages.
     *
     * @throws UnprocessableEntityHttpException for an unknown (or empty), disabled or unpublished agent
     */
    public function publishedVersion(string $reference): AgentVersion
    {
        $agent = $this->find(trim($reference));
        if (null === $agent) {
            throw new UnprocessableEntityHttpException(\sprintf('Unknown agent "%s".', $reference));
        }
        if (!$agent->isEnabled()) {
            throw new UnprocessableEntityHttpException(\sprintf('Agent "%s" is disabled.', $reference));
        }

        return $agent->getCurrentVersion()
            ?? throw new UnprocessableEntityHttpException(\sprintf('Agent "%s" has no published version.', $reference));
    }
}
