<?php

// file generated with AI assistance: Claude Code - 2026-10-16 08:40:52 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\Artifact;

use Dmstr\NeuronAiBundle\Artifact\ArtifactAccess;
use Dmstr\NeuronAiBundle\Artifact\ArtifactLimitsInterface;
use Dmstr\NeuronAiBundle\Artifact\ArtifactScope;
use Dmstr\NeuronAiBundle\Artifact\ArtifactTool;
use Dmstr\NeuronAiBundle\Artifact\ArtifactToolkit;
use Dmstr\NeuronAiBundle\Artifact\ArtifactToolkitFactory;
use Dmstr\NeuronAiBundle\Artifact\DefaultArtifactLimits;
use Dmstr\NeuronAiBundle\Testing\InMemoryArtifactLedger;
use Dmstr\NeuronAiBundle\Tests\Support\ArtifactTestFiles;
use Dmstr\NeuronAiBundle\Tests\Support\ArtifactTestLimits;
use NeuronAI\Tools\Toolkits\FileSystem\ReadFileTool;
use NeuronAI\Tools\Toolkits\FileSystem\WriteFileTool;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\ToolOutput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

/**
 * Kernel-free: the artifact toolkit and its tool wrapper on a temporary
 * directory, with the in-memory ledger and an in-memory lock store.
 *
 * Layout: `<base>/root/<owner>` is the scope (user root), `<base>/outside`
 * holds a secret the tools must never reach, `<base>/root/<owner>2` is a
 * sibling sharing the scope's prefix.
 */
final class ArtifactToolTest extends TestCase
{
    private const string OWNER = 'user-1';
    private const string CONVERSATION = '0199e3a4-6b7c-7d8e-9f01-23456789abcd';
    private const string OTHER_CONVERSATION = '0199e3a4-6b7c-7d8e-9f01-000000000002';

    private string $base;

    private string $scopeRoot;

    private InMemoryArtifactLedger $ledger;

    private LockFactory $lockFactory;

    protected function setUp(): void
    {
        $this->base = ArtifactTestFiles::makeTempDir();
        mkdir($this->base.'/root');
        mkdir($this->base.'/outside');
        file_put_contents($this->base.'/outside/secret.txt', 'secret');
        mkdir($this->base.'/root/'.self::OWNER.'2');
        file_put_contents($this->base.'/root/'.self::OWNER.'2/x.txt', 'sibling');

        $this->scopeRoot = (new ArtifactScope($this->base.'/root'))->ensure(self::OWNER, self::CONVERSATION);
        $this->ledger = new InMemoryArtifactLedger();
        $this->lockFactory = new LockFactory(new InMemoryStore());
    }

    protected function tearDown(): void
    {
        ArtifactTestFiles::remove($this->base);
    }

    // --- tool set and guidelines -----------------------------------------

    public function testWritableToolkitOffersTheFiveTools(): void
    {
        $names = array_map(static fn (ToolInterface $tool): string => $tool->getName(), $this->toolkit()->tools());

        self::assertSame(ArtifactToolkit::TOOL_NAMES, $names);
        foreach ($this->toolkit()->tools() as $tool) {
            self::assertInstanceOf(ArtifactTool::class, $tool);
        }
    }

    public function testReadOnlyToolkitOffersOnlyTheReadTools(): void
    {
        $names = array_map(static fn (ToolInterface $tool): string => $tool->getName(), $this->toolkit(writable: false)->tools());

        self::assertSame(['read_file', 'grep_file_content', 'glob_path'], $names);
        self::assertSame(array_values(array_diff(ArtifactToolkit::TOOL_NAMES, ArtifactToolkit::WRITE_TOOL_NAMES)), $names);
    }

    public function testExcludeAndAddCannotChangeTheSet(): void
    {
        $toolkit = $this->toolkit();
        $toolkit->add(WriteFileTool::make($this->scopeRoot));
        $toolkit->exclude([]);

        self::assertCount(5, $toolkit->tools());
    }

