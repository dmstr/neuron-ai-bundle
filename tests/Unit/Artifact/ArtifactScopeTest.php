<?php

// file generated with AI assistance: Claude Code - 2026-10-16 08:40:52 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\Artifact;

use Dmstr\NeuronAiBundle\Artifact\ArtifactScope;
use Dmstr\NeuronAiBundle\Artifact\ArtifactScopeException;
use Dmstr\NeuronAiBundle\Tests\Support\ArtifactTestFiles;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Kernel-free: folder creation and refusals of ArtifactScope::ensure() on a
 * temporary directory.
 */
final class ArtifactScopeTest extends TestCase
{
    private const string CONVERSATION = '0199e3a4-6b7c-7d8e-9f01-23456789abcd';

    private string $base;

    protected function setUp(): void
    {
        $this->base = ArtifactTestFiles::makeTempDir();
        mkdir($this->base.'/root');
    }

    protected function tearDown(): void
    {
        ArtifactTestFiles::remove($this->base);
    }

    public function testEnsureCreatesTheUserRootAndTheWorkingFolder(): void
    {
        $scope = new ArtifactScope($this->base.'/root/');

        $userRoot = $scope->ensure('user-1', self::CONVERSATION);

        self::assertSame(realpath($this->base.'/root').'/user-1', $userRoot);
        self::assertDirectoryExists($userRoot.'/'.self::CONVERSATION);
        self::assertSame(0o750, fileperms($userRoot.'/'.self::CONVERSATION) & 0o777);
        self::assertSame($this->base.'/root', $scope->root());

        // Idempotent: a second turn of the same conversation.
        self::assertSame($userRoot, $scope->ensure('user-1', self::CONVERSATION));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidSubjects(): iterable
    {
        yield 'parent traversal' => ['../x'];
        yield 'slash' => ['a/b'];
        yield 'empty' => [''];
        yield '129 characters' => [str_repeat('a', 129)];
        yield 'dot' => ['.'];
        yield 'dot dot' => ['..'];
        yield 'anonymous subject' => ['anon:0199e3a4'];
        yield 'NUL byte' => ["a\0b"];
    }

    #[DataProvider('invalidSubjects')]
    public function testInvalidSubjectsAreRefused(string $subject): void
    {
        self::assertFalse(ArtifactScope::isValidSubject($subject));

        $this->expectException(ArtifactScopeException::class);
        (new ArtifactScope($this->base.'/root'))->ensure($subject, self::CONVERSATION);
    }

    public function testValidSubjects(): void
    {
        self::assertTrue(ArtifactScope::isValidSubject('3f1c2d4e-aaaa-bbbb-cccc-1234567890ab'));
        self::assertTrue(ArtifactScope::isValidSubject(str_repeat('a', 128)));
        self::assertTrue(ArtifactScope::isValidSubject('service-account.x_1'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidConversationIds(): iterable
    {
        yield 'no uuid' => ['abc'];
        yield 'upper case' => [strtoupper(self::CONVERSATION)];
        yield 'traversal' => ['../'.self::CONVERSATION];
        yield 'empty' => [''];
    }

    #[DataProvider('invalidConversationIds')]
    public function testInvalidConversationIdsAreRefused(string $conversationId): void
    {
        $this->expectException(ArtifactScopeException::class);
        (new ArtifactScope($this->base.'/root'))->ensure('user-1', $conversationId);
    }

    public function testMissingRootIsRefused(): void
    {
        $this->expectException(ArtifactScopeException::class);
        $this->expectExceptionMessage('does not exist');
        (new ArtifactScope($this->base.'/missing'))->ensure('user-1', self::CONVERSATION);
    }

    public function testRootThatIsAFileIsRefused(): void
    {
        touch($this->base.'/file');

        $this->expectException(ArtifactScopeException::class);
        (new ArtifactScope($this->base.'/file'))->ensure('user-1', self::CONVERSATION);
    }

    public function testFolderThatCannotBeCreatedIsRefused(): void
    {
        // A file where the user root would be: mkdir fails even for root.
        touch($this->base.'/root/user-1');

        $this->expectException(ArtifactScopeException::class);
        (new ArtifactScope($this->base.'/root'))->ensure('user-1', self::CONVERSATION);
    }

    public function testNonWritableRootIsRefused(): void
    {
        if (0 === posix_geteuid()) {
            self::markTestSkipped('root writes into read-only directories; covered by testFolderThatCannotBeCreatedIsRefused().');
        }

        chmod($this->base.'/root', 0o555);

        try {
            $this->expectException(ArtifactScopeException::class);
            $this->expectExceptionMessage('not writable');
            (new ArtifactScope($this->base.'/root'))->ensure('user-1', self::CONVERSATION);
        } finally {
            chmod($this->base.'/root', 0o755);
        }
    }

    public function testUserRootSymlinkIsRefused(): void
    {
        mkdir($this->base.'/elsewhere');
        symlink($this->base.'/elsewhere', $this->base.'/root/user-1');

        $this->expectException(ArtifactScopeException::class);
        (new ArtifactScope($this->base.'/root'))->ensure('user-1', self::CONVERSATION);
    }

    public function testWorkingFolderSymlinkIsRefused(): void
    {
        mkdir($this->base.'/elsewhere');
        mkdir($this->base.'/root/user-1');
        symlink($this->base.'/elsewhere', $this->base.'/root/user-1/'.self::CONVERSATION);

        $this->expectException(ArtifactScopeException::class);
        (new ArtifactScope($this->base.'/root'))->ensure('user-1', self::CONVERSATION);
    }
}
