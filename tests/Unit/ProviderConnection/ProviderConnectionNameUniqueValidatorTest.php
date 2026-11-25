<?php

// file generated with AI assistance: Claude Code - 2026-10-05 18:50:29 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\ProviderConnection;

use Dmstr\ApiConfiguration\Entity\ApiConfiguration;
use Dmstr\NeuronAiBundle\Testing\ProviderConnectionFixtures;
use Dmstr\NeuronAiBundle\Validator\ProviderConnectionNameUnique;
use Dmstr\NeuronAiBundle\Validator\ProviderConnectionNameUniqueValidator;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Symfony\Component\Validator\ConstraintValidatorInterface;
use Symfony\Component\Validator\Test\ConstraintValidatorTestCase;

/**
 * Kernel-free checks of the name uniqueness across `ollama` and
 * `anthropic` connections, with a stubbed repository.
 *
 * @extends ConstraintValidatorTestCase<ProviderConnectionNameUniqueValidator>
 */
final class ProviderConnectionNameUniqueValidatorTest extends ConstraintValidatorTestCase
{
    /** @var list<ApiConfiguration> */
    private array $stored = [];

    /** @var list<array<string, mixed>> */
    private array $queries = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setPropertyPath('');
    }

    protected function createValidator(): ConstraintValidatorInterface
    {
        $repository = $this->createStub(EntityRepository::class);
        $repository->method('findBy')->willReturnCallback(function (array $criteria): array {
            $this->queries[] = $criteria;

            return array_values(array_filter(
                $this->stored,
                static fn (ApiConfiguration $c): bool => \in_array($c->getType(), (array) $criteria['type'], true) && $c->getName() === $criteria['name'],
            ));
        });

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);

        return new ProviderConnectionNameUniqueValidator($entityManager);
    }

    public function testFirstConnectionIsValidAndQueriesBothTypes(): void
    {
        $this->validator->validate(ProviderConnectionFixtures::ollama('local'), new ProviderConnectionNameUnique());

        $this->assertNoViolation();
        self::assertSame([['type' => ['ollama', 'anthropic'], 'name' => 'local']], $this->queries);
    }

    public function testNameOfTheOtherProviderTypeIsReportedOnName(): void
    {
        $this->stored = [ProviderConnectionFixtures::anthropic('main')];

        $this->validator->validate(ProviderConnectionFixtures::ollama('main'), new ProviderConnectionNameUnique());

        $this->buildViolation('A provider connection named {{ name }} already exists.')
            ->setParameter('{{ name }}', '"main"')
            ->setCode(ProviderConnectionNameUnique::NOT_UNIQUE_ERROR)
            ->setInvalidValue('main')
            ->atPath('name')
            ->assertRaised();
    }

    public function testUpdatingTheConnectionItselfIsValid(): void
    {
        $existing = ProviderConnectionFixtures::ollama('local');
        $this->stored = [$existing];

        $this->validator->validate($existing, new ProviderConnectionNameUnique());

        $this->assertNoViolation();
    }

    public function testMcpConnectionsAreNeitherCheckedNorCounted(): void
    {
        $mcp = new ApiConfiguration()->setName('docs')->setConfigJson(['mcp' => true, 'type' => 'mcp', 'endpoint_type' => 'rest', 'url' => 'https://mcp.example.org/mcp']);
        $this->stored = [$mcp];

        $this->validator->validate($mcp, new ProviderConnectionNameUnique());
        $this->validator->validate(ProviderConnectionFixtures::ollama('docs'), new ProviderConnectionNameUnique());

        $this->assertNoViolation();
        self::assertCount(1, $this->queries, 'only the ollama connection is checked');
    }

    public function testMissingNameIsLeftToNotBlank(): void
    {
        $this->validator->validate(new ApiConfiguration()->setConfigJson(ProviderConnectionFixtures::ollamaConfig()), new ProviderConnectionNameUnique());

        $this->assertNoViolation();
        self::assertSame([], $this->queries);
    }
}