    public function testGuidelinesNameTheWorkingFolderAndNoServerPath(): void
    {
        $guidelines = (string) $this->toolkit()->guidelines();

        self::assertStringContainsString(self::CONVERSATION.'/', $guidelines);
        self::assertStringContainsString('glob_path', $guidelines);
        self::assertStringContainsString("pattern: '**/*'", $guidelines);
        self::assertStringContainsString('grep_file_content', $guidelines);
        self::assertStringContainsString('write_file', $guidelines);
        self::assertStringNotContainsString($this->scopeRoot, $guidelines);
        self::assertStringNotContainsString($this->base, $guidelines);
        self::assertStringNotContainsString('parse_file', $guidelines);
        self::assertStringNotContainsString('bash', $guidelines);
        self::assertStringNotContainsString('delete_file', $guidelines);

        $readOnly = (string) $this->toolkit(writable: false)->guidelines();
        self::assertStringContainsString(self::CONVERSATION.'/', $readOnly);
        self::assertStringNotContainsString('write_file', $readOnly);
        self::assertStringNotContainsString('edit_file', $readOnly);
        self::assertStringNotContainsString($this->scopeRoot, $readOnly);
    }

    public function testFactoryBuildsTheToolkitOfTheAccess(): void
    {
        $factory = new ArtifactToolkitFactory(new DefaultArtifactLimits(), $this->ledger, $this->lockFactory);
        $toolkit = $factory->forAccess(new ArtifactAccess(self::OWNER, self::CONVERSATION, $this->scopeRoot, false));

        self::assertCount(3, $toolkit->tools());
    }

    public function testWrapperDelegatesWhatTheProviderNeeds(): void
    {
        foreach ($this->toolkit()->tools() as $tool) {
            $class = 'NeuronAI\\Tools\\Toolkits\\FileSystem\\'.str_replace(' ', '', ucwords(str_replace('_', ' ', $tool->getName()))).'Tool';
            /** @var ToolInterface $stock */
            $stock = $class::make($this->scopeRoot);

            self::assertSame($stock->getName(), $tool->getName());
            self::assertSame($stock->getDescription(), $tool->getDescription());
            self::assertEquals($stock->getProperties(), $tool->getProperties());
            self::assertSame($stock->getRequiredProperties(), $tool->getRequiredProperties());
            self::assertEquals($stock->getInputSchema(), $tool->getInputSchema());
            self::assertSame($stock->getParameters(), $tool->getParameters());
            self::assertSame($stock->getRunKey(), $tool->getRunKey());
            self::assertSame($stock->requiresApproval(), $tool->requiresApproval());
            self::assertTrue($tool->isVisible());
            self::assertFalse($tool->hasResult());
        }

        $tool = $this->tool('read_file');
        $tool->setInputs(['file_path' => 'a.txt'])->setCallId('call-1');
        self::assertSame(['file_path' => 'a.txt'], $tool->getInputs());
        self::assertSame('a.txt', $tool->getInput('file_path'));
        self::assertSame('call-1', $tool->getCallId());
        self::assertSame('read_file', $tool->jsonSerialize()['name']);
        self::assertSame('call-1', $tool->jsonSerialize()['callId']);
    }

    public function testCloneKeepsTheRegisteredToolUntouched(): void
    {
        $registered = $this->tool('read_file');
        $call = clone $registered;
        $call->setInputs(['file_path' => 'a.txt']);

        self::assertSame([], $registered->getInputs());
        self::assertSame(['file_path' => 'a.txt'], $call->getInputs());
    }

    // --- scope refusals -------------------------------------------------

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function escapes(): iterable
    {
        yield 'parent traversal' => ['read_file', '../../outside/secret.txt'];
        yield 'parent traversal on write' => ['write_file', '../../outside/new.txt'];
        yield 'absolute outside' => ['read_file', '{base}/outside/secret.txt'];
        yield 'absolute outside on write' => ['write_file', '{base}/outside/new.txt'];
        yield 'symlink file' => ['read_file', 'file-link.txt'];
        yield 'symlink file on write' => ['write_file', 'file-link.txt'];
        yield 'symlink directory' => ['read_file', 'dir-link/secret.txt'];
        yield 'symlink directory on write' => ['write_file', 'dir-link/new.txt'];
        yield 'symlink directory on glob' => ['glob_path', 'dir-link'];
        yield 'dangling symlink' => ['read_file', 'dangling.txt'];
        yield 'dangling symlink on write' => ['write_file', 'dangling.txt'];
        yield 'sibling prefix relative' => ['read_file', '../'.self::OWNER.'2/x.txt'];
        yield 'sibling prefix absolute' => ['read_file', '{base}/root/'.self::OWNER.'2/x.txt'];
        yield 'sibling prefix on write' => ['write_file', '{base}/root/'.self::OWNER.'2/new.txt'];
        yield 'grep outside' => ['grep_file_content', '../../outside/secret.txt'];
        yield 'edit outside' => ['edit_file', '../../outside/secret.txt'];
    }

