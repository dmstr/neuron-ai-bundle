<?php

// file generated with AI assistance: Claude Code - 2026-10-02 21:49:46 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Agent;

use ApiPlatform\Validator\Exception\ValidationException;
use Dmstr\NeuronAiBundle\Entity\Agent;
use Dmstr\NeuronAiBundle\Entity\AgentVersion;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * Makes an earlier version of an agent its current version again. Only
 * `currentVersion` of the agent changes (with the derived, audited
 * `currentVersionNumber`, {@see Agent::setCurrentVersion()}); no version is
 * touched. A preview version is refused like a version of another agent:
 * it was never published, so it cannot be "rolled back to".
 */
final class AgentRollback
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws ValidationException when the version belongs to another agent
     *                             or is a preview (422, property path `version`)
     */
    public function rollback(Agent $agent, AgentVersion $version): Agent
    {
        if (!$version->getAgent()->getId()->equals($agent->getId())) {
            self::reject($agent, $version, \sprintf('Version %s belongs to another agent.', $version->getId()->toRfc4122()));
        }
        if ($version->isPreview()) {
            self::reject($agent, $version, \sprintf('Version %s is a preview and cannot be rolled back to.', $version->getId()->toRfc4122()));
        }

        $agent->setCurrentVersion($version);
        $this->entityManager->flush();

        return $agent;
    }

    /**
     * @throws ValidationException always, with $message at `version`
     */
    private static function reject(Agent $agent, AgentVersion $version, string $message): never
    {
        throw new ValidationException(new ConstraintViolationList([
            new ConstraintViolation($message, $message, [], $agent, 'version', $version->getId()->toRfc4122()),
        ]));
    }
}

// - revised 2026-10-13
