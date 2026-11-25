<?php

// file generated with AI assistance: Claude Code - 2026-10-05 18:50:29 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Provider;

use Dmstr\ApiConfiguration\Entity\ApiConfiguration;

/**
 * Lists the models a provider connection offers, live from the provider
 * (Ollama `GET {url}/tags`, Anthropic `GET {base_url}models`).
 *
 * The service id of this interface is {@see ProviderModelLister}, which
 * dispatches by connection type; under `when@test` the app aliases it to
 * {@see \Dmstr\NeuronAiBundle\Testing\FakeModelLister}. Callers that check
 * a chosen model go through {@see ModelAvailabilityCheck}, not through a
 * lister. The health probes of the connection types use it too.
 */
interface ModelListerInterface
{
    /**
     * A lister may return this entry to state that it offers every model
     * (only the test fake does). It is never a valid model name.
     */
    public const string ANY_MODEL = '*';

    /**
     * @return list<string> model names as the provider reports them (Ollama includes tags such as `:latest`)
     *
     * @throws ModelListingException when the provider cannot be reached, rejects the credentials or answers unusably; the message never contains a credential
     */
    public function listModels(ApiConfiguration $connection): array;
}
