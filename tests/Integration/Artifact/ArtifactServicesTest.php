<?php

// file generated with AI assistance: Claude Code - 2026-10-16 08:40:52 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Integration\Artifact;

use Dmstr\NeuronAiBundle\Security\Permissions;
use Dmstr\NeuronAiBundle\Tests\Kernel\TestKernel;
use Dmstr\NeuronAiBundle\Tests\Kernel\TestRoles;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The artifact configuration of the bundle as the test kernel compiles
 * it. The services themselves are private and only kept once the turn
 * pipeline uses them; `lint:container` checks their arguments.
 */
final class ArtifactServicesTest extends KernelTestCase
{
    public function testConfiguration(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        self::assertSame(TestKernel::artifactRoot(), $container->getParameter('dmstr_neuron_ai.artifacts.root'));

        // The permission is registered by the bundle and mapped by the host (the test kernel).
        $permissions = $container->getParameter(TestKernel::PERMISSION_MAP_PARAMETER);
        self::assertIsArray($permissions);
        self::assertSame([TestRoles::ARTIFACT_WRITER], $permissions[Permissions::ARTIFACT_WRITE] ?? null);
        self::assertContains(Permissions::ARTIFACT_WRITE, $container->getParameter(TestKernel::PERMISSIONS_REGISTERED_PARAMETER));
        self::assertFalse($container->hasParameter('dmstr_neuron_ai.permissions'));
    }
}
