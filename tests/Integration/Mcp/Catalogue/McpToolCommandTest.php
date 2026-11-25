<?php

// file generated with AI assistance: Claude Code - 2026-09-30 21:41:04 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Integration\Mcp\Catalogue;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use Dmstr\NeuronAiBundle\Tests\Support\CatalogueDatabaseTrait;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Console twins of scan and sync (`neuron:mcp-tools:scan`,
 * `neuron:mcp-tools:sync`) produce the same rows as the API, synchronously
 * and without a job. Needs the migrated test database (CI only).
 */
#[Group('database')]
final class McpToolCommandTest extends ApiTestCase
{
    use CatalogueDatabaseTrait;

    protected static ?bool $alwaysBootKernel = true;

    private const string DOCS = 'https://docs.cmd.example.org/mcp';
    private const string WIKI = 'https://wiki.cmd.example.org/mcp';
    private const string OLD = 'https://old.cmd.example.org/mcp';

    public function testScanCommandMirrorsAllActiveConnections(): void
    {
        self::bootKernel();
        $docs = $this->connection('docs', self::DOCS);
        $wiki = $this->connection('wiki', self::WIKI);
        $old = $this->connection('old', self::OLD, active: false);
        $this->tool($docs, 'gone');
        $fake = $this->fake();
        $fake->serveTools([['name' => 'search', 'description' => 'Search', 'inputSchema' => ['type' => 'object']]], self::DOCS);
        $fake->serveTools([['name' => 'page', 'inputSchema' => ['type' => 'object']]], self::WIKI);

        $tester = $this->command('neuron:mcp-tools:scan');
        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode, $tester->getDisplay());
        self::assertStringContainsString(self::PREFIX.'docs', $tester->getDisplay());
        self::assertSame(['search'], array_keys(self::rows($docs)));
        self::assertSame('Search', self::rows($docs)['search']['description']);
        self::assertSame(['page'], array_keys(self::rows($wiki)));
        self::assertSame(0, $fake->getRequestCount(self::OLD));
        self::assertSame([], self::rows($old));
        self::assertSame(0, self::jobCount());
    }

    public function testScanCommandWithOneConnection(): void
    {
        self::bootKernel();
        $docs = $this->connection('docs', self::DOCS);
        $wiki = $this->connection('wiki', self::WIKI);
        $this->fake()->serveTools([['name' => 'search', 'inputSchema' => ['type' => 'object']]]);

        $tester = $this->command('neuron:mcp-tools:scan');

        self::assertSame(Command::SUCCESS, $tester->execute(['--connection' => $docs->getName()]), $tester->getDisplay());
        self::assertSame(['search'], array_keys(self::rows($docs)));
        self::assertSame([], self::rows($wiki));
    }

    public function testScanCommandFailsForAFailingConnection(): void
    {
        self::bootKernel();
        $docs = $this->connection('docs', self::DOCS);
        $this->fake()->rejectCredentials(self::DOCS);

        $tester = $this->command('neuron:mcp-tools:scan');

        self::assertSame(Command::FAILURE, $tester->execute(['--connection' => $docs->getName()]));
        self::assertStringContainsString('Authentication failed', $tester->getDisplay());
    }

    public function testScanCommandRejectsAnUnknownConnection(): void
    {
        self::bootKernel();

        $tester = $this->command('neuron:mcp-tools:scan');

        self::assertSame(Command::FAILURE, $tester->execute(['--connection' => self::PREFIX.'missing']));
        self::assertStringContainsString('Unknown MCP connection', $tester->getDisplay());
    }

    public function testSyncCommand(): void
    {
        self::bootKernel();
        $docs = $this->connection('docs', self::DOCS);
        $tool = $this->tool($docs, 'search', 'Old');
        $fake = $this->fake();
        $fake->serveTools([['name' => 'search', 'description' => 'New', 'inputSchema' => ['type' => 'object']]], self::DOCS);

        $tester = $this->command('neuron:mcp-tools:sync');

        self::assertSame(Command::SUCCESS, $tester->execute(['id' => (string) $tool->getId()]), $tester->getDisplay());
        self::assertSame('New', self::rows($docs)['search']['description']);

        $fake->serveTools([], self::DOCS);
        self::assertSame(Command::FAILURE, $tester->execute(['id' => (string) $tool->getId()]));
        self::assertStringContainsString('no longer listed', (string) self::rows($docs)['search']['last_error']);
    }

    public function testSyncCommandRejectsAnUnknownId(): void
    {
        self::bootKernel();

        $tester = $this->command('neuron:mcp-tools:sync');

        self::assertSame(Command::FAILURE, $tester->execute(['id' => 'not-a-uuid']));
    }

    private function command(string $name): CommandTester
    {
        $application = new Application(static::$kernel);

        return new CommandTester($application->find($name));
    }
}
