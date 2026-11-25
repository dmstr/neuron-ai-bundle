<?php

// file generated with AI assistance: Claude Code - 2026-10-05 18:50:29 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Integration\ProviderConnection;

use Dmstr\ApiConfiguration\Security\ConfigSecrets;
use Dmstr\NeuronAiBundle\Testing\FakeModelLister;
use Dmstr\NeuronAiBundle\Testing\ProviderConnectionFixtures;
use Dmstr\NeuronAiBundle\Tests\Kernel\TestDatabase;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `neuron:provider:connection:seed` against the database: create from
 * arguments and a seed file, the second run "unchanged" although the key is
 * stored encrypted, update in place, and rejected input without the key in
 * the output. Needs the migrated test database (CI only). Rows carry the
 * name prefix `test-provider-seed-`; the fake model lister proves that no
 * provider is contacted.
 */
#[Group('database')]
final class ProviderConnectionSeedFunctionalTest extends KernelTestCase
{
    private const string PREFIX = 'test-provider-seed-';
    private const string KEY = 'sk-ant-seed-functional-placeholder';

    private string $file;

    protected function setUp(): void
    {
        TestDatabase::migrate(static::bootKernel());
        self::bootKernel();
        self::cleanUp();
        $this->file = sys_get_temp_dir().'/provider-seed-'.bin2hex(random_bytes(4)).'.json';
        file_put_contents($this->file, json_encode([
            ['type' => 'ollama', 'name' => self::PREFIX.'remote', 'config' => ['url' => 'https://ollama.example.org/api', 'timeout' => 60]],
            ['type' => 'anthropic', 'name' => self::PREFIX.'claude', 'config' => ['api_key' => self::KEY]],
        ], \JSON_THROW_ON_ERROR));
    }

    protected function tearDown(): void
    {
        self::cleanUp();
        @unlink($this->file);
        parent::tearDown();
    }

    public function testSeedTwiceIsUnchangedTheSecondTime(): void
    {
        $input = ['name' => self::PREFIX.'local', '--type' => 'ollama', '--config' => '{"url": "http://ollama:11434/api"}', '--file' => $this->file];
        $tester = $this->command();
        self::assertSame(Command::SUCCESS, $tester->execute($input), $tester->getDisplay());
        self::assertSame(3, substr_count($tester->getDisplay(), 'created'));
        $before = self::rows();
        self::assertCount(3, $before);

        $stored = self::storedConfig($before[self::PREFIX.'claude']);
        self::assertStringStartsWith('enc:v1:', $stored['api_key']);
        self::assertSame(ProviderConnectionFixtures::anthropicConfig(self::KEY), static::getContainer()->get(ConfigSecrets::class)->decrypt($stored));
        self::assertSame(ProviderConnectionFixtures::ollamaConfig('http://ollama:11434/api'), self::storedConfig($before[self::PREFIX.'local']));

        // A fresh kernel reads the rows back from the database, as a second
        // seed run would.
        self::ensureKernelShutdown();
        self::bootKernel();
        $tester = $this->command();

        self::assertSame(Command::SUCCESS, $tester->execute($input), $tester->getDisplay());
        self::assertSame(3, substr_count($tester->getDisplay(), 'unchanged'));
        $after = self::rows();
        foreach ($before as $name => $row) {
            self::assertSame($row['updated_at'], $after[$name]['updated_at'], $name);
        }
        self::assertStringNotContainsString(self::KEY, $tester->getDisplay());
        self::assertSame([], static::getContainer()->get(FakeModelLister::class)->getListedConnections());
    }

    public function testChangedUrlUpdatesInPlace(): void
    {
        $tester = $this->command();
        self::assertSame(Command::SUCCESS, $tester->execute(['name' => self::PREFIX.'local', '--type' => 'ollama', '--config' => '{"url": "http://ollama:11434/api"}']), $tester->getDisplay());
        $before = self::rows();

        self::assertSame(Command::SUCCESS, $tester->execute(['name' => self::PREFIX.'local', '--type' => 'ollama', '--config' => '{"url": "http://moved:11434/api"}']), $tester->getDisplay());

        $after = self::rows();
        self::assertSame($before[self::PREFIX.'local']['id'], $after[self::PREFIX.'local']['id']);
        self::assertSame('http://moved:11434/api', self::storedConfig($after[self::PREFIX.'local'])['url']);
    }

    public function testInvalidEntryFailsWithoutTheKeyAndCreatesNothing(): void
    {
        file_put_contents($this->file, json_encode([
            ['type' => 'anthropic', 'name' => self::PREFIX.'claude', 'config' => ['api_key' => self::KEY, 'max_tokens' => 0]],
        ], \JSON_THROW_ON_ERROR));
        $tester = $this->command();

        self::assertSame(Command::FAILURE, $tester->execute(['--file' => $this->file]));
        self::assertStringContainsString('is invalid', $tester->getDisplay());
        self::assertStringNotContainsString(self::KEY, $tester->getDisplay());
        self::assertSame([], self::rows());
    }

    public function testNameOfTheOtherTypeIsRejected(): void
    {
        $tester = $this->command();
        self::assertSame(Command::SUCCESS, $tester->execute(['name' => self::PREFIX.'main', '--type' => 'ollama', '--config' => '{"url": "http://ollama:11434/api"}']), $tester->getDisplay());

        self::assertSame(Command::FAILURE, $tester->execute(['name' => self::PREFIX.'main', '--type' => 'anthropic', '--config' => json_encode(['api_key' => self::KEY], \JSON_THROW_ON_ERROR)]));
        self::assertCount(1, self::rows());
    }

    private function command(): CommandTester
    {
        return new CommandTester(new Application(static::$kernel)->find('neuron:provider:connection:seed'));
    }

    /**
     * @return array<string, array<string, mixed>> rows by name
     */
    private static function rows(): array
    {
        $rows = self::dbal()->fetchAllAssociative(
            'SELECT * FROM dmstr_api_configuration WHERE name LIKE :prefix ORDER BY name',
            ['prefix' => self::PREFIX.'%'],
        );

        return array_column($rows, null, 'name');
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private static function storedConfig(array $row): array
    {
        return json_decode((string) $row['config_json'], true, 512, \JSON_THROW_ON_ERROR);
    }

    private static function cleanUp(): void
    {
        self::dbal()->executeStatement('DELETE FROM dmstr_api_configuration WHERE name LIKE :prefix', ['prefix' => self::PREFIX.'%']);
    }

    private static function dbal(): Connection
    {
        if (null === static::$kernel) {
            static::bootKernel();
        }

        /** @var Connection $connection */
        $connection = static::getContainer()->get('doctrine.dbal.default_connection');

        return $connection;
    }
}
