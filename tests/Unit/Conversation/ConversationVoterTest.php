<?php

// file generated with AI assistance: Claude Code - 2026-10-01 15:34:45 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\Conversation;

use Dmstr\NeuronAiBundle\Entity\Conversation;
use Dmstr\NeuronAiBundle\Entity\Message;
use Dmstr\NeuronAiBundle\Security\ConversationSecurity;
use Dmstr\NeuronAiBundle\Security\ConversationVoter;
use Dmstr\NeuronAiBundle\Security\Permissions;
use Dmstr\NeuronAiBundle\Tests\Kernel\TestPermissionVoter;
use Dmstr\NeuronAiBundle\Tests\Kernel\TestRoles;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\Security\Core\Authentication\AuthenticationTrustResolver;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AccessDecision;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManager;
use Symfony\Component\Security\Core\Authorization\AuthorizationChecker;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Authorization\ExpressionLanguage;
use Symfony\Component\Security\Core\Authorization\Voter\ExpressionVoter;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Component\Security\Core\Role\RoleHierarchy;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * Kernel-free test of the conversation rights. The package voter decides
 * only the three owner attributes (`*_own`: the token's user identifier
 * equals `ownerSubject`) and denies everything else without an exception:
 * no user, an empty identifier, a null subject (the Hydra operation filter
 * evaluates item expressions with a null object). The mapped permissions
 * (`read_any`, `delete_any`, `read_eval`) are decided by the core voter;
 * `ConversationSecurity` links both, evaluated here by the real
 * authorization checker with the real expression voter. Holders of
 * `read_eval` read evaluation conversations (`origin` = `eval`) and replay
 * conversations (`origin` = `replay`) of every owner, but run no turn in
 * them and read no foreign ordinary conversation.
 */
final class ConversationVoterTest extends TestCase
{
    private const string OWNER = 'user-a';
    private const string OTHER = 'user-b';

    private const array OWN_ATTRIBUTES = [Permissions::READ_OWN, Permissions::TURN_OWN, Permissions::DELETE_OWN];

    public function testOwnerHoldsTheOwnAttributes(): void
    {
        $token = self::token(self::OWNER, ['ROLE_USER']);
        $conversation = self::conversation(self::OWNER);

        foreach (self::OWN_ATTRIBUTES as $attribute) {
            self::assertSame(VoterInterface::ACCESS_GRANTED, self::voter()->vote($token, $conversation, [$attribute]), $attribute);
        }
    }

    public function testTheVoterDecidesOnlyTheOwnAttributes(): void
    {
        $token = self::token(self::OWNER, ['ROLE_ADMIN']);
        $conversation = self::conversation(self::OWNER);

        foreach ([Permissions::READ_ANY, Permissions::DELETE_ANY, Permissions::READ_PAYLOAD, Permissions::READ_EVAL, Permissions::ARTIFACT_WRITE, 'ROLE_USER'] as $attribute) {
            self::assertFalse(self::voter()->supportsAttribute($attribute), $attribute);
            self::assertSame(VoterInterface::ACCESS_ABSTAIN, self::voter()->vote($token, $conversation, [$attribute]), $attribute);
            self::assertSame(VoterInterface::ACCESS_ABSTAIN, self::voter()->vote($token, null, [$attribute]), $attribute);
        }
        foreach (self::OWN_ATTRIBUTES as $attribute) {
            self::assertTrue(self::voter()->supportsAttribute($attribute), $attribute);
        }
    }

    public function testOwnAttributesAreNotRegistered(): void
    {
        foreach (self::OWN_ATTRIBUTES as $attribute) {
            self::assertNotContains($attribute, Permissions::REGISTERED);
        }
        self::assertCount(28, Permissions::REGISTERED);
        self::assertCount(28, array_unique(Permissions::REGISTERED));
    }

