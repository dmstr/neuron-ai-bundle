<?php

// file generated with AI assistance: Claude Code - 2026-10-02 22:05:06 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\Agent;

use ApiPlatform\Metadata\Post;
use ApiPlatform\Validator\Exception\ValidationException;
use Dmstr\ApiPlatformUtils\Service\UuidResolver;
use Dmstr\NeuronAiBundle\Agent\AgentRollback;
use Dmstr\NeuronAiBundle\Agent\AgentVersionKind;
use Dmstr\NeuronAiBundle\Entity\Agent;
use Dmstr\NeuronAiBundle\Entity\AgentVersion;
use Dmstr\NeuronAiBundle\State\AgentRollbackProcessor;
use Dmstr\NeuronAiBundle\State\InputBodyValidator;
use Dmstr\OpenApiJsonSchema\Service\OperationInputSchemaResolver;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Kernel-free checks of `agent_rollback`: the body is validated against
 * `Entity/Agent/rollback.input.json` (resolved like the bundle does), the
 * version reference (IRI, full or partial UUID) is resolved through the
 * UuidResolver, anything unknown, ambiguous or malformed is 404 without a
 * lookup of a LIKE pattern, and a version of another agent or a preview
 * is the 422 of AgentRollback.
 */
final class AgentRollbackProcessorTest extends TestCase
{
    private const string ENTITY_ROOT = __DIR__.'/../../../src/Entity';

    private Agent $agent;
    private AgentVersion $first;
    private AgentVersion $second;

    /** @var list<string> ids passed to the UuidResolver */
    private array $lookups = [];

    private int $flushes = 0;

    protected function setUp(): void
    {
        $this->agent = new Agent()->setName('Support')->setSlug('support');
        $this->first = new AgentVersion($this->agent, 1, [], 'test-admin');
        $this->second = new AgentVersion($this->agent, 2, [], 'test-admin');
        $this->agent->setCurrentVersion($this->second);
    }

    /**
     * @return iterable<string, array{callable(AgentVersion): string}>
     */
    public static function references(): iterable
    {
        yield 'IRI' => [static fn (AgentVersion $version): string => '/api/agent_versions/'.$version->getId()->toRfc4122()];
        yield 'full UUID' => [static fn (AgentVersion $version): string => $version->getId()->toRfc4122()];
        yield 'partial UUID' => [static fn (AgentVersion $version): string => substr($version->getId()->toRfc4122(), 0, 8)];
        yield 'surrounding blanks' => [static fn (AgentVersion $version): string => ' '.$version->getId()->toRfc4122().' '];
    }

    /**
     * @param callable(AgentVersion): string $reference
     */
    #[DataProvider('references')]
    public function testRollbackResolvesTheReference(callable $reference): void
    {
        $result = $this->process(['version' => $reference($this->first)]);

        self::assertSame($this->agent, $result);
        self::assertSame($this->first, $this->agent->getCurrentVersion());
        self::assertSame(1, $this->agent->getCurrentVersionNumber());
        self::assertSame(1, $this->flushes);
        self::assertCount(1, $this->lookups);
        self::assertStringStartsWith(substr($this->first->getId()->toRfc4122(), 0, 8), $this->lookups[0]);
    }

    public function testUnknownVersionIsNotFound(): void
    {
        try {
            $this->process(['version' => '0199a0b1-0000-7000-8000-000000000001']);
            self::fail('Expected a 404.');
        } catch (NotFoundHttpException) {
        }

        self::assertSame($this->second, $this->agent->getCurrentVersion());
        self::assertSame(0, $this->flushes);
    }

