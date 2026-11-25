<?php

// file generated with AI assistance: Claude Code - 2026-10-09 07:01:30 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\Mcp\Restriction;

use ApiPlatform\Metadata\Exception\ItemNotFoundException;
use ApiPlatform\Metadata\IriConverterInterface;
use Dmstr\ApiConfiguration\Entity\ApiConfiguration;
use Dmstr\ApiPlatformUtils\Service\UuidResolver;
use Dmstr\NeuronAiBundle\Conversation\ConversationReferences;
use Dmstr\NeuronAiBundle\Mcp\McpConnectionResolver;
use Dmstr\NeuronAiBundle\Security\Permissions;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Kernel-free checks of the direct use of MCP connections
 * ({@see McpConnectionResolver::resolveForDirectUse()},
 * {@see McpConnectionResolver::resolveManyForDirectUse()} and
 * {@see ConversationReferences::connectionIdsForDirectUse()}): a restricted
 * connection (`restricted: true` or `auth_type: client_credentials`) needs
 * `neuronai:mcp_connection:use_restricted`, 403 otherwise and always without an authorization
 * checker; the unchecked resolution of agent versions and stored
 * conversations is unaffected.
 */
final class McpConnectionDirectUseTest extends TestCase
{
    /** @var list<ApiConfiguration> */
    private array $stored = [];

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function restrictions(): iterable
    {
        yield 'restricted flag' => [['restricted' => true]];
        yield 'client credentials' => [['auth_type' => 'client_credentials', 'token_url' => 'https://sso.example.org/token', 'client_id' => 'mcp-agent', 'client_secret' => 'enc:geheim']];
        yield 'client credentials with restricted false' => [['auth_type' => 'client_credentials', 'restricted' => false]];
    }

