<?php

// file generated with AI assistance: Claude Code - 2026-11-25 21:50:20 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Kernel;

use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;

/**
 * Example permission voter, as a host application provides it for the
 * permission contract (`za7_core.permission` tag, map `za7_core.permissions`).
 *
 * - Only registered permissions are supported; every other attribute
 *   (roles, `IS_AUTHENTICATED_*`, unknown strings) abstains.
 * - The subject is ignored and every subject type is supported, null
 *   included.
 * - A registered permission is granted when one of the token's reachable
 *   roles (role hierarchy) is mapped to it.
 * - Fail-closed: no user, an unmapped permission or an empty role list deny.
 *
 * The constructor takes the role hierarchy, the registered permissions and
 * the map, so kernel-free tests build it with a hierarchy and a map of their
 * own (`new TestPermissionVoter(new RoleHierarchy([...]), $registered, $map)`).
 *
 * @extends Voter<string, mixed>
 */
final class TestPermissionVoter extends Voter
{
    /**
     * @param list<string>                $registered every registered permission
     * @param array<string, list<string>> $map        permission => roles that grant it
     */
    public function __construct(
        private readonly RoleHierarchyInterface $roleHierarchy,
        private readonly array $registered,
        private readonly array $map,
    ) {
    }

    public function supportsAttribute(string $attribute): bool
    {
        return \in_array($attribute, $this->registered, true);
    }

    public function supportsType(string $subjectType): bool
    {
        return true;
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $this->supportsAttribute($attribute);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        if (null === $token->getUser()) {
            return false;
        }

        $granting = $this->map[$attribute] ?? [];
        if ([] === $granting) {
            return false;
        }

        return [] !== array_intersect($granting, $this->roleHierarchy->getReachableRoleNames($token->getRoleNames()));
    }
}
