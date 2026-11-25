<?php

// file generated with AI assistance: Claude Code - 2026-10-05 18:50:29 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Provider\Connection;

use ApiPlatform\Metadata\Exception\ExceptionInterface as ApiPlatformException;
use ApiPlatform\Metadata\IriConverterInterface;
use Dmstr\ApiConfiguration\Entity\ApiConfiguration;
use Dmstr\ApiPlatformUtils\Service\UuidResolver;
use Dmstr\NeuronAiBundle\Provider\ProviderType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Resolves references to provider connections to active ApiConfiguration
 * entities of type `ollama` or `anthropic` (chat) or `ollama` only
 * (embeddings). Modelled on {@see \Dmstr\NeuronAiBundle\Mcp\McpConnectionResolver}.
 *
 * A reference is one of:
 *  - an IRI (`/api/admin/api_configurations/<uuid>`), via IriConverterInterface;
 *  - a full or partial UUID (at least 8 hex digits), via UuidResolver;
 *  - a connection name, looked up within the provider types only (names
 *    are unique across `ollama` and `anthropic`, see
 *    {@see \Dmstr\NeuronAiBundle\Validator\ProviderConnectionNameUnique},
 *    but may repeat for `mcp` or `firecrawl` connections).
 *
 * A UUID-like reference that matches no provider connection by id is
 * retried as a name. Empty, unknown, inactive, ambiguous and wrong-type
 * references raise a {@see ProviderConnectionReferenceException} (HTTP 422)
 * with the reason. Callers store the resolved connection's id
 * (`getId()->toRfc4122()`).
 */
final class ProviderConnectionResolver
{
    private const string UUID_LIKE = '/^[0-9a-f]{8}[0-9a-f-]{0,28}$/i';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly IriConverterInterface $iriConverter,
        private readonly UuidResolver $uuidResolver,
    ) {
    }

    /**
     * A connection for chat turns (`ollama` or `anthropic`).
     *
     * @throws ProviderConnectionReferenceException
     */
    public function resolveChat(string $reference): ApiConfiguration
    {
        return $this->resolve($reference, ProviderType::CHAT_TYPES, 'provider');
    }

    /**
     * The checks of {@see resolveChat()} on a connection the caller already
     * holds, e.g. the connection of a model catalogue entry: of type
     * `ollama` or `anthropic` and active; the messages name the connection.
     *
     * @throws ProviderConnectionReferenceException
     */
    public function assertChat(ApiConfiguration $connection): ApiConfiguration
    {
        return $this->assertUsable($connection, ProviderType::nameOf($connection), ProviderType::CHAT_TYPES, 'provider');
    }

    /**
     * A connection for embeddings (`ollama` only).
     *
     * @throws ProviderConnectionReferenceException
     */
    public function resolveEmbedding(string $reference): ApiConfiguration
    {
        return $this->resolve($reference, ProviderType::EMBEDDING_TYPES, 'embedding');
    }

    /**
     * @param list<string> $types
     *
     * @throws ProviderConnectionReferenceException
     */
    private function resolve(string $reference, array $types, string $kind): ApiConfiguration
    {
        $reference = trim($reference);
        if ('' === $reference) {
            throw new ProviderConnectionReferenceException(\sprintf('Empty %s connection reference.', $kind), ProviderConnectionReferenceException::EMPTY, $reference);
        }

        if (str_starts_with($reference, '/')) {
            return $this->assertUsable($this->fromIri($reference, $kind), $reference, $types, $kind);
        }

        $byId = null;
        if (1 === preg_match(self::UUID_LIKE, $reference)) {
            try {
                $byId = $this->uuidResolver->findByPartialUuid(ApiConfiguration::class, $reference);
            } catch (\RuntimeException) {
                throw new ProviderConnectionReferenceException(\sprintf('%s connection reference "%s" is ambiguous.', ucfirst($kind), $reference), ProviderConnectionReferenceException::AMBIGUOUS, $reference);
            }

            if ($byId instanceof ApiConfiguration && self::isOfType($byId, $types)) {
                return $this->assertUsable($byId, $reference, $types, $kind);
            }
        }

        // Names are unique across all provider types, so the lookup covers
        // all of them: a name of an `anthropic` connection given for
        // embeddings is a wrong type, not an unknown connection.
        $byName = $this->findByName($reference, $kind);
        if (null !== $byName) {
            return $this->assertUsable($byName, $reference, $types, $kind);
        }

        if ($byId instanceof ApiConfiguration) {
            // Matched a connection of another type by id.
            return $this->assertUsable($byId, $reference, $types, $kind);
        }

        throw new ProviderConnectionReferenceException(\sprintf('Unknown %s connection "%s".', $kind, $reference), ProviderConnectionReferenceException::UNKNOWN, $reference);
    }

    private function fromIri(string $iri, string $kind): ApiConfiguration
    {
        try {
            $resource = $this->iriConverter->getResourceFromIri($iri);
        } catch (ApiPlatformException) {
            throw new ProviderConnectionReferenceException(\sprintf('Unknown %s connection "%s".', $kind, $iri), ProviderConnectionReferenceException::UNKNOWN, $iri);
        }

        if (!$resource instanceof ApiConfiguration) {
            throw new ProviderConnectionReferenceException(\sprintf('"%s" does not reference a connection.', $iri), ProviderConnectionReferenceException::UNKNOWN, $iri);
        }

        return $resource;
    }

    private function findByName(string $name, string $kind): ?ApiConfiguration
    {
        $matches = $this->entityManager->getRepository(ApiConfiguration::class)
            ->findBy(['type' => ProviderType::CHAT_TYPES, 'name' => $name], null, 2);

        if (\count($matches) > 1) {
            throw new ProviderConnectionReferenceException(\sprintf('%s connection name "%s" is ambiguous.', ucfirst($kind), $name), ProviderConnectionReferenceException::AMBIGUOUS, $name);
        }

        return $matches[0] ?? null;
    }

    /**
     * @param list<string> $types
     */
    private function assertUsable(ApiConfiguration $connection, string $reference, array $types, string $kind): ApiConfiguration
    {
        if (!self::isOfType($connection, $types)) {
            $type = $connection->getConfigJson()['type'] ?? null;
            throw new ProviderConnectionReferenceException(
                ProviderType::isProvider($connection)
                    ? \sprintf('Connection "%s" is an %s connection, which cannot serve %s (expected: %s).', $reference, \is_string($type) ? $type : '', 'embedding' === $kind ? 'embeddings' : 'chat', implode(', ', $types))
                    : \sprintf('Connection "%s" is not a provider connection (expected: %s).', $reference, implode(', ', $types)),
                ProviderConnectionReferenceException::WRONG_TYPE,
                $reference,
            );
        }
        if (!$connection->isActive()) {
            throw new ProviderConnectionReferenceException(\sprintf('%s connection "%s" is inactive.', ucfirst($kind), $reference), ProviderConnectionReferenceException::INACTIVE, $reference);
        }

        return $connection;
    }

    /**
     * @param list<string> $types
     */
    private static function isOfType(ApiConfiguration $connection, array $types): bool
    {
        return \in_array($connection->getConfigJson()['type'] ?? null, $types, true);
    }
}
