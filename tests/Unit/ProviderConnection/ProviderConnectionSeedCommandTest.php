<?php

// file generated with AI assistance: Claude Code - 2026-10-05 18:50:29 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\ProviderConnection;

use Dmstr\ApiConfiguration\Entity\ApiConfiguration;
use Dmstr\NeuronAiBundle\Command\ProviderConnectionSeedCommand;
use Dmstr\NeuronAiBundle\Testing\ConfigSecretsFixture;
use Dmstr\NeuronAiBundle\Testing\ProviderConnectionFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Kernel-free checks of `neuron:provider:connection:seed` with a stubbed
 * entity manager and validator: input parsing, created/updated/unchanged
 * (also with an encrypted key), type clash, and no secret in the output.
 * The database-backed runs are in ProviderConnectionSeedFunctionalTest.
 */
final class ProviderConnectionSeedCommandTest extends TestCase
{
    private const string KEY = 'sk-ant-seed-placeholder-9999';

    /** @var list<ApiConfiguration> */
    private array $stored = [];

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/provider-seed-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map(unlink(...), glob($this->dir.'/*') ?: []);
        rmdir($this->dir);
    }

    public function testBuildConfigAddsTheDiscriminators(): void
    {
        self::assertSame(
            ProviderConnectionFixtures::ollamaConfig('http://ollama:11434/api', ['timeout' => 60]),
            ProviderConnectionSeedCommand::buildConfig('ollama', ['url' => 'http://ollama:11434/api', 'timeout' => 60, 'type' => 'anthropic']),
        );
        $this->expectException(\InvalidArgumentException::class);
        ProviderConnectionSeedCommand::buildConfig('mcp', []);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function malformedFiles(): iterable
    {
        yield 'not json' => ['{', 'not valid JSON'];
        yield 'object instead of list' => ['{"type": "ollama"}', 'JSON list'];
        yield 'mcp type' => ['[{"type": "mcp", "name": "x", "config": {}}]', 'Entry 0: "type" must be one of: ollama, anthropic.'];
        yield 'empty name' => ['[{"type": "ollama", "name": " ", "config": {}}]', 'Entry 0: "name" must be a non-empty string.'];
        yield 'config list' => ['[{"type": "ollama", "name": "x", "config": [1]}]', 'Entry 0 ("x"): "config" must be an object.'];
        yield 'unknown key' => ['[{"type": "ollama", "name": "x", "config": {}, "active": false}]', 'unknown keys active'];
    }

    #[DataProvider('malformedFiles')]
    public function testMalformedFilesFailBeforeAnyLookup(string $json, string $message): void
    {
        $entityManager = $this->entityManager(flushes: 0);
        $entityManager->expects(self::never())->method('getRepository');

        $tester = $this->tester($entityManager, validations: 0);

        self::assertSame(Command::FAILURE, $tester->execute(['--file' => $this->file($json)]));
        self::assertStringContainsString($message, self::flat($tester->getDisplay()));
    }

    public function testMissingFileAndMissingInputFail(): void
    {
        $tester = $this->tester($this->entityManager(flushes: 0), validations: 0);

        self::assertSame(Command::FAILURE, $tester->execute(['--file' => $this->dir.'/missing.json']));
        self::assertStringContainsString('does not exist', self::flat($tester->getDisplay()));
        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('Nothing to seed', self::flat($tester->getDisplay()));
        self::assertSame(Command::FAILURE, $tester->execute(['--type' => 'ollama']));
        self::assertSame(Command::FAILURE, $tester->execute(['name' => 'x', '--type' => 'ollama', '--config' => '[1]']));
    }

    public function testCreatesTheSingleConnectionAndTheFileEntries(): void
    {
        $persisted = [];
        $entityManager = $this->entityManager(flushes: 3);
        $entityManager->method('persist')->willReturnCallback(function (object $entity) use (&$persisted): void {
            $persisted[] = $entity;
            $this->stored[] = $entity;
        });
        $file = $this->file(json_encode([
            ['type' => 'ollama', 'name' => 'remote', 'config' => ['url' => 'https://ollama.example.org/api']],
            ['type' => 'anthropic', 'name' => 'claude', 'config' => ['api_key' => self::KEY, 'max_tokens' => 4096]],
        ], \JSON_THROW_ON_ERROR));

        $tester = $this->tester($entityManager);

        self::assertSame(Command::SUCCESS, $tester->execute(['name' => 'ollama-local', '--type' => 'ollama', '--config' => '{"url": "http://ollama:11434/api"}', '--file' => $file]), $tester->getDisplay());
        self::assertSame(['ollama-local', 'remote', 'claude'], array_map(static fn (ApiConfiguration $c): string => $c->getName(), $persisted));
        self::assertSame(ProviderConnectionFixtures::ollamaConfig('http://ollama:11434/api'), $persisted[0]->getConfigJson());
        self::assertSame(ProviderConnectionFixtures::anthropicConfig(self::KEY, ['max_tokens' => 4096]), $persisted[2]->getConfigJson());
        self::assertTrue($persisted[2]->isActive());
        self::assertSame(3, substr_count($tester->getDisplay(), 'created'));
        self::assertStringNotContainsString(self::KEY, $tester->getDisplay());
    }

    public function testEncryptedKeyWithTheSameValueIsUnchanged(): void
    {
        // As stored since dmstr/api-configuration-bundle 0.5: the key is encrypted.
        $existing = ProviderConnectionFixtures::anthropic('claude', apiKey: self::KEY, options: ['max_tokens' => 4096]);
        $existing->setConfigJson(array_reverse(ConfigSecretsFixture::create()->encrypt($existing->getConfigJson()), true));
        $this->stored = [$existing];
        $entityManager = $this->entityManager(flushes: 0);
        $entityManager->expects(self::never())->method('persist');

        $tester = $this->tester($entityManager, validations: 0);

        self::assertSame(Command::SUCCESS, $tester->execute(['name' => 'claude', '--type' => 'anthropic', '--config' => json_encode(['api_key' => self::KEY, 'max_tokens' => 4096], \JSON_THROW_ON_ERROR)]), $tester->getDisplay());
        self::assertStringContainsString('unchanged', $tester->getDisplay());
    }

    public function testChangedKeyUpdatesInPlaceAndKeepsTheActiveFlag(): void
    {
        $existing = ProviderConnectionFixtures::anthropic('claude', active: false);
        $existing->setConfigJson(ConfigSecretsFixture::create()->encrypt($existing->getConfigJson()));
        $this->stored = [$existing];
        $entityManager = $this->entityManager(flushes: 1);
        $entityManager->expects(self::never())->method('persist');

        $tester = $this->tester($entityManager);

        self::assertSame(Command::SUCCESS, $tester->execute(['name' => 'claude', '--type' => 'anthropic', '--config' => json_encode(['api_key' => self::KEY], \JSON_THROW_ON_ERROR)]), $tester->getDisplay());
        self::assertSame(self::KEY, $existing->getConfigJson()['api_key']);
        self::assertFalse($existing->isActive());
        self::assertStringContainsString('updated', $tester->getDisplay());
        self::assertStringNotContainsString(self::KEY, $tester->getDisplay());
    }

    public function testExistingConnectionOfTheOtherTypeIsAnError(): void
    {
        $this->stored = [ProviderConnectionFixtures::anthropic('main')];

        $tester = $this->tester($this->entityManager(flushes: 0), validations: 0);

        self::assertSame(Command::FAILURE, $tester->execute(['name' => 'main', '--type' => 'ollama', '--config' => '{"url": "http://ollama:11434/api"}']));
        self::assertStringContainsString('exists with type "anthropic"', self::flat($tester->getDisplay()));
    }

    public function testViolationsAreReportedWithoutValuesAndOtherEntriesContinue(): void
    {
        $entityManager = $this->entityManager(flushes: 1);
        $entityManager->expects(self::once())->method('persist');
        $violations = new ConstraintViolationList([new ConstraintViolation('Invalid API configuration: api_key: This field is required', null, [], null, 'configJson', ['api_key' => self::KEY])]);
        $validator = $this->createStub(ValidatorInterface::class);
        $validator->method('validate')->willReturnCallback(static fn (ApiConfiguration $c): ConstraintViolationList => 'broken' === $c->getName() ? $violations : new ConstraintViolationList());
        $file = $this->file(json_encode([
            ['type' => 'anthropic', 'name' => 'broken', 'config' => ['api_key' => self::KEY, 'max_tokens' => 0]],
            ['type' => 'ollama', 'name' => 'fine', 'config' => ['url' => 'http://ollama:11434/api']],
        ], \JSON_THROW_ON_ERROR));

        $tester = new CommandTester(new ProviderConnectionSeedCommand($entityManager, $validator, ConfigSecretsFixture::create()));

        self::assertSame(Command::FAILURE, $tester->execute(['--file' => $file]));
        $display = self::flat($tester->getDisplay());
        self::assertStringContainsString('Provider connection "broken" is invalid: configJson: Invalid API configuration: api_key: This field is required', $display);
        self::assertStringContainsString('Provider connection "fine" created', $display);
        self::assertStringNotContainsString(self::KEY, $display);
    }

    private function entityManager(int $flushes): EntityManagerInterface&MockObject
    {
        $repository = $this->createStub(EntityRepository::class);
        $repository->method('findBy')->willReturnCallback(function (array $criteria): array {
            self::assertSame(['ollama', 'anthropic'], $criteria['type']);

            return array_values(array_filter($this->stored, static fn (ApiConfiguration $c): bool => $c->getName() === $criteria['name']));
        });

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);
        $entityManager->expects(self::exactly($flushes))->method('flush');

        return $entityManager;
    }

    private function tester(EntityManagerInterface $entityManager, ?int $validations = null): CommandTester
    {
        if (0 === $validations) {
            $validator = $this->createMock(ValidatorInterface::class);
            $validator->expects(self::never())->method('validate');
        } else {
            $validator = $this->createStub(ValidatorInterface::class);
            $validator->method('validate')->willReturn(new ConstraintViolationList());
        }

        return new CommandTester(new ProviderConnectionSeedCommand($entityManager, $validator, ConfigSecretsFixture::create()));
    }

    private function file(string $json): string
    {
        $path = $this->dir.'/seed-'.bin2hex(random_bytes(3)).'.json';
        file_put_contents($path, $json);

        return $path;
    }

    /** The output with line breaks and padding of SymfonyStyle blocks collapsed. */
    private static function flat(string $display): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $display));
    }
}

// - revised 2026-10-07
