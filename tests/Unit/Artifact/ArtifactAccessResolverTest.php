<?php

// file generated with AI assistance: Claude Code - 2026-10-16 09:04:09 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\Artifact;

use Dmstr\NeuronAiBundle\Agent\AgentConfiguration;
use Dmstr\NeuronAiBundle\Anonymous\SystemActor;
use Dmstr\NeuronAiBundle\Artifact\ArtifactAccess;
use Dmstr\NeuronAiBundle\Artifact\ArtifactAccessResolver;
use Dmstr\NeuronAiBundle\Artifact\ArtifactScope;
use Dmstr\NeuronAiBundle\Entity\Conversation;
use Dmstr\NeuronAiBundle\Security\Permissions;
use Dmstr\NeuronAiBundle\Tests\Kernel\TestPermissionVoter;
use Dmstr\NeuronAiBundle\Tests\Support\ArtifactTestFiles;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManager;
use Symfony\Component\Security\Core\Authorization\AuthorizationChecker;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Role\RoleHierarchy;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * Kernel-free: the artifact gate of a turn with a real token storage, a
 * real {@see ArtifactScope} on a temporary directory and an authorization
 * checker stub (the role hierarchy test uses the real voter). Every
 * exclusion returns null with one warning naming the reason and creates no
 * folder; the happy path creates the working folder.
 */
final class ArtifactAccessResolverTest extends TestCase
{
    private const string OWNER = 'user-1';

    private string $base;

    private TokenStorage $tokens;

    private bool $granted = true;

    /** @var list<array{string|TokenInterface|null, mixed}> attribute and subject of each isGranted() call */
    private array $checks = [];

    /** @var list<array{string, string, array<string, mixed>}> level, message, context */
    private array $logged = [];

    protected function setUp(): void
    {
        $this->base = ArtifactTestFiles::makeTempDir();
        mkdir($this->base.'/root');
        $this->tokens = new TokenStorage();
    }

    protected function tearDown(): void
    {
        ArtifactTestFiles::remove($this->base);
    }

    public function testOwnerWithThePermissionGetsAWritableAccessAndTheFolder(): void
    {
        $this->login(self::OWNER);
        $conversation = $this->conversation();

        $access = $this->resolver()->resolve($conversation, self::configuration());

        self::assertInstanceOf(ArtifactAccess::class, $access);
        self::assertSame(self::OWNER, $access->ownerSubject);
        self::assertSame($conversation->getId()->toRfc4122(), $access->conversationId);
        self::assertSame($this->base.'/root/'.self::OWNER, $access->scopeRoot);
        self::assertTrue($access->writable);
        self::assertDirectoryExists($access->scopeRoot.'/'.$access->conversationId);
        self::assertSame([[Permissions::ARTIFACT_WRITE, $conversation]], $this->checks);
        self::assertSame([], $this->logged);
    }

    public function testOwnerWithoutThePermissionGetsTheReadToolsAndAWarning(): void
    {
        $this->login(self::OWNER);
        $this->granted = false;
        $conversation = $this->conversation();

        $access = $this->resolver()->resolve($conversation, self::configuration());

        self::assertInstanceOf(ArtifactAccess::class, $access);
        self::assertFalse($access->writable);
        self::assertDirectoryExists($access->scopeRoot.'/'.$access->conversationId);
        self::assertSame([['warning', ArtifactAccessResolver::MESSAGE_READ_ONLY, ['conversation' => $conversation->getId()->toRfc4122()]]], $this->logged);
    }

    public function testFailingPermissionCheckDeniesTheWriteTools(): void
    {
        $this->login(self::OWNER);
        $checker = $this->createStub(AuthorizationCheckerInterface::class);
        $checker->method('isGranted')->willThrowException(new \RuntimeException('voter failed'));

        $access = new ArtifactAccessResolver($this->tokens, $checker, new ArtifactScope($this->base.'/root'), $this->logger())->resolve($this->conversation(), self::configuration());

        self::assertInstanceOf(ArtifactAccess::class, $access);
        self::assertFalse($access->writable);
        self::assertSame(['warning', 'warning'], array_column($this->logged, 0));
    }

