<?php

// file generated with AI assistance: Claude Code - 2026-09-30 22:07:50 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\Mcp\Seed;

use Dmstr\ApiConfiguration\Entity\ApiConfiguration;
use Dmstr\NeuronAiBundle\Command\McpConnectionSeedCommand;
use Dmstr\NeuronAiBundle\Testing\ConfigSecretsFixture;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Kernel-free flow of `neuron:mcp:connection:seed` with a stubbed entity
 * manager and validator: create, no-op, update in place, validation failure,
 * duplicates. The database-backed variant is McpConnectionSeedFunctionalTest.
 */
final class McpConnectionSeedCommandTest extends TestCase
{
    private const string URL = 'https://mcp.example.org/mcp';

    private const string TOKEN_URL = 'http://sso.example.org/realms/example/protocol/openid-connect/token';

    private const array CLIENT_CREDENTIALS_INPUT = [
        '--auth' => 'client_credentials',
        '--token-url' => self::TOKEN_URL,
        '--client-id' => 'mcp-agent',
        '--client-secret' => 'mcp-agent-dev-secret',
        '--restricted' => true,
    ];

    private const array FORWARD_JWT_INPUT = [
        '--auth' => 'forward_jwt',
        '--scan-token-url' => self::TOKEN_URL,
        '--scan-client-id' => 'mcp-scan',
        '--scan-client-secret' => 'mcp-scan-dev-secret',
        '--scan-scope' => 'mcp',
    ];

