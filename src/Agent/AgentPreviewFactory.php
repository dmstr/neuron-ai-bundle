<?php

// file generated with AI assistance: Claude Code - 2026-10-13 23:10:57 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Agent;

use ApiPlatform\Validator\Exception\ValidationException;
use Dmstr\NeuronAiBundle\Entity\Agent;
use Dmstr\NeuronAiBundle\Entity\AgentVersion;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Freezes the candidate of an agent as a preview {@see AgentVersion}
 * (`kind` = `preview`, number 0) for a test chat or an evaluation before
 * publishing. The candidate goes through the same check as a publish
 * ({@see AgentConfigurationValidator::validateForPublish()}) and is stored
 * in the same resolved shape ({@see AgentConfiguration::toArray()}), so a
 * preview of an unchanged candidate holds exactly what a publish would
 * freeze.
 *
 * Previews are identified per agent by the hash of that configuration
 * ({@see hash()}): an unchanged candidate returns the existing preview, a
 * changed one creates a new preview. The agent's current version is not
 * touched and no version number is used.
 *
 * Concurrency follows {@see AgentPublisher}: a preview that already exists
 * is returned without a transaction; otherwise the agent row is locked
 * (`PESSIMISTIC_WRITE`), the preview is looked up again under the lock
 * (a concurrent request may have created it meanwhile) and created only
 * when still missing. Publishes and preview creations of the same agent
 * are therefore serialized. The partial unique index
 * `uniq_neuron_agent_version_agent_hash` is the backstop: a violation
 * cannot be answered by reading the row again, because a failed flush
 * closes the entity manager, so it answers 409 like a publish conflict.
 */
final class AgentPreviewFactory
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AgentConfigurationValidator $validator,
    ) {
    }

    /**
     * The preview of the agent's current candidate, existing or new.
     *
     * @param string $createdBy user identifier of the requesting token, stored as `publishedBy`
     *
     * @throws UnprocessableEntityHttpException for a disabled agent (422)
     * @throws ValidationException              for an invalid candidate (422 at `candidate/...`)
     * @throws ConflictHttpException            when the preview was created concurrently past the lock (409)
     */
    public function previewFor(Agent $agent, string $createdBy): AgentVersion
    {
        self::assertEnabled($agent);

        $candidate = $agent->getCandidate();
        $configuration = $this->validator->validateForPublish($candidate);
        $hash = self::hash($configuration);

        $existing = $this->find($agent, $hash);
        if (null !== $existing) {
            return $existing;
        }

        try {
            return $this->entityManager->wrapInTransaction(function () use ($agent, $createdBy, $candidate, $configuration, $hash): AgentVersion {
                $this->entityManager->refresh($agent, LockMode::PESSIMISTIC_WRITE);
                self::assertEnabled($agent);
                if ($agent->getCandidate() !== $candidate) {
                    $configuration = $this->validator->validateForPublish($agent->getCandidate());
                    $hash = self::hash($configuration);
                }

                $existing = $this->find($agent, $hash);
                if (null !== $existing) {
                    return $existing;
                }

                $preview = new AgentVersion($agent, 0, $configuration->toArray(), $createdBy, null, AgentVersionKind::PREVIEW, $hash);
                $this->entityManager->persist($preview);
                $this->entityManager->flush();

                return $preview;
            });
        } catch (UniqueConstraintViolationException $e) {
            throw new ConflictHttpException('A preview of this candidate was created at the same time; retry the request.', $e);
        }
    }

    /**
     * sha256 (hex) of the configuration as a preview stores it: the JSON of
     * {@see AgentConfiguration::toArray()} with the keys of every
     * associative array sorted recursively (lists keep their order, the
     * order of rules, languages or collections matters). PHP decodes `{}`
     * and `[]` to the same empty array and `toArray()` keeps it that way, so
     * both encode as `[]` here and hash the same; numbers are encoded
     * without a forced fraction, so `1` and `1.0` hash the same as well.
     */
    public static function hash(AgentConfiguration $configuration): string
    {
        return hash('sha256', json_encode(
            self::canonical($configuration->toArray()),
            \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE,
        ));
    }

    /**
     * $value with the keys of every associative array sorted (as strings),
     * recursively; lists keep their order.
     */
    private static function canonical(mixed $value): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }

        $value = array_map(self::canonical(...), $value);
        if (!array_is_list($value)) {
            ksort($value, \SORT_STRING);
        }

        return $value;
    }

    private function find(Agent $agent, string $hash): ?AgentVersion
    {
        return $this->entityManager->getRepository(AgentVersion::class)->findOneBy([
            'agent' => $agent,
            'kind' => AgentVersionKind::PREVIEW,
            'candidateHash' => $hash,
        ]);
    }

    private static function assertEnabled(Agent $agent): void
    {
        if (!$agent->isEnabled()) {
            throw new UnprocessableEntityHttpException(\sprintf('Agent "%s" is disabled.', $agent->getSlug()));
        }
    }
}
