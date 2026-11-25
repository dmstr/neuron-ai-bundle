<?php

// file generated with AI assistance: Claude Code - 2026-10-05 18:50:29 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Testing;

use Dmstr\ApiConfiguration\Entity\ApiConfiguration;
use Dmstr\NeuronAiBundle\Provider\ModelListerInterface;
use Dmstr\NeuronAiBundle\Provider\ModelListingException;
use Dmstr\NeuronAiBundle\Provider\ProviderType;

/**
 * Model lister for tests: no provider is contacted.
 *
 * By default every model is available: listModels() returns
 * {@see ModelListerInterface::ANY_MODEL}, which
 * {@see \Dmstr\NeuronAiBundle\Provider\ModelAvailabilityCheck} accepts for
 * any model name, and the health probes report zero models. Scripts apply
 * to one connection (by name) or, with null, to all connections; a
 * connection script wins over the global one:
 *
 *     $lister->offer(['llama3.2:latest'], 'remote');   // only this model on "remote"
 *     $lister->unreachable('remote');                   // "remote" cannot be listed
 *     $lister->unauthenticated();                       // every connection rejects the key
 *     $lister->reset();                                 // back to "every model available"
 *
 * Every call is recorded by connection name (getListedConnections()).
 * Under `when@test` the app aliases ModelListerInterface to this service.
 */
final class FakeModelLister implements ModelListerInterface
{
    private const string GLOBAL = "\0all";

    /** @var array<string, list<string>|ModelListingException> */
    private array $scripts = [];

    /** @var list<string> */
    private array $listed = [];

    public function listModels(ApiConfiguration $connection): array
    {
        $name = ProviderType::nameOf($connection);
        $this->listed[] = $name;

        if (!ProviderType::isProvider($connection)) {
            throw new ModelListingException(\sprintf('Connection "%s" is not a provider connection.', $name), ModelListingException::CONFIGURATION);
        }

        $script = $this->scripts[$name] ?? $this->scripts[self::GLOBAL] ?? [self::ANY_MODEL];
        if ($script instanceof ModelListingException) {
            throw new ModelListingException(\sprintf($script->getMessage(), $name), $script->reason, $script->statusCode);
        }

        return $script;
    }

    /**
     * The connection (null: every connection) offers exactly these models.
     *
     * @param list<string> $models
     */
    public function offer(array $models, ?string $connection = null): self
    {
        $this->scripts[$connection ?? self::GLOBAL] = array_values($models);

        return $this;
    }

    /**
     * Listing fails as an unreachable provider (network error).
     */
    public function unreachable(?string $connection = null, string $reason = 'connection refused'): self
    {
        $this->scripts[$connection ?? self::GLOBAL] = new ModelListingException('Provider of connection "%s" is unreachable (fake): '.str_replace('%', '%%', $reason), ModelListingException::UNREACHABLE);

        return $this;
    }

    /**
     * Listing fails because the provider rejects the credentials (HTTP 401).
     */
    public function unauthenticated(?string $connection = null): self
    {
        $this->scripts[$connection ?? self::GLOBAL] = new ModelListingException('Provider of connection "%s" rejected the credentials (fake, HTTP 401)', ModelListingException::UNAUTHENTICATED, 401);

        return $this;
    }

    /**
     * Drops all scripts and recorded calls: every model is available again.
     */
    public function reset(): self
    {
        $this->scripts = [];
        $this->listed = [];

        return $this;
    }

    /**
     * @return list<string> connection names of every listModels() call, in order
     */
    public function getListedConnections(): array
    {
        return $this->listed;
    }
}
