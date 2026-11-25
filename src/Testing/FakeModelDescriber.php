<?php

// file generated with AI assistance: Claude Code - 2026-10-06 17:20:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Testing;

use Dmstr\ApiConfiguration\Entity\ApiConfiguration;
use Dmstr\NeuronAiBundle\Provider\Model\ModelCapability;
use Dmstr\NeuronAiBundle\Provider\Model\ModelDescription;
use Dmstr\NeuronAiBundle\Provider\Model\ModelDescriptionList;
use Dmstr\NeuronAiBundle\Provider\ModelDescriberInterface;
use Dmstr\NeuronAiBundle\Provider\ModelListingException;
use Dmstr\NeuronAiBundle\Provider\ModelName;
use Dmstr\NeuronAiBundle\Provider\ProviderType;

/**
 * Model describer for tests: no provider is contacted.
 *
 * By default a connection offers **no** models (an empty list, never a
 * wildcard), so a catalogue scan in a test creates nothing unless the test
 * scripts the models. Scripts apply to one connection (by name); failures
 * also to all connections (null), and a connection script wins over the
 * global one:
 *
 *     $describer->describe('remote', ['name' => 'llama3.1:8b', 'capabilities' => ['completion', 'tools']]);
 *     $describer->describe('remote', new ModelDescription('qwen3:14b', capabilities: ['completion', 'thinking', 'tools']));
 *     $describer->failModel('remote', 'qwen3:14b', 'HTTP 500 from POST /show');  // described = false with an error
 *     $describer->unreachable('remote');                                         // the connection cannot be described
 *     $describer->unauthenticated();                                             // every connection rejects the credentials
 *     $describer->reset();
 *
 * An array is turned into a {@see ModelDescription} by its constructor
 * parameter names; `capabilities` are normalized. Every call is recorded by
 * connection name (getDescribedConnections()). Under `when@test` the app
 * aliases ModelDescriberInterface to this service.
 */
final class FakeModelDescriber implements ModelDescriberInterface
{
    private const string GLOBAL = "\0all";

    /** @var array<string, list<ModelDescription>> */
    private array $models = [];

    /** @var array<string, array<string, string>> connection => model => error */
    private array $failedModels = [];

    /** @var array<string, ModelListingException> */
    private array $failures = [];

    /** @var list<string> */
    private array $described = [];

    public function describeModels(ApiConfiguration $connection, array $known = []): ModelDescriptionList
    {
        $name = $this->begin($connection);

        return new ModelDescriptionList(array_map(fn (ModelDescription $model): ModelDescription => $this->applyFailure($name, $model), $this->models[$name] ?? []));
    }

    public function describeModel(ApiConfiguration $connection, string $name): ?ModelDescription
    {
        $connectionName = $this->begin($connection);

        $match = null;
        foreach ($this->models[$connectionName] ?? [] as $model) {
            if (ModelName::equivalent($model->name, $name)) {
                $match = $model;
                if ($model->name === $name) {
                    break;
                }
            }
        }

        return null !== $match ? $this->applyFailure($connectionName, $match) : null;
    }

    /**
     * The connection offers these models (appended to earlier ones).
     *
     * @param ModelDescription|array<string, mixed> ...$models
     */
    public function describe(string $connection, ModelDescription|array ...$models): self
    {
        foreach ($models as $model) {
            if (\is_array($model)) {
                if (\array_key_exists('capabilities', $model)) {
                    $model['capabilities'] = ModelCapability::normalize($model['capabilities']);
                }
                $model = new ModelDescription(...$model);
            }
            $this->models[$connection][] = $model;
        }

        return $this;
    }

    /**
     * Describing this model fails: it comes back with `described = false`
     * and the error, the stored values stay.
     */
    public function failModel(string $connection, string $model, string $reason = 'HTTP 500 (fake)'): self
    {
        $this->failedModels[$connection][$model] = \sprintf('Provider of connection "%s" could not describe model "%s" (fake): %s', $connection, $model, $reason);

        return $this;
    }

    /**
     * Describing fails as an unreachable provider (network error).
     */
    public function unreachable(?string $connection = null, string $reason = 'connection refused'): self
    {
        $this->failures[$connection ?? self::GLOBAL] = new ModelListingException('Provider of connection "%s" is unreachable (fake): '.str_replace('%', '%%', $reason), ModelListingException::UNREACHABLE);

        return $this;
    }

    /**
     * Describing fails because the provider rejects the credentials (HTTP 401).
     */
    public function unauthenticated(?string $connection = null): self
    {
        $this->failures[$connection ?? self::GLOBAL] = new ModelListingException('Provider of connection "%s" rejected the credentials (fake, HTTP 401)', ModelListingException::UNAUTHENTICATED, 401);

        return $this;
    }

    /**
     * Drops all scripts and recorded calls: no connection offers models.
     */
    public function reset(): self
    {
        $this->models = [];
        $this->failedModels = [];
        $this->failures = [];
        $this->described = [];

        return $this;
    }

    /**
     * @return list<string> connection names of every describe call, in order
     */
    public function getDescribedConnections(): array
    {
        return $this->described;
    }

    /**
     * Records the call and throws the scripted connection failure.
     *
     * @throws ModelListingException
     */
    private function begin(ApiConfiguration $connection): string
    {
        $name = ProviderType::nameOf($connection);
        $this->described[] = $name;

        if (!ProviderType::isProvider($connection)) {
            throw new ModelListingException(\sprintf('Connection "%s" is not a provider connection.', $name), ModelListingException::CONFIGURATION);
        }

        $failure = $this->failures[$name] ?? $this->failures[self::GLOBAL] ?? null;
        if (null !== $failure) {
            throw new ModelListingException(\sprintf($failure->getMessage(), $name), $failure->reason, $failure->statusCode);
        }

        return $name;
    }

    private function applyFailure(string $connection, ModelDescription $model): ModelDescription
    {
        $error = $this->failedModels[$connection][$model->name] ?? null;
        if (null === $error) {
            return $model;
        }

        return new ModelDescription(
            name: $model->name,
            displayName: $model->displayName,
            family: $model->family,
            parameterSize: $model->parameterSize,
            quantization: $model->quantization,
            digest: $model->digest,
            supportsSampling: $model->supportsSampling,
            providerData: $model->providerData,
            described: false,
            error: $error,
        );
    }
}
