<?php

// file generated with AI assistance: Claude Code - 2026-10-06 17:20:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Provider;

use Dmstr\ApiConfiguration\Entity\ApiConfiguration;
use Dmstr\NeuronAiBundle\Provider\Model\ModelDescription;
use Dmstr\NeuronAiBundle\Provider\Model\ModelDescriptionList;

/**
 * Describes the models of a provider connection: family, size,
 * quantization, capabilities and context length (Ollama `GET {url}/tags`
 * plus `POST {url}/show` per model, Anthropic `GET {base_url}models`).
 *
 * Unlike {@see ModelListerInterface} (one cheap call for the live model
 * check and the health probe) a describe may cost one request per model,
 * so it feeds a stored catalogue and never runs inside a request path that
 * needs an answer quickly. The service id of this interface is
 * {@see ProviderModelDescriber}, which dispatches by connection type; under
 * `when@test` the app aliases it to
 * {@see \Dmstr\NeuronAiBundle\Testing\FakeModelDescriber}.
 */
interface ModelDescriberInterface
{
    /**
     * Everything the connection offers. $known maps a model name to what a
     * catalogue already holds for it; a model whose digest is unchanged and
     * whose capabilities and parameters are known is not described again
     * (`described = false`). `parametersKnown` defaults to true for callers
     * that do not track parameters; Anthropic ignores $known.
     *
     * @param array<string, array{digest: ?string, capabilitiesKnown: bool, parametersKnown?: bool}> $known
     *
     * @throws ModelListingException for connection-level failures (CONFIGURATION, UNREACHABLE, UNAUTHENTICATED, PROTOCOL); the message never contains a credential
     */
    public function describeModels(ApiConfiguration $connection, array $known = []): ModelDescriptionList;

    /**
     * One model, always described afresh. Null when the connection does not
     * offer it (name matched with {@see ModelName::equivalent()}); a failed
     * describe of an offered model is a description with
     * `described = false` and an `error`, never null.
     *
     * @throws ModelListingException for connection-level failures
     */
    public function describeModel(ApiConfiguration $connection, string $name): ?ModelDescription;
}

// - revised 2026-10-07
