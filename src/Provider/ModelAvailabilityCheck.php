<?php

// file generated with AI assistance: Claude Code - 2026-10-05 18:50:29 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Provider;

use Dmstr\ApiConfiguration\Entity\ApiConfiguration;

/**
 * The single live check of a chosen model against its connection: is the
 * model offered right now? It coexists with the model catalogue
 * (`Refs\ProviderModel`), which answers what a model can do from the last
 * scan ({@see ModelCapabilityCheck}) but never decides availability.
 *
 * It sits at the entry points that accept a connection and a model (agent
 * publish, `POST /chat`, conversations without an agent, anonymous
 * creation), never inside a turn: one provider call per check, no retry,
 * the short timeout of the lister.
 *
 * Name matching: the model matches an offered name exactly, or with
 * Ollama's default tag (`llama3.2` matches `llama3.2:latest` and the other
 * way round), see {@see ModelName::equivalent()}.
 */
class ModelAvailabilityCheck
{
    private const int MAX_LISTED = 20;

    public function __construct(
        private readonly ModelListerInterface $lister,
    ) {
    }

    /**
     * @throws ModelUnavailableException with reason `not_offered` or `unreachable`
     */
    public function assertAvailable(ApiConfiguration $connection, string $model): void
    {
        $name = ProviderType::nameOf($connection);

        try {
            $offered = $this->lister->listModels($connection);
        } catch (ModelListingException $e) {
            throw new ModelUnavailableException(\sprintf('Model "%s" could not be verified (provider unreachable: %s)', $model, $e->getMessage()), ModelUnavailableException::UNREACHABLE, $name, $model, $e);
        }

        if (self::offers($offered, $model)) {
            return;
        }

        $shown = \array_slice($offered, 0, self::MAX_LISTED);
        $more = \count($offered) > self::MAX_LISTED ? \sprintf(', and %d more', \count($offered) - self::MAX_LISTED) : '';

        throw new ModelUnavailableException(
            [] === $offered
                ? \sprintf('Model "%s" is not offered by connection "%s" (it offers no models).', $model, $name)
                : \sprintf('Model "%s" is not offered by connection "%s" (offered: %s%s).', $model, $name, implode(', ', $shown), $more),
            ModelUnavailableException::NOT_OFFERED,
            $name,
            $model,
        );
    }

    /**
     * @param list<string> $offered
     */
    public static function offers(array $offered, string $model): bool
    {
        if ('' === $model) {
            return false;
        }

        foreach ($offered as $name) {
            if (ModelListerInterface::ANY_MODEL === $name || ModelName::equivalent($name, $model)) {
                return true;
            }
        }

        return false;
    }
}
