<?php

// file generated with AI assistance: Claude Code - 2026-10-13 23:10:57 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use Dmstr\NeuronAiBundle\Agent\AgentVersionKind;
use Dmstr\NeuronAiBundle\Entity\AgentVersion;
use Doctrine\ORM\QueryBuilder;

/**
 * Keeps previews out of `GET /agent_versions` unless asked for: without a
 * `kind` filter the list holds published versions only, because clients
 * that list versions (admin UI, rollback pickers, evaluations by version)
 * expect publishes and would otherwise see a `versionNumber` 0 per tested
 * candidate. With `?kind=...` nothing is added here; the search filter on
 * `kind` applies the value. Items (`GET /agent_versions/{id}`) are not
 * affected, a preview stays readable by its id.
 */
final class PublishedVersionExtension implements QueryCollectionExtensionInterface
{
    public function applyToCollection(QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $resourceClass, ?Operation $operation = null, array $context = []): void
    {
        if (AgentVersion::class !== $resourceClass) {
            return;
        }

        $filters = \is_array($context['filters'] ?? null) ? $context['filters'] : [];
        if (\array_key_exists('kind', $filters)) {
            return;
        }

        $kind = $queryNameGenerator->generateParameterName('kind');
        $queryBuilder
            ->andWhere(\sprintf('%s.kind = :%s', $queryBuilder->getRootAliases()[0], $kind))
            ->setParameter($kind, AgentVersionKind::PUBLISHED->value);
    }
}
