<?php

// file generated with AI assistance: Claude Code - 2026-09-30 21:22:22 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\Mcp;

use Dmstr\ApiConfiguration\Entity\ApiConfiguration;
use Dmstr\NeuronAiBundle\Validator\McpConnectionNameUnique;
use Dmstr\NeuronAiBundle\Validator\McpConnectionNameUniqueValidator;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Symfony\Component\Validator\ConstraintValidatorInterface;
use Symfony\Component\Validator\Test\ConstraintValidatorTestCase;

/**
 * Kernel-free checks of the name uniqueness of mcp connections, with a
 * stubbed repository instead of the database.
 *
 * @extends ConstraintValidatorTestCase<McpConnectionNameUniqueValidator>
 */
final class McpConnectionNameUniqueValidatorTest extends ConstraintValidatorTestCase
{
    /** @var list<ApiConfiguration> */
    private array $stored = [];

    /** @var list<array<string, mixed>> */
    private array $queries = [];

    protected function setUp(): void
    {
        parent::setUp();
        // Class constraint on the root object: paths start at the entity.
        $this->setPropertyPath('');
    }

    protected function createValidator(): ConstraintValidatorInterface
    {
        $repository = $this->createStub(EntityRepository::class);
        $repository->method('findBy')->willReturnCallback(function (array $criteria): array {
            $this->queries[] = $criteria;

            return array_values(array_filter(
                $this->stored,
                static fn (ApiConfiguration $c): bool => $c->getType() === $criteria['type'] && $c->getName() === $criteria['name'],
            ));
        });

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);

        return new McpConnectionNameUniqueValidator($entityManager);
    }

    public function testFirstMcpConnectionIsValid(): void
    {
        $this->validator->validate(self::connection('mcp', 'docs'), new McpConnectionNameUnique());

        $this->assertNoViolation();
        self::assertSame([['type' => 'mcp', 'name' => 'docs']], $this->queries);
    }

    public function testDuplicateMcpNameIsReportedOnName(): void
    {
        $this->stored = [self::connection('mcp', 'docs')];

        $this->validator->validate(self::connection('mcp', 'docs'), new McpConnectionNameUnique());

        $this->buildViolation('An MCP connection named {{ name }} already exists.')
            ->setParameter('{{ name }}', '"docs"')
            ->setCode(McpConnectionNameUnique::NOT_UNIQUE_ERROR)
            ->setInvalidValue('docs')
            ->atPath('name')
            ->assertRaised();
    }

    public function testUpdatingTheConnectionItselfIsValid(): void
    {
        $existing = self::connection('mcp', 'docs');
        $this->stored = [$existing];

        $this->validator->validate($existing, new McpConnectionNameUnique());

        $this->assertNoViolation();
    }

    public function testNonMcpDuplicateIsUnaffected(): void
    {
        $this->stored = [self::connection('github', 'docs'), self::connection('mcp', 'docs')];

        $this->validator->validate(self::connection('github', 'docs'), new McpConnectionNameUnique());

        $this->assertNoViolation();
        self::assertSame([], $this->queries);
    }

    public function testMcpNameMayBeUsedByAnotherType(): void
    {
        $this->stored = [self::connection('github', 'docs')];

        $this->validator->validate(self::connection('mcp', 'docs'), new McpConnectionNameUnique());

        $this->assertNoViolation();
    }

    public function testMissingNameIsLeftToNotBlank(): void
    {
        $connection = (new ApiConfiguration())->setConfigJson(['type' => 'mcp', 'endpoint_type' => 'rest']);

        $this->validator->validate($connection, new McpConnectionNameUnique());

        $this->assertNoViolation();
        self::assertSame([], $this->queries);
    }

    public function testNullIsIgnored(): void
    {
        $this->validator->validate(null, new McpConnectionNameUnique());

        $this->assertNoViolation();
    }

    private static function connection(string $type, string $name): ApiConfiguration
    {
        return (new ApiConfiguration())
            ->setName($name)
            ->setConfigJson(['type' => $type, 'endpoint_type' => 'rest', 'url' => 'https://example.org']);
    }
}