    /**
     * `neuronai:artifact:write` goes through the core permission voter with the role
     * hierarchy and the permission map of the application: the map lists ROLE_ARTIFACT_WRITER
     * and ROLE_ADMIN, ROLE_USER is not listed.
     *
     * @param list<string> $roles
     */
    #[DataProvider('roles')]
    public function testWritePermissionFollowsThePermissionMap(array $roles, bool $writable): void
    {
        $this->login(self::OWNER, $roles);
        $voter = new TestPermissionVoter(
            new RoleHierarchy(['ROLE_ADMIN' => ['ROLE_USER']]),
            [Permissions::ARTIFACT_WRITE],
            [Permissions::ARTIFACT_WRITE => ['ROLE_ARTIFACT_WRITER', 'ROLE_ADMIN']],
        );
        $checker = new AuthorizationChecker($this->tokens, new AccessDecisionManager([$voter]));

        $access = new ArtifactAccessResolver($this->tokens, $checker, new ArtifactScope($this->base.'/root'))->resolve($this->conversation(), self::configuration());

        self::assertSame($writable, $access?->writable);
    }

    /**
     * @return iterable<string, array{list<string>, bool}>
     */
    public static function roles(): iterable
    {
        yield 'writer' => [['ROLE_USER', 'ROLE_ARTIFACT_WRITER'], true];
        yield 'admin through the map' => [['ROLE_ADMIN'], true];
        yield 'plain user' => [['ROLE_USER'], false];
    }

    public function testVersionWithoutArtifactToolsIsSilent(): void
    {
        $this->login(self::OWNER);

        self::assertNull($this->resolver()->resolve($this->conversation(), AgentConfiguration::fromArray([])));
        self::assertSame([], $this->logged);
        self::assertSame([], $this->checks);
        self::assertSame([], array_values(array_diff(scandir($this->base.'/root') ?: [], ['.', '..'])));
    }

    /**
     * @return iterable<string, array{callable(self): Conversation, string}>
     */
    public static function exclusions(): iterable
    {
        yield 'anonymous' => [static function (self $test): Conversation {
            $test->login(self::OWNER);

            return $test->conversation()->setAnonymous(true);
        }, ArtifactAccessResolver::REASON_ANONYMOUS];
        yield 'origin eval' => [static function (self $test): Conversation {
            $test->login(self::OWNER);

            return $test->conversation()->setOrigin(Conversation::ORIGIN_EVAL);
        }, ArtifactAccessResolver::REASON_ORIGIN];
        yield 'origin replay' => [static function (self $test): Conversation {
            $test->login(self::OWNER);

            return $test->conversation()->setOrigin(Conversation::ORIGIN_REPLAY);
        }, ArtifactAccessResolver::REASON_ORIGIN];
        yield 'no owner' => [static function (self $test): Conversation {
            $test->login(self::OWNER);

            return new Conversation();
        }, ArtifactAccessResolver::REASON_NO_OWNER];
        yield 'no token' => [static fn (self $test): Conversation => $test->conversation(), ArtifactAccessResolver::REASON_NO_TOKEN];
        yield 'token without user identifier' => [static function (self $test): Conversation {
            $test->tokens->setToken(new NullToken());

            return $test->conversation();
        }, ArtifactAccessResolver::REASON_NO_IDENTIFIER];
        yield 'foreign user' => [static function (self $test): Conversation {
            $test->login('user-2');

            return $test->conversation();
        }, ArtifactAccessResolver::REASON_FOREIGN_USER];
        yield 'invalid subject' => [static function (self $test): Conversation {
            $test->login('../user-1');

            return $test->conversation('../user-1');
        }, ArtifactAccessResolver::REASON_INVALID_SUBJECT];
        yield 'missing root' => [static function (self $test): Conversation {
            $test->login(self::OWNER);
            rmdir($test->base.'/root');

            return $test->conversation();
        }, ArtifactAccessResolver::REASON_SCOPE];
        yield 'symlinked user root' => [static function (self $test): Conversation {
            $test->login(self::OWNER);
            mkdir($test->base.'/elsewhere');
            symlink($test->base.'/elsewhere', $test->base.'/root/'.self::OWNER);

            return $test->conversation();
        }, ArtifactAccessResolver::REASON_SCOPE];
    }