    public function testCreatesAnActiveConnection(): void
    {
        $persisted = [];
        $entityManager = $this->entityManager([], flushes: 1);
        $entityManager->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted): void {
            $persisted[] = $entity;
        });

        $tester = $this->tester($entityManager);

        self::assertSame(Command::SUCCESS, $tester->execute(['name' => 'docs', 'url' => self::URL]), $tester->getDisplay());
        self::assertCount(1, $persisted);
        $connection = $persisted[0];
        self::assertInstanceOf(ApiConfiguration::class, $connection);
        self::assertSame('docs', $connection->getName());
        self::assertSame('mcp', $connection->getType());
        self::assertSame('rest', $connection->getEndpointType());
        self::assertTrue($connection->isActive());
        self::assertSame(McpConnectionSeedCommand::buildConfig(self::URL), $connection->getConfigJson());
        self::assertStringContainsString('created', $tester->getDisplay());
    }

    public function testEqualConfigIsANoOp(): void
    {
        $existing = $this->existing(array_reverse(McpConnectionSeedCommand::buildConfig(self::URL), true));
        $entityManager = $this->entityManager([$existing], flushes: 0);
        $entityManager->expects(self::never())->method('persist');

        $tester = $this->tester($entityManager, validations: 0);

        self::assertSame(Command::SUCCESS, $tester->execute(['name' => 'docs', 'url' => self::URL]), $tester->getDisplay());
        self::assertStringContainsString('unchanged', $tester->getDisplay());
    }

    public function testEncryptedTokenWithTheSameValueIsUnchanged(): void
    {
        // As stored since dmstr/api-configuration-bundle 0.5: the token is encrypted.
        $stored = ConfigSecretsFixture::create()->encrypt(McpConnectionSeedCommand::buildConfig(self::URL, 'bearer', 'secret'));
        self::assertStringStartsWith('enc:v1:', $stored['token']);
        $entityManager = $this->entityManager([$this->existing($stored)], flushes: 0);
        $entityManager->expects(self::never())->method('persist');

        $tester = $this->tester($entityManager, validations: 0);

        self::assertSame(Command::SUCCESS, $tester->execute(['name' => 'docs', 'url' => self::URL, '--auth' => 'bearer', '--token' => 'secret']), $tester->getDisplay());
        self::assertStringContainsString('unchanged', $tester->getDisplay());
    }

    public function testEncryptedTokenWithAnotherValueIsUpdated(): void
    {
        $existing = $this->existing(ConfigSecretsFixture::create()->encrypt(McpConnectionSeedCommand::buildConfig(self::URL, 'bearer', 'old-secret')));
        $entityManager = $this->entityManager([$existing], flushes: 1);

        $tester = $this->tester($entityManager);

        self::assertSame(Command::SUCCESS, $tester->execute(['name' => 'docs', 'url' => self::URL, '--auth' => 'bearer', '--token' => 'new-secret']), $tester->getDisplay());
        self::assertStringContainsString('updated', $tester->getDisplay());
        self::assertStringNotContainsString('secret', $tester->getDisplay());
    }

    public function testClientCredentialsOptionsBuildARestrictedConnection(): void
    {
        $persisted = [];
        $entityManager = $this->entityManager([], flushes: 1);
        $entityManager->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted): void {
            $persisted[] = $entity;
        });

        $tester = $this->tester($entityManager);

        self::assertSame(Command::SUCCESS, $tester->execute(['name' => 'docs', 'url' => self::URL] + self::CLIENT_CREDENTIALS_INPUT), $tester->getDisplay());
        self::assertCount(1, $persisted);
        self::assertInstanceOf(ApiConfiguration::class, $persisted[0]);
        self::assertSame([
            'mcp' => true,
            'type' => 'mcp',
            'endpoint_type' => 'rest',
            'url' => self::URL,
            'transport' => 'streamable-http',
            'auth_type' => 'client_credentials',
            'token_url' => self::TOKEN_URL,
            'client_id' => 'mcp-agent',
            'client_secret' => 'mcp-agent-dev-secret',
            'restricted' => true,
        ], $persisted[0]->getConfigJson());
        self::assertStringNotContainsString('mcp-agent-dev-secret', $tester->getDisplay());
    }

    public function testForwardJwtOptionsBuildAnUnrestrictedConnection(): void
    {
        $persisted = [];
        $entityManager = $this->entityManager([], flushes: 1);
        $entityManager->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted): void {
            $persisted[] = $entity;
        });

        $tester = $this->tester($entityManager);

        self::assertSame(Command::SUCCESS, $tester->execute(['name' => 'docs', 'url' => self::URL] + self::FORWARD_JWT_INPUT), $tester->getDisplay());
        self::assertCount(1, $persisted);
        self::assertInstanceOf(ApiConfiguration::class, $persisted[0]);
        self::assertSame([
            'mcp' => true,
            'type' => 'mcp',
            'endpoint_type' => 'rest',
            'url' => self::URL,
            'transport' => 'streamable-http',
            'auth_type' => 'forward_jwt',
            'scan_token_url' => self::TOKEN_URL,
            'scan_client_id' => 'mcp-scan',
            'scan_client_secret' => 'mcp-scan-dev-secret',
            'scan_scope' => 'mcp',
        ], $persisted[0]->getConfigJson());
        self::assertStringNotContainsString('mcp-scan-dev-secret', $tester->getDisplay());
    }

    public function testEncryptedScanClientSecretWithTheSameValueIsUnchanged(): void
    {
        $stored = ConfigSecretsFixture::create()->encrypt(McpConnectionSeedCommand::buildConfig(
            self::URL,
            'forward_jwt',
            scanTokenUrl: self::TOKEN_URL,
            scanClientId: 'mcp-scan',
            scanClientSecret: 'mcp-scan-dev-secret',
            scanScope: 'mcp',
        ));
        self::assertStringStartsWith('enc:v1:', $stored['scan_client_secret']);
        $entityManager = $this->entityManager([$this->existing($stored)], flushes: 0);
        $entityManager->expects(self::never())->method('persist');

        $tester = $this->tester($entityManager, validations: 0);

        self::assertSame(Command::SUCCESS, $tester->execute(['name' => 'docs', 'url' => self::URL] + self::FORWARD_JWT_INPUT), $tester->getDisplay());
        self::assertStringContainsString('unchanged', $tester->getDisplay());
    }

    public function testEncryptedClientSecretWithTheSameValueIsUnchanged(): void
    {
        $stored = ConfigSecretsFixture::create()->encrypt(McpConnectionSeedCommand::buildConfig(
            self::URL,
            'client_credentials',
            tokenUrl: self::TOKEN_URL,
            clientId: 'mcp-agent',
            clientSecret: 'mcp-agent-dev-secret',
            restricted: true,
        ));
        self::assertStringStartsWith('enc:v1:', $stored['client_secret']);
        $entityManager = $this->entityManager([$this->existing($stored)], flushes: 0);
        $entityManager->expects(self::never())->method('persist');

        $tester = $this->tester($entityManager, validations: 0);

        self::assertSame(Command::SUCCESS, $tester->execute(['name' => 'docs', 'url' => self::URL] + self::CLIENT_CREDENTIALS_INPUT), $tester->getDisplay());
        self::assertStringContainsString('unchanged', $tester->getDisplay());
    }

    public function testDriftUpdatesInPlaceAndKeepsTheActiveFlag(): void
    {
        $existing = $this->existing(McpConnectionSeedCommand::buildConfig('https://old.example.org/mcp'), active: false);
        $entityManager = $this->entityManager([$existing], flushes: 1);
        $entityManager->expects(self::never())->method('persist');

        $tester = $this->tester($entityManager);

        self::assertSame(Command::SUCCESS, $tester->execute(['name' => 'docs', 'url' => self::URL, '--auth' => 'bearer', '--token' => 'secret']), $tester->getDisplay());
        self::assertSame(McpConnectionSeedCommand::buildConfig(self::URL, 'bearer', 'secret'), $existing->getConfigJson());
        self::assertFalse($existing->isActive());
        self::assertStringContainsString('updated', $tester->getDisplay());
        self::assertStringNotContainsString('secret', $tester->getDisplay());
    }

    public function testViolationsAreReportedAndNothingIsWritten(): void
    {
        $entityManager = $this->entityManager([], flushes: 0);
        $entityManager->expects(self::never())->method('persist');
        $violations = new ConstraintViolationList([
            new ConstraintViolation('The property token is required', null, [], null, 'configJson', null),
        ]);

        $tester = $this->tester($entityManager, $violations);

        self::assertSame(Command::FAILURE, $tester->execute(['name' => 'docs', 'url' => self::URL, '--auth' => 'bearer']));
        self::assertStringContainsString('configJson: The property token is required', $tester->getDisplay());
    }

    public function testRejectedUpdateIsDroppedFromTheManagedEntity(): void
    {
        $existing = $this->existing(McpConnectionSeedCommand::buildConfig('https://old.example.org/mcp'));
        $entityManager = $this->entityManager([$existing], flushes: 0);
        $entityManager->expects(self::once())->method('refresh')->with($existing);
        $violations = new ConstraintViolationList([
            new ConstraintViolation('Invalid URL', null, [], null, 'configJson', null),
        ]);

        $tester = $this->tester($entityManager, $violations);

        self::assertSame(Command::FAILURE, $tester->execute(['name' => 'docs', 'url' => 'ftp://mcp.example.org/mcp']));
    }

    public function testDuplicateNamesAreAnError(): void
    {
        $config = McpConnectionSeedCommand::buildConfig(self::URL);
        $entityManager = $this->entityManager([$this->existing($config), $this->existing($config)], flushes: 0);

        $tester = $this->tester($entityManager, validations: 0);

        self::assertSame(Command::FAILURE, $tester->execute(['name' => 'docs', 'url' => self::URL]));
        self::assertStringContainsString('resolve the duplicates', $tester->getDisplay());
    }

    public function testMisplacedOptionsFailBeforeAnyLookup(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('getRepository');

        $tester = $this->tester($entityManager, validations: 0);

        self::assertSame(Command::FAILURE, $tester->execute(['name' => 'docs', 'url' => self::URL, '--token' => 'secret']));
        self::assertSame(Command::FAILURE, $tester->execute(['name' => 'docs', 'url' => self::URL, '--timeout' => 'ten']));
        self::assertSame(Command::FAILURE, $tester->execute(['name' => 'docs', 'url' => self::URL, '--client-secret' => 'secret']));
        self::assertSame(Command::FAILURE, $tester->execute(['name' => 'docs', 'url' => self::URL, '--scan-client-secret' => 'secret']));
    }

    /**
     * @param list<ApiConfiguration> $found
     */
    private function entityManager(array $found, int $flushes): EntityManagerInterface&MockObject
    {
        $repository = $this->createStub(EntityRepository::class);
        $repository->method('findBy')->willReturnCallback(static function (array $criteria) use ($found): array {
            self::assertSame(['type' => 'mcp', 'name' => 'docs'], $criteria);

            return $found;
        });

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturnCallback(static function (string $class) use ($repository): EntityRepository {
            self::assertSame(ApiConfiguration::class, $class);

            return $repository;
        });
        $entityManager->expects(self::exactly($flushes))->method('flush');

        return $entityManager;
    }

    private function tester(EntityManagerInterface $entityManager, ?ConstraintViolationList $violations = null, ?int $validations = null): CommandTester
    {
        if (0 === $validations) {
            $validator = $this->createMock(ValidatorInterface::class);
            $validator->expects(self::never())->method('validate');
        } else {
            $validator = $this->createStub(ValidatorInterface::class);
            $validator->method('validate')->willReturn($violations ?? new ConstraintViolationList());
        }

        return new CommandTester(new McpConnectionSeedCommand($entityManager, $validator, ConfigSecretsFixture::create()));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function existing(array $config, bool $active = true): ApiConfiguration
    {
        return (new ApiConfiguration())
            ->setName('docs')
            ->setConfigJson($config)
            ->setActive($active);
    }
}

// - revised 2026-10-09 06:42:28 UTC
// - revised 2026-10-09 13:43:23 UTC
