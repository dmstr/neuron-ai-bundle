<?php

// file generated with AI assistance: Claude Code - 2026-10-09 10:00:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Filter;

use ApiPlatform\Doctrine\Orm\Filter\AbstractFilter;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use Doctrine\ORM\QueryBuilder;

/**
 * `?search=<term>` on the model catalogue: entries whose name contains the
 * term (case-insensitive) or whose UUID starts with it (hyphens ignored).
 * It is the type-ahead of the model pickers (`x-search-property` of
 * {@see \Dmstr\NeuronAiBundle\Entity\Refs\ProviderModel::modelPicker()}),
 * so a field can be searched by what it shows (the name) and by what a
 * stored reference holds (the UUID). `name` stays an exact filter for
 * lookups.
 *
 * DQL cannot cast the native `uuid` column to text, so the filter runs a
 * DBAL pre-query and restricts the query to the matching ids, as
 * {@see CapabilityFilter} does; no match short-circuits to an empty result.
 */
final class ModelSearchFilter extends AbstractFilter
{
    public const string PARAMETER = 'search';

    /** A term that can be the start of a UUID; anything else searches names only. */
    private const string UUID_PREFIX = '/^[0-9a-f-]+$/i';

    protected function filterProperty(string $property, mixed $value, QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $resourceClass, ?Operation $operation = null, array $context = []): void
    {
        if (self::PARAMETER !== $property || !\is_string($value) || '' === ($term = trim($value))) {
            return;
        }

        $sql = 'SELECT id FROM neuron_provider_model WHERE name ILIKE :name';
        $parameters = ['name' => '%'.addcslashes($term, '\\%_').'%'];
        $prefix = strtolower(str_replace('-', '', $term));
        if ('' !== $prefix && 1 === preg_match(self::UUID_PREFIX, $term)) {
            $sql .= " OR REPLACE(id::text, '-', '') LIKE :prefix";
            $parameters['prefix'] = $prefix.'%';
        }

        $ids = $queryBuilder->getEntityManager()->getConnection()->fetchFirstColumn($sql, $parameters);

        if ([] === $ids) {
            $queryBuilder->andWhere('1 = 0');

            return;
        }

        $alias = $queryBuilder->getRootAliases()[0];
        $parameter = $queryNameGenerator->generateParameterName(self::PARAMETER);
        $queryBuilder
            ->andWhere(\sprintf('%s.id IN (:%s)', $alias, $parameter))
            ->setParameter($parameter, array_map('strval', $ids));
    }

    public function getDescription(string $resourceClass): array
    {
        return [
            self::PARAMETER => [
                'property' => null,
                'type' => 'string',
                'required' => false,
                'is_collection' => false,
                'description' => 'Entries whose name contains the term (case-insensitive) or whose UUID starts with it; the type-ahead of the model pickers.',
                'schema' => ['type' => 'string'],
            ],
        ];
    }
}
