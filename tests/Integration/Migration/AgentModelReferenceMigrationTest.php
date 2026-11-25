<?php

// file generated with AI assistance: Claude Code - 2026-10-08 16:54:10 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Integration\Migration;

use Dmstr\NeuronAiBundle\Provider\ModelReferenceMigrationSql;
use Dmstr\NeuronAiBundle\Tests\Kernel\TestDatabase;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The data part of migration B (DB, CI only): `Version20261008180000`
 * rewrites agent versions and candidates from `{connection, model: <name>}`
 * to the UUID of their catalogue entry, with the building blocks of
 * {@see ModelReferenceMigrationSql}. The test seeds legacy rows, runs the
 * statements of up() and then of down() and checks the rows: the exact
 * name first, `x` vs. `x:latest`, placeholder entries (one per pair,
 * untagged Ollama names with `:latest`), candidates matched by name, IRI
 * or partial UUID only when exactly one connection matches, unknown and
 * ambiguous connections, versions without a connection, and that every
 * other key keeps its order and text.
 *
 * Everything runs in a transaction that tearDown() rolls back, so the
 * migration's statements, which touch every row of the tables, leave the
 * test database as it was.
 */
#[Group('database')]
final class AgentModelReferenceMigrationTest extends KernelTestCase
{
    private const string MIGRATION = 'Dmstr\\NeuronAiBundle\\Migrations\\Version20261008180000';

    private const string PREFIX = 'test-model-ref-migration-';

    private const string OLLAMA = 'b1000000-0000-4000-8000-000000000001';
    private const string ANTHROPIC = 'b2000000-0000-4000-8000-000000000002';
    /** Two `ollama` connections whose ids share the prefix `b3000000`. */
    private const string TWIN_A = 'b3000000-0000-4000-8000-00000000000a';
    private const string TWIN_B = 'b3000000-0000-4000-8000-00000000000b';
    private const string MISSING = 'b9000000-0000-4000-8000-000000000009';

    private const string MISTRAL = 'c1000000-0000-4000-8000-000000000001';
    private const string MISTRAL_LATEST = 'c1000000-0000-4000-8000-000000000002';
    private const string LLAMA31_LATEST = 'c1000000-0000-4000-8000-000000000003';

    private const string AGENT = 'd1000000-0000-4000-8000-000000000001';

    /** A response schema whose properties are not in jsonb order (shorter keys first). */
    private const string RESPONSE_SCHEMA = '{"type": "object", "properties": {"status": {"type": "string"}, "message": {"type": "string"}, "processKey": {"type": "string"}, "files": {"type": "array"}, "removedFiles": {"type": "array"}, "openQuestions": {"type": "array"}, "deployment": {"type": "object"}}}';

    private Connection $connection;

    protected function setUp(): void
    {
        TestDatabase::migrate(static::bootKernel());
        self::bootKernel();
        $this->connection = self::getContainer()->get('doctrine.dbal.default_connection');
        $this->connection->beginTransaction();

        foreach ([
            [self::OLLAMA, 'ollama'],
            [self::ANTHROPIC, 'anthropic'],
            [self::TWIN_A, 'ollama'],
            [self::TWIN_B, 'ollama'],
        ] as [$id, $type]) {
            $this->connection->executeStatement(
                "INSERT INTO dmstr_api_configuration (id, name, type, endpoint_type, config_json, active, created_at, updated_at)
                 VALUES (?, ?, ?, 'rest', ?, true, NOW(), NOW())",
                [$id, self::fixtureName($id, $type), $type, json_encode(['type' => $type, 'endpoint_type' => 'rest'], \JSON_THROW_ON_ERROR)],
            );
        }

        foreach ([
            [self::MISTRAL, 'mistral'],
            [self::MISTRAL_LATEST, 'mistral:latest'],
            [self::LLAMA31_LATEST, 'llama3.1:latest'],
        ] as [$id, $name]) {
            $this->connection->executeStatement(
                "INSERT INTO neuron_provider_model (id, api_configuration_id, name, endpoint_fingerprint, available, created_at, updated_at)
                 VALUES (?, ?, ?, 'sha256:test', true, NOW(), NOW())",
                [$id, self::OLLAMA, $name],
            );
        }
    }

    protected function tearDown(): void
    {
        $this->connection->rollBack();
        parent::tearDown();
    }

