<?php

// file generated with AI assistance: Claude Code - 2026-10-01 13:37:11 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Integration\Reindex;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use Dmstr\NeuronAiBundle\Tests\Kernel\TestDatabase;
use Dmstr\NeuronAiBundle\Tests\Support\AgentFixtures;
use Dmstr\NeuronAiBundle\Tests\Support\ConversationConnections;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * neuron:embedding:index:sync creates the missing vector index of a model
 * in use and leaves existing ones alone.
 *
 * Every test starts without collections and vectors
 * ({@see TestDatabase::purge()}) and without the runtime index of
 * `mxbai-embed-large`; collections embed through the `ollama` fixture
 * connection of {@see AgentFixtures}.
 */
#[Group('database')]
final class EmbeddingIndexSyncCommandTest extends ApiTestCase
{
    protected static ?bool $alwaysBootKernel = true;

    /** substr(sha1('mxbai-embed-large'), 0, 12) */
    private const string MXBAI_INDEX = 'idx_embedding_vector_d281fab09939';

    protected function setUp(): void
    {
        self::reset();
    }

    protected function tearDown(): void
    {
        self::reset();
        parent::tearDown();
    }

    public function testCreatesTheMissingIndexAndThenReportsItAsExisting(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/admin/collections', [
            'headers' => ['Content-Type' => 'application/ld+json', 'Accept' => 'application/ld+json'],
            'json' => ['name' => 'docs-en', 'embeddingModel' => ConversationConnections::modelIri('ollama', 'mxbai-embed-large:latest')],
        ]);
        self::assertResponseStatusCodeSame(201);
        self::assertTrue($this->indexExists());
        TestDatabase::connection(static::$kernel)->executeStatement('DROP INDEX '.self::MXBAI_INDEX);
        self::assertFalse($this->indexExists());
        self::ensureKernelShutdown();

        $first = $this->runCommand();
        self::assertMatchesRegularExpression('/mxbai-embed-large\s+'.self::MXBAI_INDEX.'\s+created/', $first);
        self::assertStringContainsString('1 index(es) created, 0 already existed', $first);
        self::assertTrue($this->indexExists());

        $second = $this->runCommand();
        self::assertMatchesRegularExpression('/mxbai-embed-large\s+'.self::MXBAI_INDEX.'\s+existing/', $second);
        self::assertStringContainsString('0 index(es) created, 1 already existed', $second);
    }

    public function testWithoutCollectionsThereIsNothingToDo(): void
    {
        self::assertStringContainsString('nothing to do', $this->runCommand());
    }

    private function runCommand(): string
    {
        $tester = new CommandTester(new Application(static::bootKernel())->find('neuron:embedding:index:sync'));
        self::assertSame(Command::SUCCESS, $tester->execute([]), $tester->getDisplay());
        $display = $tester->getDisplay();
        self::ensureKernelShutdown();

        return $display;
    }

    private function indexExists(): bool
    {
        return (bool) TestDatabase::connection(static::bootKernel())->fetchOne('SELECT to_regclass(:name) IS NOT NULL', ['name' => self::MXBAI_INDEX]);
    }

    private static function reset(): void
    {
        $kernel = static::bootKernel();
        TestDatabase::migrate($kernel);
        $connection = TestDatabase::connection($kernel);
        TestDatabase::purge($connection);
        $connection->executeStatement('DROP INDEX IF EXISTS '.self::MXBAI_INDEX);
        static::ensureKernelShutdown();
    }
}
