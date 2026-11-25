<?php

// file generated with AI assistance: Claude Code - 2026-10-09 09:30:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Integration\Mcp\Restriction;

use Dmstr\ApiConfiguration\Entity\ApiConfiguration;
use Dmstr\NeuronAiBundle\Mcp\McpConnectionResolver;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * Wiring of {@see McpConnectionResolver} in the test kernel, without
 * database: the service definition injects the authorization checker, so
 * the direct use of a restricted connection follows the caller's roles
 * (ROLE_AGENT_ADMIN and ROLE_ADMIN, listed in the permission map) instead of failing closed
 * for everybody.
 */
final class McpConnectionResolverWiringTest extends KernelTestCase
{
    public function testAgentAdminMayUseARestrictedConnectionDirectly(): void
    {
        self::assertTrue($this->resolverFor(['ROLE_USER', 'ROLE_AGENT_ADMIN'])->mayUseDirectly(self::restricted()));
    }

    public function testAdminInheritsTheDirectUse(): void
    {
        self::assertTrue($this->resolverFor(['ROLE_ADMIN'])->mayUseDirectly(self::restricted()));
    }

    public function testPlainUserMayNotUseARestrictedConnectionDirectly(): void
    {
        $resolver = $this->resolverFor(['ROLE_USER']);

        self::assertFalse($resolver->mayUseDirectly(self::restricted()));
        self::assertTrue($resolver->mayUseDirectly(self::connection([])), 'An unrestricted connection stays usable.');
    }

    /**
     * @param list<string> $roles
     */
    private function resolverFor(array $roles): McpConnectionResolver
    {
        self::bootKernel();
        $container = self::getContainer();

        /** @var TokenStorageInterface $tokenStorage */
        $tokenStorage = $container->get('security.token_storage');
        $tokenStorage->setToken(new UsernamePasswordToken(new InMemoryUser('wiring-test', null, $roles), 'main', $roles));

        $resolver = $container->get(McpConnectionResolver::class);
        self::assertInstanceOf(McpConnectionResolver::class, $resolver);

        return $resolver;
    }

    private static function restricted(): ApiConfiguration
    {
        return self::connection(['restricted' => true]);
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function connection(array $config): ApiConfiguration
    {
        return (new ApiConfiguration())
            ->setName('flowable')
            ->setConfigJson($config + ['mcp' => true, 'type' => 'mcp', 'endpoint_type' => 'rest', 'url' => 'https://example.org/mcp', 'transport' => 'streamable-http'])
            ->setActive(true);
    }
}