    public function testUpRewritesVersionsAndKeepsEverythingElse(): void
    {
        $exact = $this->version(1, self::OLLAMA, 'mistral');
        $tagged = $this->version(2, strtoupper(self::OLLAMA), 'llama3.1');
        $untaggedA = $this->version(3, self::OLLAMA, 'llama3.2');
        $untaggedB = $this->version(4, self::OLLAMA, 'llama3.2');
        $anthropic = $this->version(5, self::ANTHROPIC, 'claude-test-1');
        $unknown = $this->version(6, self::MISSING, 'mistral');
        $legacy = $this->insertVersion(7, '{"instructions": "Hi", "provider": "ollama", "model": "mistral"}');
        $untouched = [$unknown => $this->text('neuron_agent_version', 'configuration', $unknown), $legacy => $this->text('neuron_agent_version', 'configuration', $legacy)];

        $this->migrate('up');

        self::assertSame(self::MISTRAL, $this->model($exact), 'The exact name wins over the tagged one.');
        self::assertSame(self::LLAMA31_LATEST, $this->model($tagged), 'An untagged name matches the tagged entry.');

        $placeholder = $this->entry(self::OLLAMA, 'llama3.2:latest');
        self::assertNotNull($placeholder, 'An untagged Ollama name gets a placeholder with :latest.');
        self::assertSame([false, ModelReferenceMigrationSql::PLACEHOLDER_FINGERPRINT], [$placeholder['available'], $placeholder['endpoint_fingerprint']]);
        self::assertNull($this->entry(self::OLLAMA, 'llama3.2'));
        self::assertSame($placeholder['id'], $this->model($untaggedA));
        self::assertSame($placeholder['id'], $this->model($untaggedB), 'One placeholder per pair.');

        $claude = $this->entry(self::ANTHROPIC, 'claude-test-1');
        self::assertNotNull($claude, 'An anthropic name is stored as given.');
        self::assertSame($claude['id'], $this->model($anthropic));

        foreach ($untouched as $id => $text) {
            self::assertSame($text, $this->text('neuron_agent_version', 'configuration', $id), 'A version without an existing connection stays as it is.');
        }

        // Only `model` and `connection` changed; the key order is the stored one at every depth.
        $configuration = $this->decoded('neuron_agent_version', 'configuration', $exact);
        self::assertSame(['instructions', 'model', 'responseSchema', 'startSchema', 'maxToolCalls'], array_keys($configuration));
        self::assertSame(['status', 'message', 'processKey', 'files', 'removedFiles', 'openQuestions', 'deployment'], array_keys($configuration['responseSchema']['properties']));
        self::assertSame(['zeta', 'alpha', 'mid'], array_keys($configuration['startSchema']['properties']));
        self::assertSame(json_decode(self::RESPONSE_SCHEMA, true), $configuration['responseSchema']);
    }

    public function testUpRewritesCandidatesByUniqueConnection(): void
    {
        $byName = $this->agent('by-name', ['connection' => self::fixtureName(self::ANTHROPIC, 'anthropic'), 'model' => 'claude-test-2']);
        $byIri = $this->agent('by-iri', ['connection' => ' /api/admin/api_configurations/'.strtoupper(self::OLLAMA).' ', 'model' => 'llama3.1']);
        $byPartial = $this->agent('by-partial', ['connection' => 'b1000000', 'model' => 'mistral']);
        $ambiguous = $this->agent('ambiguous', ['connection' => 'b3000000', 'model' => 'phi3']);
        $unknown = $this->agent('unknown', ['connection' => 'nowhere', 'model' => 'phi3']);
        $current = $this->agent('current', ['model' => '/api/refs/provider_models/'.self::MISTRAL]);
        $currentText = $this->text('neuron_agent', 'candidate', $current);

        $this->migrate('up');

        $claude = $this->entry(self::ANTHROPIC, 'claude-test-2');
        self::assertNotNull($claude);
        self::assertSame(['instructions' => 'Hi', 'model' => $claude['id'], 'maxToolCalls' => 3], $this->decoded('neuron_agent', 'candidate', $byName));
        self::assertSame(self::LLAMA31_LATEST, $this->decoded('neuron_agent', 'candidate', $byIri)['model']);
        self::assertSame(self::MISTRAL, $this->decoded('neuron_agent', 'candidate', $byPartial)['model']);

        // No unique connection: the name stays, `connection` goes, no placeholder.
        foreach ([$ambiguous, $unknown] as $id) {
            self::assertSame(['instructions' => 'Hi', 'model' => 'phi3', 'maxToolCalls' => 3], $this->decoded('neuron_agent', 'candidate', $id));
        }
        foreach ([self::TWIN_A, self::TWIN_B] as $twin) {
            self::assertNull($this->entry($twin, 'phi3:latest'));
        }

        self::assertSame($currentText, $this->text('neuron_agent', 'candidate', $current), 'A candidate without `connection` is not touched.');
    }

