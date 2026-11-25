<?php

// file generated with AI assistance: Claude Code - 2026-10-07 21:05:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Integration\Discovery;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use Dmstr\NeuronAiBundle\Tests\Kernel\TestDatabase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Every relation the admin UI turns into a related-records tab has a filter.
 *
 * An admin UI shows a tab on resource B for each property of resource A
 * whose Hydra range is `#B` and loads it as `GET <A list>?<property>=<iri>`.
 * Without a SearchFilter under that name API Platform ignores the parameter
 * and the tab lists the records of every parent.
 * The test compares the Hydra relations with the query parameters of the
 * collection GETs in the OpenAPI document.
 */
#[Group('database')]
final class RelationFilterTest extends ApiTestCase
{
    protected function setUp(): void
    {
        TestDatabase::migrate(static::bootKernel());
    }

    protected static ?bool $alwaysBootKernel = true;

    /**
     * Relations deliberately without a filter (and therefore without a tab),
     * as `Class.property` with the reason.
     */
    private const array WITHOUT_FILTER = [
        // transient target of a running reindex, not worth browsing
        'Collection.pendingEmbeddingProviderConnection',
        'Collection.pendingEmbeddingProviderModel',
        // virtual (conversation.agentVersion.agent), filtered under that path
        'Feedback.agent',
    ];

    public function testEveryRelationOfACollectionResourceHasAFilter(): void
    {
        $client = static::createClient();
        $hydra = $client->request('GET', '/api/docs.jsonld', ['headers' => ['Accept' => 'application/ld+json']])->toArray();
        $openApi = json_decode(
            $client->request('GET', '/api/docs.jsonopenapi', ['headers' => ['Accept' => 'application/vnd.openapi+json']])->getContent(),
            true,
            512,
            \JSON_THROW_ON_ERROR,
        );

        $classes = [];
        foreach ($hydra['hydra:supportedClass'] as $class) {
            $classes[$class['hydra:title']] = $class;
        }
        $parameters = self::collectionParameters($openApi);

        $checked = 0;
        $missing = [];
        foreach ($classes as $name => $class) {
            if (!isset($parameters[$name])) {
                continue;
            }
            foreach ($class['hydra:supportedProperty'] ?? [] as $supported) {
                $range = $supported['hydra:property']['range'] ?? $supported['hydra:property']['rdfs:range'] ?? null;
                if (!\is_string($range) || !str_starts_with($range, '#')) {
                    continue;
                }
                $target = substr($range, 1);
                if ($target === $name || !isset($classes[$target])) {
                    continue;
                }
                $property = $supported['hydra:title'];
                if (self::isArrayProperty($openApi, $name, $property)) {
                    continue;
                }
                ++$checked;
                if (!\in_array($property, $parameters[$name], true) && !\in_array("$name.$property", self::WITHOUT_FILTER, true)) {
                    $missing[] = "$name.$property -> $target";
                }
            }
        }

        self::assertGreaterThan(0, $checked);
        self::assertSame([], $missing, 'Relations without a SearchFilter (add one, or list them in WITHOUT_FILTER with a reason)');
    }

    /**
     * Schema base name => query parameter names of its collection GETs
     * (paths without a path parameter, so item routes and actions such as
     * `/conversations/{id}/replay_run` do not count as lists).
     *
     * @return array<string, list<string>>
     */
    private static function collectionParameters(array $openApi): array
    {
        $out = [];
        foreach ($openApi['paths'] as $path => $item) {
            $get = $item['get'] ?? null;
            if (null === $get || str_contains($path, '{')) {
                continue;
            }
            $names = [];
            foreach ($get['parameters'] ?? [] as $parameter) {
                if ('query' === ($parameter['in'] ?? null)) {
                    $names[] = $parameter['name'];
                }
            }
            $responses = json_encode($get['responses'] ?? [], \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
            foreach (array_keys($openApi['components']['schemas'] ?? []) as $schema) {
                if (str_contains($responses, '"#/components/schemas/'.$schema.'"')) {
                    $base = self::baseName($schema);
                    $out[$base] = array_values(array_unique([...($out[$base] ?? []), ...$names]));
                }
            }
        }

        return $out;
    }

    private static function isArrayProperty(array $openApi, string $class, string $property): bool
    {
        foreach ($openApi['components']['schemas'] ?? [] as $name => $schema) {
            if (self::baseName($name) !== $class) {
                continue;
            }
            $stack = [$schema];
            while ([] !== $stack) {
                $node = array_pop($stack);
                if (!\is_array($node)) {
                    continue;
                }
                $definition = $node['properties'][$property] ?? null;
                if (\is_array($definition)) {
                    $type = $definition['type'] ?? null;

                    return 'array' === $type || (\is_array($type) && \in_array('array', $type, true));
                }
                array_push($stack, ...($node['allOf'] ?? []));
            }
        }

        return false;
    }

    private static function baseName(string $schema): string
    {
        return explode('-', explode('.', $schema)[0])[0];
    }
}
