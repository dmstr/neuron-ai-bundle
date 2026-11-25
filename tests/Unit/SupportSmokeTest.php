<?php

// file generated with AI assistance: Claude Code - 2026-11-25 22:00:17 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit;

use Dmstr\NeuronAiBundle\Retrieval\Neighbour;
use Dmstr\NeuronAiBundle\Security\Permissions;
use Dmstr\NeuronAiBundle\Tests\Kernel\TestHeaderAuthenticator;
use Dmstr\NeuronAiBundle\Tests\Kernel\TestPermissionVoter;
use Dmstr\NeuronAiBundle\Tests\Kernel\TestRoles;
use Dmstr\NeuronAiBundle\Tests\Support\Port\InMemoryAgentLimitDefaults;
use Dmstr\NeuronAiBundle\Tests\Support\Port\InMemoryAgentPreviewRetention;
use Dmstr\NeuronAiBundle\Tests\Support\Port\InMemoryAnonymousAccessPolicy;
use Dmstr\NeuronAiBundle\Tests\Support\Port\InMemoryEvaluationSettings;
use Dmstr\NeuronAiBundle\Tests\Support\Port\InMemoryNeighbourSource;
use Dmstr\NeuronAiBundle\Tests\Support\Port\TestPorts;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Component\Security\Core\Role\RoleHierarchy;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * Kernel-free checks of the shared test foundation: the header semantics of
 * the test authenticator, the voter with the role hierarchy, and the port
 * doubles with their defaults and the reset after every test.
 */
final class SupportSmokeTest extends TestCase
{
    public function testWithoutHeadersTheRequestIsTheAdmin(): void
    {
        $user = new TestHeaderAuthenticator()->authenticate(Request::create('/api/agents'))->getUser();

        self::assertSame(TestHeaderAuthenticator::ADMIN_IDENTIFIER, $user->getUserIdentifier());
        self::assertEqualsCanonicalizing(['ROLE_ADMIN', 'ROLE_USER'], $user->getRoles());
    }

    public function testRolesReplaceTheAdminAndAddRoleUser(): void
    {
        $request = Request::create('/api/agents');
        $request->headers->set(TestHeaderAuthenticator::HEADER_ROLES, ' ROLE_EVAL_ADMIN , ,');

        $user = new TestHeaderAuthenticator()->authenticate($request)->getUser();

        self::assertSame(TestHeaderAuthenticator::USER_IDENTIFIER, $user->getUserIdentifier());
        self::assertSame(['ROLE_EVAL_ADMIN', 'ROLE_USER'], $user->getRoles());
    }

    public function testTheUserHeaderNamesTheIdentity(): void
    {
        $request = Request::create('/api/agents');
        $request->headers->set(TestHeaderAuthenticator::HEADER_ROLES, '');
        $request->headers->set(TestHeaderAuthenticator::HEADER_USER, 'alice');

        $user = new TestHeaderAuthenticator()->authenticate($request)->getUser();

        self::assertSame('alice', $user->getUserIdentifier());
        self::assertSame(['ROLE_USER'], $user->getRoles());
    }

    public function testAnonymousSkipsTheAuthenticator(): void
    {
        $request = Request::create('/api/conversations/anonymous', 'POST');
        $request->headers->set(TestHeaderAuthenticator::HEADER_AUTH, 'Anonymous');

        self::assertFalse(new TestHeaderAuthenticator()->supports($request));
        self::assertSame(401, new TestHeaderAuthenticator()->start($request)->getStatusCode());
    }

    public function testTheVoterGrantsThroughTheHierarchyAndAbstainsOnOtherAttributes(): void
    {
        $voter = new TestPermissionVoter(new RoleHierarchy(TestRoles::HIERARCHY), Permissions::REGISTERED, TestRoles::PERMISSIONS);
        $editor = new UsernamePasswordToken(new InMemoryUser('test-user', null, ['ROLE_EDITOR', 'ROLE_USER']), 'api', ['ROLE_EDITOR', 'ROLE_USER']);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($editor, null, [Permissions::COLLECTION_WRITE]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($editor, null, [Permissions::AGENT_WRITE]));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($editor, null, ['ROLE_EDITOR']));
    }

    public function testEveryPermissionOfTheBundleIsMapped(): void
    {
        self::assertSame([], array_values(array_diff(Permissions::REGISTERED, array_keys(TestRoles::PERMISSIONS))));
    }

    public function testThePortsStartWithTheirDefaults(): void
    {
        $policy = new InMemoryAnonymousAccessPolicy()->current();

        self::assertNull($policy->model);
        self::assertSame(InMemoryAnonymousAccessPolicy::DEFAULT_CREATIONS_PER_ADDRESS_AND_HOUR, $policy->creationsPerAddressAndHour);
        self::assertNull(new InMemoryAgentLimitDefaults()->maxToolCalls());
        self::assertSame(InMemoryAgentPreviewRetention::DEFAULT_RETENTION_DAYS, new InMemoryAgentPreviewRetention()->retentionDays());
        self::assertSame(InMemoryEvaluationSettings::DEFAULT_FEEDBACK_PER_SUBJECT_AND_HOUR, new InMemoryEvaluationSettings()->feedbackPerSubjectAndHour());
        self::assertSame(['before' => null, 'after' => null], new InMemoryNeighbourSource()->neighbours('chunk-1'));
    }

    public function testConfiguredValuesApply(): void
    {
        InMemoryAnonymousAccessPolicy::configure(['model' => '/api/refs/provider_models/x', 'creations_per_address_and_hour' => 0]);
        InMemoryAnonymousAccessPolicy::configure(['agent' => ' docs ']);
        InMemoryAgentPreviewRetention::set(0);
        InMemoryNeighbourSource::set('chunk-1', null, new Neighbour('chunk-2', 'after'));

        $policy = new InMemoryAnonymousAccessPolicy()->current();
        self::assertSame('/api/refs/provider_models/x', $policy->model);
        self::assertSame('docs', $policy->agent);
        self::assertFalse($policy->allowsCreation());
        self::assertSame(0, new InMemoryAgentPreviewRetention()->retentionDays());
        self::assertSame('chunk-2', new InMemoryNeighbourSource()->neighbours('chunk-1')['after']?->chunkId);
    }

    #[Depends('testConfiguredValuesApply')]
    public function testTheExtensionResetsThePortsAfterEveryTest(): void
    {
        self::assertSame([], InMemoryAnonymousAccessPolicy::values());
        self::assertSame(InMemoryAgentPreviewRetention::DEFAULT_RETENTION_DAYS, new InMemoryAgentPreviewRetention()->retentionDays());
        self::assertSame([], InMemoryNeighbourSource::asked());
    }

    public function testResetIsIdempotent(): void
    {
        TestPorts::reset();
        TestPorts::reset();

        self::assertSame([], InMemoryAnonymousAccessPolicy::values());
    }
}
