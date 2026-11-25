<?php

// file generated with AI assistance: Claude Code - 2026-09-30 21:22:22 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Mcp;

use ApiPlatform\Metadata\Exception\ExceptionInterface as ApiPlatformException;
use ApiPlatform\Metadata\IriConverterInterface;
use Dmstr\ApiConfiguration\Entity\ApiConfiguration;
use Dmstr\ApiPlatformUtils\Service\UuidResolver;
use Dmstr\NeuronAiBundle\Security\Permissions;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Resolves references to MCP connections (for the chat, the catalogue scan
 * and the seed) to active ApiConfiguration entities of type `mcp`.
 *
 * A reference is one of:
 *  - an IRI (`/api/admin/api_configurations/<uuid>`), via IriConverterInterface;
 *  - a full or partial UUID (at least 8 hex digits), via UuidResolver;
 *  - a connection name, looked up within `type = 'mcp'` only, because names
 *    are not unique across connection types.
 *
 * A UUID-like reference that matches no MCP connection by id is retried as a
 * name. Unknown, inactive, ambiguous and non-`mcp` references raise an
 * UnprocessableEntityHttpException (HTTP 422).
 *
 * Direct use: a caller who names connections ad hoc (the `mcpConnections`
 * of `POST /chat` and of `POST /conversations` without an agent) resolves
 * through {@see self::resolveForDirectUse()} / {@see self::resolveManyForDirectUse()},
 * which additionally refuse a restricted connection
 * ({@see McpConnectionRestriction}) with an AccessDeniedHttpException
 * (HTTP 403) unless the caller holds {@see Permissions::MCP_CONNECTION_USE_RESTRICTED}. Agent
 * versions (whose `mcpTools` an agent administrator approved), the catalogue
 * scan, the seed and the turns of stored conversations keep using the
 * unchecked {@see self::resolve()} / {@see self::resolveMany()}.
 */
final class McpConnectionResolver
{
    private const string UUID_LIKE = '/^[0-9a-f]{8}[0-9a-f-]{0,28}$/i';

    /**
     * @param AuthorizationCheckerInterface|null $authorizationChecker the permission check of the direct use; null refuses
     *                                                                 every restricted connection on the direct-use
     *                                                                 path (fail closed), the unchecked path is unaffected
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly IriConverterInterface $iriConverter,
        private readonly UuidResolver $uuidResolver,
        private readonly ?AuthorizationCheckerInterface $authorizationChecker = null,
    ) {
    }

    /**
     * {@see self::resolve()} for a reference the caller names directly:
     * a restricted connection additionally requires {@see Permissions::MCP_CONNECTION_USE_RESTRICTED}.
     *
     * @throws UnprocessableEntityHttpException for unknown, inactive, ambiguous and non-`mcp` references
     * @throws AccessDeniedHttpException        for a restricted connection without the role
     */
    public function resolveForDirectUse(string $reference): ApiConfiguration
    {
        $connection = $this->resolve($reference);
        $this->assertDirectUse($connection);

        return $connection;
    }

    /**
     * {@see self::resolveMany()} for references the caller names directly;
     * every reference is resolved (422) before any is checked (403).
     *
     * @param iterable<string> $references
     *
     * @return list<ApiConfiguration>
     *
     * @throws UnprocessableEntityHttpException for unknown, inactive, ambiguous and non-`mcp` references
     * @throws AccessDeniedHttpException        for a restricted connection without the role
     */
    public function resolveManyForDirectUse(iterable $references): array
    {
        $connections = $this->resolveMany($references);
        foreach ($connections as $connection) {
            $this->assertDirectUse($connection);
        }

        return $connections;
    }

    /**
     * Whether the current caller may name $connection directly: always for
     * a connection that is not restricted, else only with
     * {@see Permissions::MCP_CONNECTION_USE_RESTRICTED} (never without an authorization checker).
     */
    public function mayUseDirectly(ApiConfiguration $connection): bool
    {
        if (!McpConnectionRestriction::isRestricted($connection->getConfigJson())) {
            return true;
        }

        return null !== $this->authorizationChecker && $this->authorizationChecker->isGranted(Permissions::MCP_CONNECTION_USE_RESTRICTED);
    }