    /**
     * @param array<string, mixed> $restriction
     */
    #[DataProvider('restrictions')]
    public function testPlainUserIsRefusedWithA403NamingTheConnection(array $restriction): void
    {
        $this->stored = [self::connection('flowable', $restriction)];

        try {
            $this->resolver(['ROLE_USER'])->resolveForDirectUse('flowable');
            self::fail('Expected a 403.');
        } catch (AccessDeniedHttpException $e) {
            self::assertSame(403, $e->getStatusCode());
            self::assertSame('MCP connection "flowable" is restricted: it can only be used through an agent or by an agent administrator.', $e->getMessage());
            self::assertStringNotContainsString('geheim', $e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $restriction
     */
    #[DataProvider('restrictions')]
    public function testAgentAdminMayNameARestrictedConnection(array $restriction): void
    {
        $flowable = self::connection('flowable', $restriction);
        $this->stored = [$flowable];

        self::assertSame($flowable, $this->resolver([Permissions::MCP_CONNECTION_USE_RESTRICTED])->resolveForDirectUse('flowable'));
        self::assertSame([$flowable], $this->resolver([Permissions::MCP_CONNECTION_USE_RESTRICTED])->resolveManyForDirectUse(['flowable']));
    }

    public function testWithoutAuthorizationCheckerARestrictedConnectionIsAlwaysRefused(): void
    {
        $this->stored = [self::connection('flowable', ['restricted' => true])];

        $this->expectException(AccessDeniedHttpException::class);

        $this->resolver(null)->resolveForDirectUse('flowable');
    }

    public function testUnrestrictedConnectionIsUnchanged(): void
    {
        $docs = self::connection('docs', ['restricted' => false, 'auth_type' => 'token']);
        $this->stored = [$docs];

        self::assertSame($docs, $this->resolver(['ROLE_USER'])->resolveForDirectUse('docs'));
        self::assertSame($docs, $this->resolver(null)->resolveForDirectUse('docs'), 'no checker needed for an unrestricted connection');
        self::assertSame([$docs], $this->resolver(null)->resolveManyForDirectUse(['docs', 'docs']));
    }

    public function testUncheckedResolutionStillReturnsARestrictedConnection(): void
    {
        $flowable = self::connection('flowable', ['auth_type' => 'client_credentials']);
        $this->stored = [$flowable];

        // Agent versions (VersionTurnMapper, AgentConfigurationValidator)
        // and the turns of stored conversations resolve unchecked.
        self::assertSame($flowable, $this->resolver(['ROLE_USER'])->resolve('flowable'));
        self::assertSame([$flowable], $this->resolver(null)->resolveMany(['flowable']));
    }

    public function testOneRestrictedConnectionAmongSeveralRefusesTheWholeList(): void
    {
        $this->stored = [self::connection('docs', []), self::connection('flowable', ['restricted' => true])];

        $this->expectException(AccessDeniedHttpException::class);
        $this->expectExceptionMessage('"flowable"');

        $this->resolver(['ROLE_USER'])->resolveManyForDirectUse(['docs', 'flowable']);
    }

    public function testInvalidReferencesAnswer422BeforeTheRestrictionIsChecked(): void
    {
        $this->stored = [self::connection('flowable', ['restricted' => true])];

        $this->expectException(UnprocessableEntityHttpException::class);
        $this->expectExceptionMessage('Unknown MCP connection "missing"');

        $this->resolver(['ROLE_USER'])->resolveManyForDirectUse(['flowable', 'missing']);
    }

    public function testConversationReferencesCheckTheDirectUse(): void
    {
        $docs = self::connection('docs', []);
        $flowable = self::connection('flowable', ['auth_type' => 'client_credentials']);
        $this->stored = [$docs, $flowable];

        $user = new ConversationReferences($this->entityManager(), $this->resolver(['ROLE_USER']));
        self::assertSame([$docs->getId()->toRfc4122()], $user->connectionIdsForDirectUse(['docs']));
        self::assertSame([$flowable->getId()->toRfc4122()], $user->connectionIds(['flowable']), 'unchecked for pinned agent versions');
        try {
            $user->connectionIdsForDirectUse(['docs', 'flowable']);
            self::fail('Expected a 403.');
        } catch (AccessDeniedHttpException) {
        }

        $admin = new ConversationReferences($this->entityManager(), $this->resolver([Permissions::MCP_CONNECTION_USE_RESTRICTED]));
        self::assertSame([$docs->getId()->toRfc4122(), $flowable->getId()->toRfc4122()], $admin->connectionIdsForDirectUse(['docs', 'flowable']));
        self::assertSame([], $user->connectionIdsForDirectUse([]));
    }

    /**
     * @param list<string>|null $roles the roles the checker grants (no hierarchy); null = no checker
     */
    private function resolver(?array $roles): McpConnectionResolver
    {
        $iriConverter = $this->createStub(IriConverterInterface::class);
        $iriConverter->method('getResourceFromIri')->willThrowException(new ItemNotFoundException('Not found'));
        $uuidResolver = $this->createStub(UuidResolver::class);
        $uuidResolver->method('findByPartialUuid')->willReturn(null);

        $checker = null;
        if (null !== $roles) {
            $checker = $this->createStub(AuthorizationCheckerInterface::class);
            $checker->method('isGranted')->willReturnCallback(static fn (mixed $attribute): bool => \in_array($attribute, $roles, true));
        }

        return new McpConnectionResolver($this->entityManager(), $iriConverter, $uuidResolver, $checker);
    }

    private function entityManager(): EntityManagerInterface
    {
        $repository = $this->createStub(EntityRepository::class);
        $repository->method('findBy')->willReturnCallback(fn (array $criteria, ?array $orderBy = null, ?int $limit = null): array => \array_slice(array_values(array_filter(
            $this->stored,
            static fn (ApiConfiguration $c): bool => $c->getType() === $criteria['type'] && $c->getName() === $criteria['name'],
        )), 0, $limit));

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);

        return $entityManager;
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function connection(string $name, array $config): ApiConfiguration
    {
        return (new ApiConfiguration())
            ->setName($name)
            ->setConfigJson($config + ['mcp' => true, 'type' => 'mcp', 'endpoint_type' => 'rest', 'url' => 'https://example.org/mcp', 'transport' => 'streamable-http'])
            ->setActive(true);
    }
}
