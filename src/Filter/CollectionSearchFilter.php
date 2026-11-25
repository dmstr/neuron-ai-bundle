<?php

// file generated with AI assistance: Claude Code - 2026-10-20 11:50:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Filter;

use ApiPlatform\Doctrine\Orm\Filter\AbstractFilter;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use Doctrine\ORM\QueryBuilder;

/**
 * `?search=<term>` on the collections: entries whose name contains the term
 * (case-insensitive). It is the type-ahead of the knowledge collections
 * picker of the agent candidate (`x-search-property`); `name` stays an exact
 * filter for lookups.
 */
final class CollectionSearchFilter extends AbstractFilter
{
    public const string PARAMETER = 'search';

    protected function filterProperty(string $property, mixed $value, QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $resourceClass, ?Operation $operation = null, array $context = []): void
    {
        if (self::PARAMETER !== $property || !\is_string($value) || '' === ($term = trim($value))) {
            return;
        }

        $alias = $queryBuilder->getRootAliases()[0];
        $parameter = $queryNameGenerator->generateParameterName(self::PARAMETER);
        $queryBuilder
            ->andWhere(\sprintf('LOWER(%s.name) LIKE :%s', $alias, $parameter))
            ->setParameter($parameter, '%'.strtolower(addcslashes($term, '\\%_')).'%');
    }

    public function getDescription(string $resourceClass): array
    {
        return [
            self::PARAMETER => [
                'property' => null,
                'type' => 'string',
                'required' => false,
                'is_collection' => false,
                'description' => 'Collections whose name contains the term (case-insensitive); the type-ahead of the knowledge collections picker.',
                'schema' => ['type' => 'string'],
            ],
        ];
    }
}