    /**
     * @throws AccessDeniedHttpException for a restricted connection the caller may not name directly
     */
    private function assertDirectUse(ApiConfiguration $connection): void
    {
        if ($this->mayUseDirectly($connection)) {
            return;
        }

        throw new AccessDeniedHttpException(\sprintf('MCP connection "%s" is restricted: it can only be used through an agent or by an agent administrator.', $connection->getName()));
    }

    /**
     * @throws UnprocessableEntityHttpException
     */
    public function resolve(string $reference): ApiConfiguration
    {
        $reference = trim($reference);
        if ('' === $reference) {
            throw new UnprocessableEntityHttpException('Empty MCP connection reference.');
        }

        if (str_starts_with($reference, '/')) {
            return $this->assertUsable($this->fromIri($reference), $reference);
        }

        $byId = null;
        if (1 === preg_match(self::UUID_LIKE, $reference)) {
            try {
                $byId = $this->uuidResolver->findByPartialUuid(ApiConfiguration::class, $reference);
            } catch (\RuntimeException) {
                throw new UnprocessableEntityHttpException(\sprintf('MCP connection reference "%s" is ambiguous.', $reference));
            }

            if ($byId instanceof ApiConfiguration && self::isMcp($byId)) {
                return $this->assertUsable($byId, $reference);
            }
        }

        $byName = $this->findByName($reference);
        if (null !== $byName) {
            return $this->assertUsable($byName, $reference);
        }

        if ($byId instanceof ApiConfiguration) {
            // Matched a connection of another type by id.
            return $this->assertUsable($byId, $reference);
        }

        throw new UnprocessableEntityHttpException(\sprintf('Unknown MCP connection "%s".', $reference));
    }

    /**
     * Resolves several references; duplicates (the same connection referenced
     * twice, e.g. by name and IRI) are returned once, in first-seen order.
     *
     * @param iterable<string> $references
     *
     * @return list<ApiConfiguration>
     *
     * @throws UnprocessableEntityHttpException
     */
    public function resolveMany(iterable $references): array
    {
        $resolved = [];
        foreach ($references as $reference) {
            $connection = $this->resolve($reference);
            $resolved[(string) $connection->getId()] ??= $connection;
        }

        return array_values($resolved);
    }

    private function fromIri(string $iri): ApiConfiguration
    {
        try {
            $resource = $this->iriConverter->getResourceFromIri($iri);
        } catch (ApiPlatformException) {
            throw new UnprocessableEntityHttpException(\sprintf('Unknown MCP connection "%s".', $iri));
        }

        if (!$resource instanceof ApiConfiguration) {
            throw new UnprocessableEntityHttpException(\sprintf('"%s" does not reference a connection.', $iri));
        }

        return $resource;
    }

    private function findByName(string $name): ?ApiConfiguration
    {
        $matches = $this->entityManager->getRepository(ApiConfiguration::class)
            ->findBy(['type' => McpExtension::NAME, 'name' => $name], null, 2);

        if (\count($matches) > 1) {
            throw new UnprocessableEntityHttpException(\sprintf('MCP connection name "%s" is ambiguous.', $name));
        }

        return $matches[0] ?? null;
    }

    private function assertUsable(ApiConfiguration $connection, string $reference): ApiConfiguration
    {
        if (!self::isMcp($connection)) {
            throw new UnprocessableEntityHttpException(\sprintf('Connection "%s" is not an MCP connection.', $reference));
        }
        if (!$connection->isActive()) {
            throw new UnprocessableEntityHttpException(\sprintf('MCP connection "%s" is inactive.', $reference));
        }

        return $connection;
    }

    private static function isMcp(ApiConfiguration $connection): bool
    {
        return McpExtension::NAME === ($connection->getConfigJson()['type'] ?? null);
    }
}

// - revised 2026-10-09