    public function testDownRestoresConnectionAndNameAndRemovesPlaceholders(): void
    {
        $exact = $this->version(1, self::OLLAMA, 'mistral');
        $untagged = $this->version(2, self::OLLAMA, 'llama3.2');
        $candidate = $this->agent('round-trip', ['connection' => 'b1000000', 'model' => 'llama3.1']);
        $before = $this->decoded('neuron_agent_version', 'configuration', $exact);

        $this->migrate('up');
        $this->migrate('down');

        self::assertSame($before, $this->decoded('neuron_agent_version', 'configuration', $exact), 'Connection and name are back, `connection` right before `model`.');

        $restored = $this->decoded('neuron_agent_version', 'configuration', $untagged);
        self::assertSame([self::OLLAMA, 'llama3.2:latest'], [$restored['connection'], $restored['model']], 'The name comes back as the entry stores it.');
        self::assertSame(['instructions', 'connection', 'model', 'responseSchema', 'startSchema', 'maxToolCalls'], array_keys($restored));

        self::assertSame(['instructions' => 'Hi', 'connection' => self::OLLAMA, 'model' => 'llama3.1:latest', 'maxToolCalls' => 3], $this->decoded('neuron_agent', 'candidate', $candidate));

        self::assertNull($this->entry(self::OLLAMA, 'llama3.2:latest'), 'The placeholder no scan took over is deleted.');
        self::assertNotNull($this->entry(self::OLLAMA, 'mistral'), 'Real entries stay.');
    }

    /**
     * Runs the statements of the migration's up() or down().
     */
    private function migrate(string $direction): void
    {
        require_once \dirname(__DIR__, 3).'/migrations/Version20261008180000.php';
        $class = self::MIGRATION;
        /** @var AbstractMigration $migration */
        $migration = new $class($this->connection, new NullLogger());
        $migration->{$direction}(new Schema());

        self::assertNotEmpty($migration->getSql());
        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }

    /**
     * A version in the legacy shape; keys deliberately not in jsonb order.
     */
    private function version(int $number, string $connection, string $model): string
    {
        return $this->insertVersion($number, \sprintf(
            '{"instructions": "Hi", "connection": %s, "model": %s, "responseSchema": %s, "startSchema": {"type": "object", "properties": {"zeta": {"type": "string"}, "alpha": {"type": "string"}, "mid": {"type": "integer"}}}, "maxToolCalls": 3}',
            json_encode($connection, \JSON_THROW_ON_ERROR),
            json_encode($model, \JSON_THROW_ON_ERROR),
            self::RESPONSE_SCHEMA,
        ));
    }

    private function insertVersion(int $number, string $configuration): string
    {
        if (false === $this->connection->fetchOne('SELECT 1 FROM neuron_agent WHERE id = ?', [self::AGENT])) {
            $this->insertAgent(self::AGENT, 'versions', '{}');
        }

        $id = \sprintf('d2000000-0000-4000-8000-%012d', $number);
        $this->connection->executeStatement(
            "INSERT INTO neuron_agent_version (id, agent_id, version_number, configuration, published_at, published_by)
             VALUES (?, ?, ?, ?, NOW(), 'test')",
            [$id, self::AGENT, $number, $configuration],
        );

        return $id;
    }

    /**
     * An agent whose candidate is `instructions`, $fields, `maxToolCalls`.
     *
     * @param array<string, string> $fields
     */
    private function agent(string $slug, array $fields): string
    {
        $id = \sprintf('d3000000-0000-4000-8000-%012d', crc32($slug));
        $this->insertAgent($id, $slug, json_encode(['instructions' => 'Hi'] + $fields + ['maxToolCalls' => 3], \JSON_THROW_ON_ERROR));

        return $id;
    }

    private function insertAgent(string $id, string $slug, string $candidate): void
    {
        $this->connection->executeStatement(
            'INSERT INTO neuron_agent (id, name, slug, enabled, candidate, created_at, updated_at) VALUES (?, ?, ?, true, ?, NOW(), NOW())',
            [$id, self::PREFIX.$slug, self::PREFIX.$slug, $candidate],
        );
    }

    /**
     * The `model` of a version's configuration.
     */
    private function model(string $version): mixed
    {
        return $this->decoded('neuron_agent_version', 'configuration', $version)['model'] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    private function decoded(string $table, string $column, string $id): array
    {
        return json_decode($this->text($table, $column, $id), true, 512, \JSON_THROW_ON_ERROR);
    }

    private function text(string $table, string $column, string $id): string
    {
        return (string) $this->connection->fetchOne(\sprintf('SELECT %s::text FROM %s WHERE id = ?', $column, $table), [$id]);
    }

    /**
     * The catalogue entry of a connection and an exact name.
     *
     * @return array{id: string, available: bool, endpoint_fingerprint: string}|null
     */
    private function entry(string $connection, string $name): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT id::text AS id, available, endpoint_fingerprint FROM neuron_provider_model WHERE api_configuration_id = ? AND name = ?',
            [$connection, $name],
        );
        if (false === $row) {
            return null;
        }

        return ['id' => (string) $row['id'], 'available' => (bool) $row['available'], 'endpoint_fingerprint' => (string) $row['endpoint_fingerprint']];
    }

    private static function fixtureName(string $id, string $type): string
    {
        return self::PREFIX.$type.'-'.substr($id, 0, 8);
    }
}