    public function testAmbiguousPartialUuidIsNotFound(): void
    {
        $this->expectException(NotFoundHttpException::class);

        $this->process(['version' => 'ffffffff']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedReferences(): iterable
    {
        yield 'LIKE wildcard' => ['%'];
        yield 'too short' => ['0199a0b'];
        yield 'not hex' => ['version-one'];
        yield 'IRI of another resource' => ['/api/agents/0199a0b1-0000-7000-8000-000000000001'];
        yield 'IRI without id' => ['/api/agent_versions/'];
    }

    #[DataProvider('malformedReferences')]
    public function testMalformedReferenceIsNotFoundWithoutLookup(string $reference): void
    {
        try {
            $this->process(['version' => $reference]);
            self::fail('Expected a 404.');
        } catch (NotFoundHttpException) {
        }

        self::assertSame([], $this->lookups);
    }

    public function testVersionOfAnotherAgentIsRejectedAtVersion(): void
    {
        $other = new Agent()->setName('Other')->setSlug('other');
        $foreign = new AgentVersion($other, 1, [], 'test-admin');

        try {
            $this->process(['version' => $foreign->getId()->toRfc4122()], $foreign);
            self::fail('Expected a validation exception.');
        } catch (ValidationException $e) {
            self::assertSame('version', $e->getConstraintViolationList()->get(0)->getPropertyPath());
        }

        self::assertSame($this->second, $this->agent->getCurrentVersion());
        self::assertSame(0, $this->flushes);
    }

    public function testPreviewIsRejectedAtVersion(): void
    {
        $preview = new AgentVersion($this->agent, 0, [], 'test-admin', null, AgentVersionKind::PREVIEW, hash('sha256', 'candidate'));

        try {
            $this->process(['version' => $preview->getId()->toRfc4122()], $preview);
            self::fail('Expected a validation exception.');
        } catch (ValidationException $e) {
            $violation = $e->getConstraintViolationList()->get(0);
            self::assertSame('version', $violation->getPropertyPath());
            self::assertSame(\sprintf('Version %s is a preview and cannot be rolled back to.', $preview->getId()->toRfc4122()), $violation->getMessage());
        }

        self::assertSame($this->second, $this->agent->getCurrentVersion());
        self::assertSame(0, $this->flushes);
    }

    public function testMissingVersionIsASchemaViolation(): void
    {
        $this->expectException(UnprocessableEntityHttpException::class);

        $this->process([]);
    }

    public function testUnknownBodyKeyIsASchemaViolation(): void
    {
        $this->expectException(UnprocessableEntityHttpException::class);

        $this->process(['version' => $this->first->getId()->toRfc4122(), 'force' => true]);
    }

    public function testMalformedJsonIsABadRequest(): void
    {
        $this->expectException(BadRequestHttpException::class);

        $this->processor()->process($this->agent, new Post(), [], ['request' => Request::create('/', 'POST', content: '{"version":')]);
    }

    public function testOtherDataIsNotFound(): void
    {
        $this->expectException(NotFoundHttpException::class);

        $this->processor()->process(new \stdClass(), new Post(), [], ['request' => Request::create('/', 'POST', content: '{}')]);
    }

    /**
     * @param array<string, mixed> $body
     * @param AgentVersion|null    $extra a further version the resolver knows
     */
    private function process(array $body, ?AgentVersion $extra = null): Agent
    {
        $request = Request::create('/', 'POST', content: json_encode([] === $body ? new \stdClass() : $body, \JSON_THROW_ON_ERROR));

        return $this->processor($extra)->process($this->agent, new Post(), [], ['request' => $request]);
    }

    private function processor(?AgentVersion $extra = null): AgentRollbackProcessor
    {
        $versions = array_filter([$this->first, $this->second, $extra]);

        $uuidResolver = $this->createStub(UuidResolver::class);
        $uuidResolver->method('findByPartialUuid')->willReturnCallback(function (string $class, string $id) use ($versions): ?object {
            self::assertSame(AgentVersion::class, $class);
            $this->lookups[] = $id;
            if ('ffffffff' === $id) {
                throw new \RuntimeException('Ambiguous partial UUID');
            }

            return array_find($versions, static fn (AgentVersion $version): bool => str_starts_with($version->getId()->toRfc4122(), $id)) ?? null;
        });

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('flush')->willReturnCallback(function (): void {
            ++$this->flushes;
        });

        return new AgentRollbackProcessor(
            new AgentRollback($entityManager),
            new InputBodyValidator(new OperationInputSchemaResolver([], [[self::ENTITY_ROOT, 'Dmstr\\NeuronAiBundle\\Entity']])),
            $uuidResolver,
        );
    }
}

// - revised 2026-10-13
