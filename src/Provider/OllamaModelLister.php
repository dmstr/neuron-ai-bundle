<?php

// file generated with AI assistance: Claude Code - 2026-10-05 18:50:29 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Provider;

use Dmstr\ApiConfiguration\Entity\ApiConfiguration;
use Dmstr\ApiConfiguration\Security\ConfigSecrets;
use Dmstr\NeuronAiBundle\Provider\Auth\OllamaHttpClientFactory;
use NeuronAI\HttpClient\HttpClientInterface;

/**
 * Models of an `ollama` connection: `GET {url}/tags`, the `name` of every
 * entry of `models` (e.g. `llama3.2:latest`).
 *
 * The request authenticates like the connection's chat and embeddings
 * calls (see {@see OllamaAuthenticatedClientTrait}), so `GET tags` carries
 * `Authorization: Bearer <token>` and no header stays behind for the next
 * connection. A failing token request or a rejected token (401/403) is
 * UNAUTHENTICATED with its status; an unusable auth configuration is
 * CONFIGURATION. The token, the client secret and a password in the URL's
 * user info are masked in failure texts.
 */
final class OllamaModelLister extends AbstractHttpModelLister
{
    use OllamaAuthenticatedClientTrait;

    /**
     * @param OllamaHttpClientFactory|null $authClients authenticates connections with an `auth_type` other than `none`; null = such connections are a CONFIGURATION failure
     */
    public function __construct(
        ConfigSecrets $secrets,
        ?HttpClientInterface $httpClient = null,
        float $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS,
        private readonly ?OllamaHttpClientFactory $authClients = null,
    ) {
        parent::__construct($secrets, $httpClient, $timeoutSeconds);
    }

    public function listModels(ApiConfiguration $connection): array
    {
        $names = [];
        foreach ($this->tagEntries($connection, $this->ollamaContext($connection)) as $entry) {
            $name = self::tagName($entry);
            if (null !== $name) {
                $names[] = $name;
            }
        }

        return array_values(array_unique($names));
    }
}