    /**
     * @param list<string> $roles
     */
    #[DataProvider('nonOwners')]
    public function testForeignConversationIsDeniedForTheOwnAttributes(array $roles): void
    {
        $token = self::token(self::OTHER, $roles);
        $conversation = self::conversation(self::OWNER);

        // Not even the roles of the map change the voter's answer.
        foreach (self::OWN_ATTRIBUTES as $attribute) {
            self::assertSame(VoterInterface::ACCESS_DENIED, self::voter()->vote($token, $conversation, [$attribute]), $attribute);
        }
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function nonOwners(): iterable
    {
        yield 'user' => [['ROLE_USER']];
        yield 'editor and developer' => [['ROLE_EDITOR', 'ROLE_DEVELOPER']];
        yield 'admin' => [['ROLE_ADMIN']];
        yield 'eval reviewer' => [['ROLE_USER', 'ROLE_EVAL_REVIEWER']];
        yield 'no role (capability token of another conversation)' => [[]];
    }

    public function testNullSubjectDeniesTheOwnAttributes(): void
    {
        $token = self::token(self::OWNER, ['ROLE_ADMIN']);

        foreach (self::OWN_ATTRIBUTES as $attribute) {
            self::assertSame(VoterInterface::ACCESS_DENIED, self::voter()->vote($token, null, [$attribute]), $attribute);
        }
    }

    public function testAnonymousIsDeniedEverything(): void
    {
        $token = new NullToken();
        $conversation = self::conversation(self::OWNER);

        foreach (self::OWN_ATTRIBUTES as $attribute) {
            self::assertSame(VoterInterface::ACCESS_DENIED, self::voter()->vote($token, $conversation, [$attribute]), $attribute);
            self::assertSame(VoterInterface::ACCESS_DENIED, self::voter()->vote($token, null, [$attribute]), $attribute);
        }
    }

    public function testConversationWithoutOwnerIsNotOwnedByAnybody(): void
    {
        foreach (self::OWN_ATTRIBUTES as $attribute) {
            self::assertSame(VoterInterface::ACCESS_DENIED, self::voter()->vote(self::token(self::OWNER, ['ROLE_USER']), new Conversation(), [$attribute]), $attribute);
        }
    }

    public function testUnrelatedSubjectsAbstain(): void
    {
        $token = self::token(self::OWNER, ['ROLE_ADMIN']);

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, self::voter()->vote($token, new \stdClass(), [Permissions::READ_OWN]));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, self::voter()->vote($token, null, ['mcp:sync']));
    }

    public function testSupportsCacheableTypes(): void
    {
        self::assertTrue(self::voter()->supportsType(Conversation::class));
        self::assertTrue(self::voter()->supportsType('null'));
        self::assertFalse(self::voter()->supportsType(\stdClass::class));
    }

    public function testExpressionsOnlyUseKnownPermissions(): void
    {
        $known = [...Permissions::REGISTERED, ...self::OWN_ATTRIBUTES];
        foreach ([ConversationSecurity::READ, ConversationSecurity::READ_PARENT, ConversationSecurity::DELETE, ConversationSecurity::TURN] as $expression) {
            preg_match_all("/is_granted\\('([^']+)'/", $expression, $matches);
            self::assertNotSame([], $matches[1]);
            self::assertSame([], array_diff($matches[1], $known), $expression);
        }
    }

    // ConversationSecurity, evaluated by the real checker

    public function testOwnerHoldsReadTurnAndDeleteThroughTheExpressions(): void
    {
        $checker = self::checker(self::token(self::OWNER, ['ROLE_USER']));
        $conversation = self::conversation(self::OWNER);

        self::assertTrue($checker->isGranted(new Expression(ConversationSecurity::READ), $conversation));
        self::assertTrue($checker->isGranted(new Expression(ConversationSecurity::DELETE), $conversation));
        self::assertTrue($checker->isGranted(new Expression(ConversationSecurity::TURN), $conversation));
        self::assertTrue($checker->isGranted(new Expression(ConversationSecurity::READ_PARENT), (new Message())->setConversation($conversation)));
    }

    public function testCapabilityTokenWithoutRolesHoldsOnlyItsOwnConversation(): void
    {
        $checker = self::checker(self::token('anon:1', []));
        $own = self::conversation('anon:1');
        $foreign = self::conversation('anon:2');
        $foreignEval = self::evalConversation('anon:2');

        foreach ([ConversationSecurity::READ, ConversationSecurity::DELETE, ConversationSecurity::TURN] as $expression) {
            self::assertTrue($checker->isGranted(new Expression($expression), $own), $expression);
            self::assertFalse($checker->isGranted(new Expression($expression), $foreign), $expression);
            self::assertFalse($checker->isGranted(new Expression($expression), $foreignEval), $expression);
            self::assertFalse($checker->isGranted(new Expression($expression), null), $expression);
        }
        // None of the mapped permissions reaches a roleless principal.
        foreach (Permissions::REGISTERED as $permission) {
            self::assertFalse($checker->isGranted($permission), $permission);
        }
    }

    public function testAdminReadsAndDeletesForeignConversationsButDoesNotTurn(): void
    {
        $checker = self::checker(self::token(self::OTHER, ['ROLE_ADMIN']));
        $conversation = self::conversation(self::OWNER);

        self::assertTrue($checker->isGranted(new Expression(ConversationSecurity::READ), $conversation));
        self::assertTrue($checker->isGranted(new Expression(ConversationSecurity::DELETE), $conversation));
        self::assertFalse($checker->isGranted(new Expression(ConversationSecurity::TURN), $conversation));
        self::assertTrue($checker->isGranted(Permissions::READ_PAYLOAD));
    }

    public function testEditorAndDeveloperReadNeitherForeignOrdinaryConversationsNorPayloads(): void
    {
        $checker = self::checker(self::token(self::OTHER, ['ROLE_EDITOR', 'ROLE_DEVELOPER']));
        $conversation = self::conversation(self::OWNER);

        self::assertFalse($checker->isGranted(new Expression(ConversationSecurity::READ), $conversation));
        self::assertFalse($checker->isGranted(new Expression(ConversationSecurity::DELETE), $conversation));
        self::assertFalse($checker->isGranted(Permissions::READ_PAYLOAD));
        self::assertFalse($checker->isGranted(Permissions::READ_ANY));
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function evalReaders(): iterable
    {
        yield 'eval reviewer' => [['ROLE_USER', 'ROLE_EVAL_REVIEWER']];
        yield 'eval admin' => [['ROLE_USER', 'ROLE_EVAL_ADMIN']];
        yield 'reviewer role alone' => [['ROLE_EVAL_REVIEWER']];
        yield 'editor' => [['ROLE_EDITOR']];
        yield 'developer' => [['ROLE_DEVELOPER']];
    }

    /**
     * @param list<string> $roles
     */
    #[DataProvider('evalReaders')]
    public function testEvalReadersReadForeignEvalAndReplayConversationsButDoNotTurnOrDelete(array $roles): void
    {
        $checker = self::checker(self::token(self::OTHER, $roles));

        foreach ([self::evalConversation(self::OWNER), self::replayConversation(self::OWNER)] as $conversation) {
            self::assertTrue($checker->isGranted(new Expression(ConversationSecurity::READ), $conversation));
            self::assertTrue($checker->isGranted(new Expression(ConversationSecurity::READ_PARENT), (new Message())->setConversation($conversation)));
            self::assertFalse($checker->isGranted(new Expression(ConversationSecurity::TURN), $conversation));
            self::assertFalse($checker->isGranted(new Expression(ConversationSecurity::DELETE), $conversation));
        }
    }

    /**
     * @param list<string> $roles
     */
    #[DataProvider('evalReaders')]
    public function testEvalReadersDoNotReadForeignOrdinaryConversations(array $roles): void
    {
        $checker = self::checker(self::token(self::OTHER, $roles));
        $ordinary = self::conversation(self::OWNER);
        $otherOrigin = self::conversation(self::OWNER)->setOrigin('import');

        foreach ([$ordinary, $otherOrigin] as $conversation) {
            self::assertFalse($checker->isGranted(new Expression(ConversationSecurity::READ), $conversation));
            self::assertFalse($checker->isGranted(new Expression(ConversationSecurity::READ_PARENT), (new Message())->setConversation($conversation)));
        }
    }

    public function testWithoutReadEvalForeignEvalAndReplayConversationsAreDenied(): void
    {
        foreach ([['ROLE_USER'], ['ROLE_USER', 'ROLE_MCP_ADMIN', 'ROLE_AGENT_ADMIN'], []] as $roles) {
            $checker = self::checker(self::token(self::OTHER, $roles));
            foreach ([self::evalConversation(self::OWNER), self::replayConversation(self::OWNER)] as $conversation) {
                foreach ([ConversationSecurity::READ, ConversationSecurity::TURN, ConversationSecurity::DELETE] as $expression) {
                    self::assertFalse($checker->isGranted(new Expression($expression), $conversation), implode(',', $roles));
                }
            }
        }

        // A map that grants read_eval to nobody switches the access off for both origins.
        $checker = self::checker(self::token(self::OTHER, ['ROLE_USER', 'ROLE_EVAL_REVIEWER']), [Permissions::READ_EVAL => []] + self::map());
        self::assertFalse($checker->isGranted(new Expression(ConversationSecurity::READ), self::evalConversation(self::OWNER)));
        self::assertFalse($checker->isGranted(new Expression(ConversationSecurity::READ), self::replayConversation(self::OWNER)));
    }

    public function testOwnerKeepsAllOwnRightsOnEvalAndReplayConversations(): void
    {
        $checker = self::checker(self::token(self::OWNER, ['ROLE_USER']));

        foreach ([self::evalConversation(self::OWNER), self::replayConversation(self::OWNER)] as $conversation) {
            foreach ([ConversationSecurity::READ, ConversationSecurity::TURN, ConversationSecurity::DELETE] as $expression) {
                self::assertTrue($checker->isGranted(new Expression($expression), $conversation), $expression);
            }
        }
    }

    public function testNullObjectAnswersWithTheMapAndNeverThrows(): void
    {
        // The Hydra operation filter evaluates the expressions with object = null.
        $admin = self::checker(self::token(self::OTHER, ['ROLE_ADMIN']));
        $reviewer = self::checker(self::token(self::OTHER, ['ROLE_USER', 'ROLE_EVAL_REVIEWER']));
        $user = self::checker(self::token(self::OTHER, ['ROLE_USER']));

        foreach ([ConversationSecurity::READ, ConversationSecurity::READ_PARENT, ConversationSecurity::DELETE] as $expression) {
            self::assertTrue($admin->isGranted(new Expression($expression), null), $expression);
            self::assertFalse($user->isGranted(new Expression($expression), null), $expression);
        }
        self::assertFalse($admin->isGranted(new Expression(ConversationSecurity::TURN), null));
        // read_eval without a subject: no evaluation origin to look at.
        self::assertFalse($reviewer->isGranted(new Expression(ConversationSecurity::READ), null));
        self::assertFalse($reviewer->isGranted(new Expression(ConversationSecurity::READ_PARENT), null));
    }

    public function testAnonymousTokenIsDeniedTheExpressions(): void
    {
        $checker = self::checker(new NullToken());
        $conversation = self::conversation(self::OWNER);

        foreach ([ConversationSecurity::READ, ConversationSecurity::TURN, ConversationSecurity::DELETE] as $expression) {
            self::assertFalse($checker->isGranted(new Expression($expression), $conversation), $expression);
            self::assertFalse($checker->isGranted(new Expression($expression), null), $expression);
        }
    }

    private static function voter(): ConversationVoter
    {
        return new ConversationVoter();
    }

    /**
     * The real authorization chain of the application: the expression
     * voter, the package voter and the core permission voter on the map.
     *
     * @param array<string, list<string>> $map
     */
    private static function checker(TokenInterface $token, ?array $map = null): AuthorizationCheckerInterface
    {
        $tokenStorage = new TokenStorage();
        $tokenStorage->setToken($token);

        $checker = null;
        $proxy = new class(static function () use (&$checker): AuthorizationCheckerInterface {
            return $checker;
        }) implements AuthorizationCheckerInterface {
            public function __construct(private readonly \Closure $target)
            {
            }

            public function isGranted(mixed $attribute, mixed $subject = null, ?AccessDecision $accessDecision = null): bool
            {
                return ($this->target)()->isGranted($attribute, $subject, $accessDecision);
            }
        };

        $map ??= self::map();
        $hierarchy = new RoleHierarchy(self::hierarchy());
        $checker = new AuthorizationChecker($tokenStorage, new AccessDecisionManager([
            new ExpressionVoter(new ExpressionLanguage(), new AuthenticationTrustResolver(), $proxy, $hierarchy),
            self::voter(),
            new TestPermissionVoter($hierarchy, Permissions::REGISTERED, $map),
        ]));

        return $checker;
    }

    /**
     * @param list<string> $roles
     */
    private static function token(string $identifier, array $roles): TokenInterface
    {
        return new UsernamePasswordToken(new InMemoryUser($identifier, null, $roles), 'api', $roles);
    }

    private static function conversation(string $owner): Conversation
    {
        return (new Conversation())->setOwnerSubject($owner);
    }

    private static function replayConversation(string $owner): Conversation
    {
        return self::conversation($owner)->setOrigin(Conversation::ORIGIN_REPLAY);
    }

    private static function evalConversation(string $owner): Conversation
    {
        return self::conversation($owner)->setOrigin(Conversation::ORIGIN_EVAL);
    }

    /**
     * The role hierarchy of a host application ({@see TestRoles::HIERARCHY}).
     *
     * @return array<string, list<string>>
     */
    private static function hierarchy(): array
    {
        return TestRoles::HIERARCHY;
    }

    /**
     * The conversation part of a host's `za7_core.permissions` map
     * ({@see TestRoles::PERMISSIONS}).
     *
     * @return array<string, list<string>>
     */
    private static function map(): array
    {
        return array_intersect_key(TestRoles::PERMISSIONS, array_flip([Permissions::READ_ANY, Permissions::DELETE_ANY, Permissions::READ_PAYLOAD, Permissions::READ_EVAL]));
    }
}
