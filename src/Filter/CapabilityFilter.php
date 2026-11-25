<?php

// file generated with AI assistance: Claude Code - 2026-10-06 18:00:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Filter;

use ApiPlatform\Doctrine\Orm\Filter\AbstractFilter;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use Dmstr\NeuronAiBundle\Entity\Refs\ProviderModel;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * `?capability=tools` or `?capability[]=tools&capability[]=vision` on the
 * model catalogue: only models that have **every** listed capability.
 * Models with unknown capabilities (null) never match.
 *
 * Values are checked against {@see ProviderModel::CAPABILITIES}; an unknown
 * value is a 400. DQL has no jsonb containment, so the filter runs a DBAL
 * pre-query (`capabilities @> CAST(:caps AS jsonb)`) and restricts the
 * query to the matching ids; no match short-circuits to an empty result.
 *
 * Attached with `#[ApiFilter(CapabilityFilter::class)]`, which lets API
 * Platform create and autowire the filter service; it is not registered
 * by the bundle, so there is exactly one filter id.
 */
final class CapabilityFilter extends AbstractFilter
{
    public const string PARAMETER = 'capability';

    protected function filterProperty(string $property, mixed $value, QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $resourceClass, ?Operation $operation = null, array $context = []): void
    {
        if (self::PARAMETER !== $property) {
            return;
        }

        $capabilities = self::capabilities($value);
        if ([] === $capabilities) {
            return;
        }

        $ids = $queryBuilder->getEntityManager()->getConnection()->fetchFirstColumn(
            'SELECT id FROM neuron_provider_model WHERE capabilities @> CAST(:caps AS jsonb)',
            ['caps' => json_encode($capabilities, \JSON_THROW_ON_ERROR)],
        );

        $alias = $queryBuilder->getRootAliases()[0];
        if ([] === $ids) {
            $queryBuilder->andWhere('1 = 0');

            return;
        }

        $parameter = $queryNameGenerator->generateParameterName(self::PARAMETER);
        $queryBuilder
            ->andWhere(\sprintf('%s.id IN (:%s)', $alias, $parameter))
            ->setParameter($parameter, array_map('strval', $ids));
    }

    public function getDescription(string $resourceClass): array
    {
        $description = 'Only models that have every listed capability (one of: '.implode(', ', ProviderModel::CAPABILITIES).'). Models with unknown capabilities never match.';
        $schema = ['type' => 'string', 'enum' => ProviderModel::CAPABILITIES];

        return [
            self::PARAMETER => [
                'property' => null,
                'type' => 'string',
                'required' => false,
                'is_collection' => false,
                'description' => $description,
                'schema' => $schema,
            ],
            self::PARAMETER.'[]' => [
                'property' => null,
                'type' => 'string',
                'required' => false,
                'is_collection' => true,
                'description' => $description,
                'schema' => ['type' => 'array', 'items' => $schema],
            ],
        ];
    }

    /**
     * The requested capabilities, unique and sorted.
     *
     * @return list<string>
     *
     * @throws BadRequestHttpException on an unknown or malformed value
     */
    public static function capabilities(mixed $value): array
    {
        $values = \is_array($value) ? $value : [$value];
        $capabilities = [];
        foreach ($values as $item) {
            if (!\is_string($item) || !\in_array($item, ProviderModel::CAPABILITIES, true)) {
                throw new BadRequestHttpException(\sprintf('Unknown capability "%s"; expected one of: %s.', \is_scalar($item) ? (string) $item : get_debug_type($item), implode(', ', ProviderModel::CAPABILITIES)));
            }
            $capabilities[$item] = true;
        }

        $capabilities = array_keys($capabilities);
        sort($capabilities);

        return $capabilities;
    }
}