    /**
     * @param callable(self): Conversation $arrange
     */
    #[DataProvider('exclusions')]
    public function testExclusionReturnsNullWithAWarning(callable $arrange, string $reason): void
    {
        $conversation = $arrange($this);

        self::assertNull($this->resolver()->resolve($conversation, self::configuration()));

        self::assertCount(1, $this->logged);
        [$level, $message, $context] = $this->logged[0];
        self::assertSame('warning', $level);
        self::assertSame(ArtifactAccessResolver::MESSAGE_SKIPPED, $message);
        self::assertSame($reason, $context['reason']);
        self::assertSame($conversation->getId()->toRfc4122(), $context['conversation']);
        self::assertSame([], $this->checks, 'The write permission is not checked for an excluded turn.');
        self::assertDirectoryDoesNotExist($this->base.'/root/'.self::OWNER.'/'.$conversation->getId()->toRfc4122());
    }

    /**
     * Evaluation and replay turns run under the system actor; its token
     * carries an identifier, which must not count as a user. The
     * conversation here has no origin, so the actor is what excludes it.
     */
    public function testSystemActorGetsNoArtifactTools(): void
    {
        $this->login(self::OWNER);
        $conversation = $this->conversation('system:neuron:eval');

        $access = new SystemActor($this->tokens)->run('system:neuron:eval', fn (): ?ArtifactAccess => $this->resolver()->resolve($conversation, self::configuration()));

        self::assertNull($access);
        self::assertCount(1, $this->logged);
        self::assertSame(ArtifactAccessResolver::REASON_SYSTEM_ACTOR, $this->logged[0][2]['reason']);
        self::assertSame('system:neuron:eval', $this->logged[0][2]['actor']);
    }

    /**
     * Messages for the log never reach the model, but the scope error that
     * names a server path goes to the log context, not into the message.
     */
    public function testScopeErrorIsLoggedInTheContext(): void
    {
        $this->login(self::OWNER);
        rmdir($this->base.'/root');

        $this->resolver()->resolve($this->conversation(), self::configuration());

        self::assertStringNotContainsString($this->base, $this->logged[0][1]);
        self::assertStringContainsString($this->base.'/root', (string) $this->logged[0][2]['error']);
    }

    /**
     * @param list<string> $roles
     */
    public function login(string $identifier, array $roles = ['ROLE_USER']): void
    {
        $this->tokens->setToken(new UsernamePasswordToken(new InMemoryUser($identifier, null, $roles), 'main', $roles));
    }

    public function conversation(string $owner = self::OWNER): Conversation
    {
        return new Conversation()->setOwnerSubject($owner);
    }

    private static function configuration(): AgentConfiguration
    {
        return AgentConfiguration::fromArray(['artifactTools' => true]);
    }

    private function resolver(): ArtifactAccessResolver
    {
        $checker = $this->createStub(AuthorizationCheckerInterface::class);
        $checker->method('isGranted')->willReturnCallback(function (mixed $attribute, mixed $subject = null): bool {
            $this->checks[] = [$attribute, $subject];

            return $this->granted;
        });

        return new ArtifactAccessResolver($this->tokens, $checker, new ArtifactScope($this->base.'/root'), $this->logger());
    }

    private function logger(): AbstractLogger
    {
        $logged = &$this->logged;

        return new class($logged) extends AbstractLogger {
            /**
             * @param list<array{string, string, array<string, mixed>}> $logged
             */
            public function __construct(private array &$logged)
            {
            }

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->logged[] = [(string) $level, (string) $message, $context];
            }
        };
    }
}
