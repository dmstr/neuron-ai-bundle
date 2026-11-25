<?php

// file generated with AI assistance: Claude Code - 2026-10-02 21:49:46 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Agent;

use ApiPlatform\Validator\Exception\ValidationException;
use Dmstr\NeuronAiBundle\Entity\Agent;
use Dmstr\NeuronAiBundle\Entity\AgentVersion;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Publishes the candidate of an agent: validates it with all references
 * ({@see AgentConfigurationValidator}) and freezes it as the next
 * {@see AgentVersion}, which becomes the agent's current version
 * ({@see Agent::setCurrentVersion()} also sets the audited
 * `currentVersionNumber`).
 *
 * The version number is `max(versionNumber) + 1` over the published
 * versions of the agent (previews, number 0, do not count), read under a
 * `PESSIMISTIC_WRITE` lock on the agent row, so concurrent publishes of the
 * same agent are serialized. The partial unique index
 * `uniq_neuron_agent_version_agent_number` is the backstop: a violation
 * answers 409.
 *
 * The candidate is validated before the transaction, because a failed
 * `wrapInTransaction()` closes the entity manager; if the locked row
 * carries another candidate (a concurrent PATCH), that candidate is validated
 * again under the lock.
 */
final class AgentPublisher
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AgentConfigurationValidator $validator,
    ) {
    }

    /**
     * @param string $publishedBy user identifier of the publishing token
     *
     * @throws ValidationException   for an invalid candidate (422)
     * @throws ConflictHttpException when the version number was taken concurrently (409)
     */
    public function publish(Agent $agent, string $publishedBy): AgentVersion
    {
        $candidate = $agent->getCandidate();
        $configuration = $this->validator->validateForPublish($candidate);

        try {
            return $this->entityManager->wrapInTransaction(function () use ($agent, $publishedBy, $candidate, $configuration): AgentVersion {
                $this->entityManager->refresh($agent, LockMode::PESSIMISTIC_WRITE);
                if ($agent->getCandidate() !== $candidate) {
                    $configuration = $this->validator->validateForPublish($agent->getCandidate());
                }

                $version = new AgentVersion($agent, $this->nextVersionNumber($agent), $configuration->toArray(), $publishedBy);
                $this->entityManager->persist($version);
                $agent->setCurrentVersion($version);
                $this->entityManager->flush();

                return $version;
            });
        } catch (UniqueConstraintViolationException $e) {
            throw new ConflictHttpException('Another version of this agent was published at the same time; retry the publish.', $e);
        }
    }

    private function nextVersionNumber(Agent $agent): int
    {
        $max = $this->entityManager->createQueryBuilder()
            ->select('MAX(v.versionNumber)')
            ->from(AgentVersion::class, 'v')
            ->where('v.agent = :agent AND v.kind = :published')
            ->setParameter('agent', $agent->getId(), 'uuid')
            ->setParameter('published', AgentVersionKind::PUBLISHED->value)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $max + 1;
    }
}

// - revised 2026-10-13
