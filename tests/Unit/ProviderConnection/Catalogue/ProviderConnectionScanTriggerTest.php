<?php

// file generated with AI assistance: Claude Code - 2026-10-06 18:00:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\ProviderConnection\Catalogue;

use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\State\ProcessorInterface;
use Dmstr\ApiConfiguration\Entity\ApiConfiguration;
use Dmstr\NeuronAiBundle\Provider\ProviderModelScanQueue;
use Dmstr\NeuronAiBundle\State\ProviderConnectionScanTrigger;
use Dmstr\NeuronAiBundle\Testing\ProviderConnectionFixtures;
use Dmstr\NeuronAiBundle\Tests\Support\InMemoryProviderCatalogueTrait;
use Dmstr\OpenApiJsonSchema\Service\OperationInputSchemaResolver;
use Dmstr\SymfonyJobQueue\Service\JobQueueService;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Kernel-free checks of the scan trigger on provider connection writes
 * through the API: create active, activate and a changed endpoint queue
 * one scan of that connection; renames, inactive connections, other
 * connection types and other resources queue nothing.
 */
final class ProviderConnectionScanTriggerTest extends TestCase
{
    use InMemoryProviderCatalogueTrait;

    private bool $failQueue = false;

    /** @var list<string> */
    private array $logged = [];

    public function testCreatingAnActiveProviderConnectionQueuesAScan(): void
    {
        $remote = ProviderConnectionFixtures::ollama('remote');

        $this->process($remote, null);

        self::assertCount(1, $this->jobs);
        self::assertSame(['apiConfiguration' => (string) $remote->getId()], $this->jobs[0]->getInputData());
        self::assertSame('provider_model_scan', $this->jobs[0]->getType());
    }

    public function testCreatingAnInactiveConnectionQueuesNothing(): void
    {
        $this->process(ProviderConnectionFixtures::ollama('remote', active: false), null);

        self::assertSame([], $this->jobs);
    }

    public function testActivatingQueuesAScan(): void
    {
        $before = ProviderConnectionFixtures::anthropic('claude', active: false);
        $after = (clone $before)->setActive(true);

        $this->process($after, $before);

        self::assertCount(1, $this->jobs);
    }

    public function testChangingTheUrlQueuesAScan(): void
    {
        $before = ProviderConnectionFixtures::ollama('remote');
        $after = (clone $before)->setConfigJson(ProviderConnectionFixtures::ollamaConfig('https://ollama.example.org/api'));

        $this->process($after, $before);

        self::assertCount(1, $this->jobs);
    }

    public function testChangingTheAnthropicBaseUrlQueuesAScan(): void
    {
        $before = ProviderConnectionFixtures::anthropic('claude');
        $after = (clone $before)->setConfigJson($before->getConfigJson() + ['base_url' => 'https://gateway.example.org/v1/']);

        $this->process($after, $before);

        self::assertCount(1, $this->jobs);
    }

    public function testRenameAndOtherConfigChangesQueueNothing(): void
    {
        $before = ProviderConnectionFixtures::ollama('remote');
        $after = (clone $before)
            ->setName('renamed')
            ->setConfigJson(ProviderConnectionFixtures::ollamaConfig(ProviderConnectionFixtures::OLLAMA_URL.'/', ['timeout' => 60]));

        $this->process($after, $before);

        self::assertSame([], $this->jobs);
    }

    public function testDeactivatingQueuesNothing(): void
    {
        $before = ProviderConnectionFixtures::ollama('remote');
        $after = (clone $before)->setActive(false);

        $this->process($after, $before);

        self::assertSame([], $this->jobs);
    }

    public function testMcpConnectionsAndOtherResourcesPassThrough(): void
    {
        $mcp = (new ApiConfiguration())->setName('docs')->setConfigJson(['mcp' => true, 'type' => 'mcp', 'endpoint_type' => 'rest', 'url' => 'https://docs.example.org/mcp']);

        $this->process($mcp, null);
        $result = $this->trigger()->process(new \stdClass(), new Post());

        self::assertInstanceOf(\stdClass::class, $result);
        self::assertSame([], $this->jobs);
    }

    public function testAPendingScanOfTheConnectionIsReused(): void
    {
        $remote = ProviderConnectionFixtures::ollama('remote');

        $this->process($remote, null);
        $this->process((clone $remote)->setConfigJson(ProviderConnectionFixtures::ollamaConfig('https://ollama.example.org/api')), $remote);

        self::assertCount(1, $this->jobs);
    }

    public function testARunningScanOfTheConnectionIsNotReused(): void
    {
        $remote = ProviderConnectionFixtures::ollama('remote');

        $this->process($remote, null);
        $this->jobs[0]->setStatus('running');
        $this->process((clone $remote)->setConfigJson(ProviderConnectionFixtures::ollamaConfig('https://ollama.example.org/api')), $remote);

        self::assertCount(2, $this->jobs, 'the running scan may have read the old URL');
        self::assertSame('pending', $this->jobs[1]->getStatus());
    }

    public function testAFailingQueueIsLoggedAndTheWriteStillReturns(): void
    {
        $this->failQueue = true;
        $remote = ProviderConnectionFixtures::ollama('remote');

        $result = $this->process($remote, null);

        self::assertSame($remote, $result);
        self::assertCount(1, $this->logged);
        self::assertStringContainsString('remote', $this->logged[0]);
    }

    private function process(ApiConfiguration $data, ?ApiConfiguration $previous): mixed
    {
        $operation = null === $previous ? new Post() : new Patch();

        return $this->trigger()->process($data, $operation, [], null === $previous ? [] : ['previous_data' => $previous]);
    }

    private function trigger(): ProviderConnectionScanTrigger
    {
        $entityManager = $this->entityManager();

        $inner = $this->createStub(ProcessorInterface::class);
        $inner->method('process')->willReturnArgument(0);

        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(function (object $message): Envelope {
            if ($this->failQueue) {
                throw new \RuntimeException('transport down');
            }

            return new Envelope($message);
        });

        $logger = new class(function (string $line): void { $this->logged[] = $line; }) extends AbstractLogger {
            public function __construct(private readonly \Closure $sink)
            {
            }

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                ($this->sink)(strtr((string) $message, ['{connection}' => (string) ($context['connection'] ?? ''), '{error}' => (string) ($context['error'] ?? '')]));
            }
        };

        return new ProviderConnectionScanTrigger(
            $inner,
            new ProviderModelScanQueue(new JobQueueService($entityManager, $bus, new OperationInputSchemaResolver([], [])), $entityManager),
            $logger,
        );
    }
}

// - revised 2026-10-07
