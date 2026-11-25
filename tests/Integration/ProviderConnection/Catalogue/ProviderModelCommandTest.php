<?php

// file generated with AI assistance: Claude Code - 2026-10-06 18:00:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Integration\ProviderConnection\Catalogue;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use Dmstr\NeuronAiBundle\Tests\Support\ProviderCatalogueDatabaseTrait;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Console twins of the model catalogue (`neuron:provider-models:scan` and
 * `:sync`) against the fake model describer: synchronous, no job, the
 * failure exit code. Needs the migrated test database (CI only).
 */
#[Group('database')]
final class ProviderModelCommandTest extends ApiTestCase
{
    use ProviderCatalogueDatabaseTrait;

    protected static ?bool $alwaysBootKernel = true;

    public function testScanCommandWithOneConnection(): void
    {
        self::bootKernel();
        $remote = $this->connection('remote');
        $other = $this->connection('other', 'http://other.cmd.example.org:11434/api');
        $this->fake()->describe($remote->getName(), ['name' => 'llama3.1:8b', 'capabilities' => ['completion', 'tools']]);

        $tester = $this->command('neuron:provider-models:scan');
        $exitCode = $tester->execute(['--connection' => $remote->getName()]);

        self::assertSame(Command::SUCCESS, $exitCode, $tester->getDisplay());
        self::assertStringContainsString($remote->getName(), $tester->getDisplay());
        self::assertSame(['llama3.1:8b'], array_keys(self::rows($remote)));
        self::assertSame([], self::rows($other));
        self::assertSame(0, self::jobCount());
    }

    public function testScanCommandWithoutConnectionCoversAllProviderConnections(): void
    {
        self::bootKernel();
        $remote = $this->connection('remote');
        $claude = $this->connection('claude', anthropic: true);
        $this->fake()
            ->describe($remote->getName(), ['name' => 'qwen3:14b', 'capabilities' => ['completion', 'thinking', 'tools']])
            ->describe($claude->getName(), ['name' => 'claude-sonnet-5-5', 'capabilities' => ['completion', 'tools']]);

        $tester = $this->command('neuron:provider-models:scan');
        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode, $tester->getDisplay());
        self::assertSame(['qwen3:14b'], array_keys(self::rows($remote)));
        self::assertSame(['claude-sonnet-5-5'], array_keys(self::rows($claude)));
    }

    public function testScanCommandFailsWhenAConnectionFails(): void
    {
        self::bootKernel();
        $remote = $this->connection('remote');
        $this->fake()->unreachable($remote->getName());

        $tester = $this->command('neuron:provider-models:scan');
        $exitCode = $tester->execute(['--connection' => $remote->getName()]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('unreachable', $tester->getDisplay());
    }

    public function testScanCommandRejectsAnUnknownConnection(): void
    {
        self::bootKernel();

        $tester = $this->command('neuron:provider-models:scan');
        $exitCode = $tester->execute(['--connection' => self::PREFIX.'missing']);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('Unknown provider connection', $tester->getDisplay());
    }

    public function testSyncCommand(): void
    {
        self::bootKernel();
        $remote = $this->connection('remote');
        $model = $this->model($remote, 'llama3.1:8b', ['completion']);
        $this->fake()->describe($remote->getName(), ['name' => 'llama3.1:8b', 'capabilities' => ['completion', 'tools'], 'maxContextLength' => 131072]);

        $tester = $this->command('neuron:provider-models:sync');
        $exitCode = $tester->execute(['id' => (string) $model->getId()]);

        self::assertSame(Command::SUCCESS, $exitCode, $tester->getDisplay());
        self::assertStringContainsString('completion, tools', $tester->getDisplay());
        self::assertSame(['completion', 'tools'], json_decode((string) self::rows($remote)['llama3.1:8b']['capabilities'], true));
    }

    public function testSyncCommandOfAModelNoLongerOfferedFails(): void
    {
        self::bootKernel();
        $remote = $this->connection('remote');
        $model = $this->model($remote, 'gone:latest', ['completion']);

        $tester = $this->command('neuron:provider-models:sync');
        $exitCode = $tester->execute(['id' => (string) $model->getId()]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('no longer offered', $tester->getDisplay());
    }

    private function command(string $name): CommandTester
    {
        return new CommandTester((new Application(static::$kernel))->find($name));
    }
}