    #[DataProvider('escapes')]
    public function testPathsOutsideTheScopeAreRefused(string $name, string $path): void
    {
        symlink($this->base.'/outside/secret.txt', $this->scopeRoot.'/file-link.txt');
        symlink($this->base.'/outside', $this->scopeRoot.'/dir-link');
        symlink($this->base.'/outside/missing.txt', $this->scopeRoot.'/dangling.txt');

        $path = str_replace('{base}', $this->base, $path);
        $output = $this->call($name, match ($name) {
            'write_file' => ['file_path' => $path, 'content' => 'x'],
            'edit_file' => ['file_path' => $path, 'search' => 'secret', 'replace' => 'leak'],
            'grep_file_content' => ['file_path' => $path, 'pattern' => '/secret/'],
            'glob_path' => ['directory' => $path, 'pattern' => '*'],
            default => ['file_path' => $path],
        });

        self::assertInstanceOf(ToolOutput::class, $output);
        self::assertTrue($output->isError(), $output->getText());
        self::assertStringContainsString('Access denied', $output->getText());
        $this->assertNoServerPath($output->getText());
        self::assertSame('secret', file_get_contents($this->base.'/outside/secret.txt'));
        self::assertFileDoesNotExist($this->base.'/outside/new.txt');
        self::assertFileDoesNotExist($this->base.'/root/'.self::OWNER.'2/new.txt');
        self::assertSame([], $this->ledger->rows());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidPaths(): iterable
    {
        yield 'NUL byte' => ["a\0b.txt", 'NUL byte'];
        yield 'path over 1024 bytes' => [str_repeat('abcdefgh/', 114).'x.txt', 'longer than 1024 bytes'];
        yield 'name over 255 bytes' => [str_repeat('n', 256).'.txt', 'name is longer than 255 bytes'];
    }

    #[DataProvider('invalidPaths')]
    public function testInvalidPathsAreRefusedWithoutPath(string $path, string $reason): void
    {
        foreach (['read_file' => [], 'write_file' => ['content' => 'x'], 'glob_path' => null] as $name => $extra) {
            $inputs = null === $extra ? ['directory' => $path, 'pattern' => '*'] : ['file_path' => $path, ...$extra];
            $output = $this->call($name, $inputs);

            self::assertInstanceOf(ToolOutput::class, $output);
            self::assertTrue($output->isError());
            self::assertStringContainsString($reason, $output->getText());
            self::assertStringNotContainsString(substr($path, 0, 20), $output->getText());
        }
    }

    public function testAbsolutePathInsideTheScopeIsUsedRelative(): void
    {
        $result = $this->call('write_file', ['file_path' => $this->scopeRoot.'/'.self::CONVERSATION.'/a.md', 'content' => 'hello']);

        self::assertIsString($result);
        $this->assertNoServerPath($result);
        self::assertSame(self::CONVERSATION.'/a.md', json_decode($result, true, flags: \JSON_THROW_ON_ERROR)['file_path']);
        self::assertSame('hello', file_get_contents($this->scopeRoot.'/'.self::CONVERSATION.'/a.md'));
        self::assertSame(self::CONVERSATION.'/a.md', $this->ledger->find(self::OWNER, self::CONVERSATION.'/a.md')?->path);

        $read = $this->call('read_file', ['file_path' => $this->scopeRoot.'/'.self::CONVERSATION.'/a.md']);
        self::assertIsString($read);
        self::assertStringStartsWith('hello', $read);

        $listing = $this->call('glob_path', ['directory' => $this->scopeRoot, 'pattern' => '**/*']);
        self::assertIsString($listing);
        $this->assertNoServerPath($listing);
        self::assertStringContainsString("in directory '.'", $listing);
        self::assertStringContainsString(self::CONVERSATION.'/a.md', $listing);
    }

    public function testNoErrorTextContainsTheScopeRoot(): void
    {
        $outputs = [
            $this->call('read_file', ['file_path' => 'missing.txt']),
            $this->call('glob_path', ['directory' => 'missing', 'pattern' => '*']),
            $this->call('grep_file_content', ['file_path' => 'missing.txt', 'pattern' => '/x/']),
            $this->call('write_file', ['file_path' => self::CONVERSATION, 'content' => 'x']),
            $this->call('edit_file', ['file_path' => 'missing.txt', 'search' => 'a', 'replace' => 'b']),
            $this->call('read_file', ['file_path' => '../../outside/secret.txt']),
        ];

        file_put_contents($this->scopeRoot.'/latin1.txt', "\xE4\xF6\xFC");
        $outputs[] = $this->call('read_file', ['file_path' => 'latin1.txt']);
        file_put_contents($this->scopeRoot.'/text.txt', 'some text');
        $outputs[] = $this->call('grep_file_content', ['file_path' => 'text.txt', 'pattern' => 'no delimiters']);

        foreach ($outputs as $output) {
            self::assertInstanceOf(ToolOutput::class, $output);
            self::assertTrue($output->isError());
            $this->assertNoServerPath($output->getText());
            self::assertStringNotContainsString('parse_file', $output->getText());
        }
    }

    public function testWriteIntoAFolderThatCannotBeCreatedHidesThePath(): void
    {
        // A file where the folder would be: mkdir fails and the stock error names the absolute directory.
        file_put_contents($this->scopeRoot.'/'.self::CONVERSATION.'/blocker', 'x');

        $output = $this->call('write_file', ['file_path' => self::CONVERSATION.'/blocker/sub/a.md', 'content' => 'x']);

        self::assertInstanceOf(ToolOutput::class, $output);
        self::assertTrue($output->isError());
        self::assertStringContainsString(self::CONVERSATION.'/blocker/sub', $output->getText());
        $this->assertNoServerPath($output->getText());
    }

    // --- limits -----------------------------------------------------------

    public function testFileSizeLimit(): void
    {
        $atLimit = $this->call('write_file', ['file_path' => self::CONVERSATION.'/a.txt', 'content' => str_repeat('a', 100)]);
        self::assertIsString($atLimit);

        $over = $this->call('write_file', ['file_path' => self::CONVERSATION.'/b.txt', 'content' => str_repeat('a', 101)]);
        self::assertInstanceOf(ToolOutput::class, $over);
        self::assertTrue($over->isError());
        self::assertStringContainsString('limit of 100 bytes per file', $over->getText());
        self::assertFileDoesNotExist($this->scopeRoot.'/'.self::CONVERSATION.'/b.txt');
    }

    public function testFilesPerConversationFolder(): void
    {
        for ($i = 1; $i <= 3; ++$i) {
            self::assertIsString($this->call('write_file', ['file_path' => self::CONVERSATION.'/f'.$i.'.txt', 'content' => 'x']));
        }

        $fourth = $this->call('write_file', ['file_path' => self::CONVERSATION.'/sub/f4.txt', 'content' => 'x']);
        self::assertInstanceOf(ToolOutput::class, $fourth);
        self::assertTrue($fourth->isError());
        self::assertStringContainsString('already holds 3 files', $fourth->getText());
        self::assertFileDoesNotExist($this->scopeRoot.'/'.self::CONVERSATION.'/sub/f4.txt');

        // Overwriting and editing an existing file and writing outside the working folder still work.
        self::assertIsString($this->call('write_file', ['file_path' => self::CONVERSATION.'/f1.txt', 'content' => 'y']));
        self::assertIsString($this->call('edit_file', ['file_path' => self::CONVERSATION.'/f2.txt', 'search' => 'x', 'replace' => 'z']));
        self::assertIsString($this->call('write_file', ['file_path' => 'notes.txt', 'content' => 'x']));
    }

    public function testDefaultLimitAllowsFiftyFilesPerConversation(): void
    {
        $limits = new DefaultArtifactLimits();
        for ($i = 1; $i <= 50; ++$i) {
            self::assertIsString($this->call('write_file', ['file_path' => self::CONVERSATION.'/f'.$i.'.txt', 'content' => 'x'], limits: $limits));
        }

        $output = $this->call('write_file', ['file_path' => self::CONVERSATION.'/f51.txt', 'content' => 'x'], limits: $limits);
        self::assertInstanceOf(ToolOutput::class, $output);
        self::assertTrue($output->isError());
        self::assertStringContainsString('already holds 50 files', $output->getText());
    }

    public function testUserQuota(): void
    {
        self::assertIsString($this->call('write_file', ['file_path' => self::CONVERSATION.'/a.txt', 'content' => str_repeat('a', 100)]));
        self::assertIsString($this->call('write_file', ['file_path' => self::CONVERSATION.'/b.txt', 'content' => str_repeat('b', 100)]));

        $over = $this->call('write_file', ['file_path' => 'c.txt', 'content' => str_repeat('c', 51)]);
        self::assertInstanceOf(ToolOutput::class, $over);
        self::assertTrue($over->isError());
        self::assertStringContainsString('quota of 250 bytes', $over->getText());
        self::assertFileDoesNotExist($this->scopeRoot.'/c.txt');

        // Exactly at the quota passes; an overwrite counts the new size only.
        self::assertIsString($this->call('write_file', ['file_path' => 'c.txt', 'content' => str_repeat('c', 50)]));
        self::assertIsString($this->call('write_file', ['file_path' => self::CONVERSATION.'/a.txt', 'content' => str_repeat('a', 90)]));
        self::assertSame(240, $this->ledger->totalSize(self::OWNER));
    }

    public function testCaseCollision(): void
    {
        self::assertIsString($this->call('write_file', ['file_path' => self::CONVERSATION.'/Readme.md', 'content' => 'one']));

        $output = $this->call('write_file', ['file_path' => self::CONVERSATION.'/readme.md', 'content' => 'two']);

        self::assertInstanceOf(ToolOutput::class, $output);
        self::assertTrue($output->isError());
        self::assertStringContainsString('name differs only in case from '.self::CONVERSATION.'/Readme.md', $output->getText());
        self::assertFileDoesNotExist($this->scopeRoot.'/'.self::CONVERSATION.'/readme.md');
        self::assertSame('one', file_get_contents($this->scopeRoot.'/'.self::CONVERSATION.'/Readme.md'));
    }

    public function testReadLimit(): void
    {
        file_put_contents($this->scopeRoot.'/short.txt', str_repeat('ä', 20));
        file_put_contents($this->scopeRoot.'/long.txt', str_repeat('ä', 21).'SECRET-CONTENT');
        file_put_contents($this->scopeRoot.'/huge.txt', str_repeat('a', 81));

        $short = $this->call('read_file', ['file_path' => 'short.txt']);
        self::assertIsString($short);
        self::assertStringStartsWith(str_repeat('ä', 20), $short);

        foreach (['long.txt', 'huge.txt'] as $file) {
            $output = $this->call('read_file', ['file_path' => $file]);
            self::assertInstanceOf(ToolOutput::class, $output);
            self::assertTrue($output->isError());
            self::assertStringContainsString('read limit of 20 characters', $output->getText());
            self::assertStringContainsString('grep_file_content', $output->getText());
            self::assertStringNotContainsString('SECRET-CONTENT', $output->getText());
            self::assertStringNotContainsString('aaaa', $output->getText());
        }

        $grep = $this->call('grep_file_content', ['file_path' => 'long.txt', 'pattern' => '/SECRET/']);
        self::assertIsString($grep);
        self::assertStringContainsString('SECRET', $grep);
    }

    public function testEditFile(): void
    {
        self::assertIsString($this->call('write_file', ['file_path' => self::CONVERSATION.'/doc.md', 'content' => 'alpha beta beta']));

        $one = $this->call('edit_file', ['file_path' => self::CONVERSATION.'/doc.md', 'search' => 'alpha', 'replace' => 'gamma!'], conversation: self::OTHER_CONVERSATION);
        self::assertIsString($one);
        $this->assertNoServerPath($one);
        self::assertSame('gamma! beta beta', file_get_contents($this->scopeRoot.'/'.self::CONVERSATION.'/doc.md'));
        self::assertSame(16, $this->ledger->find(self::OWNER, self::CONVERSATION.'/doc.md')?->sizeBytes);
        $row = $this->ledger->rows()[self::OWNER][self::CONVERSATION.'/doc.md'];
        self::assertSame(self::CONVERSATION, $row['createdBy']);
        self::assertSame(self::OTHER_CONVERSATION, $row['updatedBy']);

        $none = $this->call('edit_file', ['file_path' => self::CONVERSATION.'/doc.md', 'search' => 'delta', 'replace' => 'x']);
        self::assertInstanceOf(ToolOutput::class, $none);
        self::assertTrue($none->isError());
        self::assertStringContainsString('Search string not found', $none->getText());
        $this->assertNoServerPath($none->getText());

        $two = $this->call('edit_file', ['file_path' => self::CONVERSATION.'/doc.md', 'search' => 'beta', 'replace' => 'x']);
        self::assertInstanceOf(ToolOutput::class, $two);
        self::assertTrue($two->isError());
        self::assertStringContainsString('appears 2 times', $two->getText());

        $tooLarge = $this->call('edit_file', ['file_path' => self::CONVERSATION.'/doc.md', 'search' => 'gamma!', 'replace' => str_repeat('g', 91)]);
        self::assertInstanceOf(ToolOutput::class, $tooLarge);
        self::assertTrue($tooLarge->isError());
        self::assertStringContainsString('limit of 100 bytes per file', $tooLarge->getText());
        self::assertSame('gamma! beta beta', file_get_contents($this->scopeRoot.'/'.self::CONVERSATION.'/doc.md'));
    }

    // --- write gate -------------------------------------------------------

    public function testReadOnlyWrapperRefusesWriteTools(): void
    {
        $access = new ArtifactAccess(self::OWNER, self::CONVERSATION, $this->scopeRoot, false);
        $tool = new ArtifactTool(WriteFileTool::make($this->scopeRoot), $access, new ArtifactTestLimits(), $this->ledger, $this->lockFactory);
        $tool->setInputs(['file_path' => self::CONVERSATION.'/a.txt', 'content' => 'x']);
        $tool->execute();

        $output = $tool->getResult();
        self::assertInstanceOf(ToolOutput::class, $output);
        self::assertTrue($output->isError());
        self::assertStringContainsString('read-only', $output->getText());
        self::assertFileDoesNotExist($this->scopeRoot.'/'.self::CONVERSATION.'/a.txt');

        // Reading stays possible.
        file_put_contents($this->scopeRoot.'/a.txt', 'readable');
        $read = new ArtifactTool(ReadFileTool::make($this->scopeRoot), $access, new ArtifactTestLimits(), $this->ledger, $this->lockFactory);
        $read->setInputs(['file_path' => 'a.txt']);
        $read->execute();
        self::assertStringStartsWith('readable', (string) $read->getResult());
    }

    // --- lock ---------------------------------------------------------------

    public function testHeldLockRefusesTheWrite(): void
    {
        $held = $this->lockFactory->createLock('artifact:'.self::OWNER);
        self::assertTrue($held->acquire());

        try {
            $output = $this->call('write_file', ['file_path' => self::CONVERSATION.'/a.txt', 'content' => 'x']);
        } finally {
            $held->release();
        }

        self::assertInstanceOf(ToolOutput::class, $output);
        self::assertTrue($output->isError());
        self::assertStringContainsString('in progress', $output->getText());
        self::assertFileDoesNotExist($this->scopeRoot.'/'.self::CONVERSATION.'/a.txt');
        self::assertSame([], $this->ledger->rows());

        // Released: the next write passes, and its lock is released again.
        self::assertIsString($this->call('write_file', ['file_path' => self::CONVERSATION.'/a.txt', 'content' => 'x']));
        $probe = $this->lockFactory->createLock('artifact:'.self::OWNER);
        self::assertTrue($probe->acquire(false));
        $probe->release();
    }

    public function testLockOfAnotherOwnerDoesNotBlock(): void
    {
        $held = $this->lockFactory->createLock('artifact:someone-else');
        self::assertTrue($held->acquire());

        self::assertIsString($this->call('write_file', ['file_path' => self::CONVERSATION.'/a.txt', 'content' => 'x']));
        $held->release();
    }

    public function testTwoConversationsCannotBothPassTheQuota(): void
    {
        // Two turns of the same user (two conversations, two toolkits) share ledger and lock store.
        (new ArtifactScope($this->base.'/root'))->ensure(self::OWNER, self::OTHER_CONVERSATION);
        $first = $this->toolkit(conversation: self::CONVERSATION);
        $second = $this->toolkit(conversation: self::OTHER_CONVERSATION);
        self::assertIsString($this->callOn($first, 'write_file', ['file_path' => self::CONVERSATION.'/base.txt', 'content' => str_repeat('a', 100)]));

        // 150 bytes left: each write of 100 fits alone, not both.
        $a = $this->callOn($first, 'write_file', ['file_path' => self::CONVERSATION.'/a.txt', 'content' => str_repeat('a', 100)]);
        $b = $this->callOn($second, 'write_file', ['file_path' => self::OTHER_CONVERSATION.'/b.txt', 'content' => str_repeat('b', 100)]);

        self::assertIsString($a);
        self::assertInstanceOf(ToolOutput::class, $b);
        self::assertTrue($b->isError());
        self::assertStringContainsString('quota', $b->getText());
        self::assertSame(200, $this->ledger->totalSize(self::OWNER));
        self::assertFileDoesNotExist($this->scopeRoot.'/'.self::OTHER_CONVERSATION.'/b.txt');
    }

    // --- recording ----------------------------------------------------------

    public function testErrorsStayToolOutputErrors(): void
    {
        $tool = $this->tool('write_file');
        $tool->setInputs(['file_path' => '../x', 'content' => 'x']);
        $tool->execute();

        self::assertTrue($tool->hasResult());
        $result = $tool->getResult();
        self::assertInstanceOf(ToolOutput::class, $result);
        self::assertTrue($result->isError());
        self::assertSame(['is_error' => true, 'blocks' => $result->jsonSerialize()['blocks']], $tool->jsonSerialize()['result']);
        $this->assertNoServerPath((string) json_encode($tool->jsonSerialize()));
    }

    public function testMissingInputIsReportedByTheStockTool(): void
    {
        $output = $this->call('write_file', ['file_path' => self::CONVERSATION.'/a.txt']);

        self::assertInstanceOf(ToolOutput::class, $output);
        self::assertTrue($output->isError());
        self::assertStringContainsString('content', $output->getText());
        self::assertSame([], $this->ledger->rows());
    }

    // --- helpers ------------------------------------------------------------

    private function toolkit(bool $writable = true, string $conversation = self::CONVERSATION, ?ArtifactLimitsInterface $limits = null): ArtifactToolkit
    {
        return new ArtifactToolkit(
            new ArtifactAccess(self::OWNER, $conversation, $this->scopeRoot, $writable),
            $limits ?? new ArtifactTestLimits(),
            $this->ledger,
            $this->lockFactory,
            0.1,
        );
    }

    private function tool(string $name, string $conversation = self::CONVERSATION, ?ArtifactLimitsInterface $limits = null): ToolInterface
    {
        foreach ($this->toolkit(conversation: $conversation, limits: $limits)->tools() as $tool) {
            if ($tool->getName() === $name) {
                return $tool;
            }
        }

        self::fail(sprintf('Tool %s is not offered.', $name));
    }

    /**
     * Runs one call the way the agent loop does: on a clone of the
     * registered tool.
     *
     * @param array<string, mixed> $inputs
     */
    private function call(string $name, array $inputs, string $conversation = self::CONVERSATION, ?ArtifactLimitsInterface $limits = null): string|ToolOutput
    {
        $tool = clone $this->tool($name, $conversation, $limits);
        $tool->setInputs($inputs);
        $tool->execute();

        return $tool->getResult();
    }

    /**
     * @param array<string, mixed> $inputs
     */
    private function callOn(ArtifactToolkit $toolkit, string $name, array $inputs): string|ToolOutput
    {
        foreach ($toolkit->tools() as $tool) {
            if ($tool->getName() === $name) {
                $call = clone $tool;
                $call->setInputs($inputs);
                $call->execute();

                return $call->getResult();
            }
        }

        self::fail(sprintf('Tool %s is not offered.', $name));
    }

    /**
     * The scope root as a path (followed by a separator, a quote or the end)
     * must not appear; a sibling the model named itself (`<root>2`) is its
     * own input.
     */
    private function assertNoServerPath(string $text): void
    {
        foreach ([$this->scopeRoot, substr((string) json_encode($this->scopeRoot), 1, -1)] as $root) {
            self::assertDoesNotMatchRegularExpression('#'.preg_quote($root, '#').'(?![A-Za-z0-9._-])#', $text);
        }
        self::assertStringNotContainsString("working scope '/", $text);
    }
}
