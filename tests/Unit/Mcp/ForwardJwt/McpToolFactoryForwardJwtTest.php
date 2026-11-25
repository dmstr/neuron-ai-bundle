<?php

// file generated with AI assistance: Claude Code - 2026-10-09 13:43:23 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\Mcp\ForwardJwt;

use Dmstr\NeuronAiBundle\Tests\Support\InMemoryCatalogueTrait;
use Dmstr\ApiConfiguration\Entity\ApiConfiguration;
use Dmstr\NeuronAiBundle\Mcp\McpHealthClient;
use Dmstr\NeuronAiBundle\Mcp\McpToolFactory;
use Dmstr\NeuronAiBundle\Mcp\McpToolScanner;
use Dmstr\NeuronAiBundle\Mcp\McpTransportContext;
use Dmstr\NeuronAiBundle\Testing\ConfigSecretsFixture;
use Dmstr\NeuronAiBundle\Testing\FakeCurrentJwtProvider;
use Dmstr\NeuronAiBundle\Testing\FakeMcpTransportFactory;
use NeuronAI\Tools\ToolInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Kernel-free checks of `forward_jwt` connections in the tool factory: the
 * JWT of the person chatting is captured at build time and reaches the
 * transport as McpTransportContext::turn(); without a JWT only the rows
 * annotated `readOnlyHint: true` are built (fail-closed), also in strict
 * mode, with one warning per connection. Also checks the contexts of
 * catalogue scan and health.
 */
final class McpToolFactoryForwardJwtTest extends TestCase
{
    use InMemoryCatalogueTrait;

    private const string FLOWABLE_URL = 'http://mcp.example.org/mcp/flowable';
    private const string DOCS_URL = 'https://docs.example.org/mcp';
    private const string JWT = 'eyJhbGciOiJSUzI1NiJ9.eyJzdWIiOiJwZXJzb24ifQ.c2lnbmF0dXJl';

    private FakeMcpTransportFactory $fake;

    private FakeCurrentJwtProvider $jwt;

    protected function setUp(): void
    {
        $this->fake = new FakeMcpTransportFactory();
        $this->jwt = new FakeCurrentJwtProvider();
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function modes(): iterable
    {
        yield 'forTools' => [false];
        yield 'forToolsStrict' => [true];
    }

    #[DataProvider('modes')]
    public function testWithoutJwtOnlyReadOnlyToolsAreBuilt(bool $strict): void
    {
        $flowable = $this->flowable();
        $rows = [
            $this->addTool($flowable, 'list_tasks', null, ['type' => 'object'])->setAnnotations(['readOnlyHint' => true]),
            $this->addTool($flowable, 'complete_task', null, ['type' => 'object'])->setAnnotations(['readOnlyHint' => false]),
            $this->addTool($flowable, 'start_process', null, ['type' => 'object']),
            $this->addTool($flowable, 'truthy_hint', null, ['type' => 'object'])->setAnnotations(['readOnlyHint' => 'true']),
            $this->addTool($flowable, 'get_process', null, ['type' => 'object'])->setAnnotations(['readOnlyHint' => true, 'destructiveHint' => false]),
        ];

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with(self::stringContains('readOnlyHint'), self::callback(static fn (array $context): bool => 'flowable' === $context['connection']
                && 'complete_task, start_process, truthy_hint' === $context['tools']));

        $factory = $this->factory($logger);
        $tools = $strict ? $factory->forToolsStrict($rows) : $factory->forTools($rows);

        self::assertSame(['list_tasks', 'get_process'], self::names($tools));
        self::assertSame(1, $this->fake->getTransportCount());
        $context = $this->fake->getLastCreatedContext();
        self::assertNotNull($context);
        self::assertSame(McpTransportContext::PURPOSE_TURN, $context->purpose);
        self::assertNull($context->jwt);
        self::assertSame([], $this->fake->getMethods(), 'building performs no I/O');
    }

    public function testWithoutJwtAndWithoutReadOnlyToolsNoTransportIsCreated(): void
    {
        $flowable = $this->flowable();
        $this->addTool($flowable, 'complete_task', null, ['type' => 'object']);

        self::assertSame([], $this->factory()->forConnections([$flowable]));
        self::assertSame(0, $this->fake->getTransportCount());
    }

    public function testStrictModeKeepsThePositionOfAnUnbuildableRow(): void
    {
        $flowable = $this->flowable();
        $dropped = $this->addTool($flowable, 'complete_task', null, ['type' => 'object']);
        $broken = $this->addTool($flowable, 'broken', null, ['type' => 'object', 'properties' => ['nothing' => ['type' => 'null']]])
            ->setAnnotations(['readOnlyHint' => true]);

        try {
            $this->factory()->forToolsStrict([$dropped, $broken]);
            self::fail('UnexpectedValueException expected for the unbuildable read-only row');
        } catch (\UnexpectedValueException $e) {
            self::assertSame(1, $e->getCode());
        }
    }

    #[DataProvider('modes')]
    public function testWithJwtAllToolsAreBuiltAndTheJwtReachesTheTransport(bool $strict): void
    {
        $this->jwt->setJwt(self::JWT);
        $flowable = $this->flowable();
        $rows = [
            $this->addTool($flowable, 'list_tasks', null, ['type' => 'object'])->setAnnotations(['readOnlyHint' => true]),
            $this->addTool($flowable, 'complete_task', null, ['type' => 'object']),
        ];

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');

        $factory = $this->factory($logger);
        $tools = $strict ? $factory->forToolsStrict($rows) : $factory->forTools($rows);

        self::assertSame(['list_tasks', 'complete_task'], self::names($tools));
        $context = $this->fake->getLastCreatedContext();
        self::assertNotNull($context);
        self::assertSame(McpTransportContext::PURPOSE_TURN, $context->purpose);
        self::assertSame(self::JWT, $context->jwt);
        self::assertTrue($context->forwardsJwt());
    }

    public function testJwtIsCapturedAtBuildTime(): void
    {
        $this->jwt->setJwt(self::JWT);
        $flowable = $this->flowable();
        $this->addTool($flowable, 'complete_task', null, ['type' => 'object']);

        $tools = $this->factory()->forConnections([$flowable]);
        // The request ends (stream callback, token storage cleared); the
        // tools keep the transport created with the captured JWT.
        $this->jwt->setJwt(null);
        $this->fake->queueToolResult('done');
        $tools[0]->setInputs([])->execute();

        self::assertSame(1, $this->fake->getTransportCount());
        self::assertSame(self::JWT, $this->fake->getCreatedContexts()[0]?->jwt);
        self::assertSame(['initialize', 'notifications/initialized', 'tools/call'], $this->fake->getMethods(self::FLOWABLE_URL));
    }

    public function testOtherAuthTypesAreUnaffected(): void
    {
        $docs = $this->addConnection('docs', self::DOCS_URL);
        $docs->setConfigJson(['auth_type' => 'bearer', 'token' => 'docs-secret'] + $docs->getConfigJson());
        $this->addTool($docs, 'search', null, ['type' => 'object']);
        $this->addTool($docs, 'write', null, ['type' => 'object'])->setAnnotations(['readOnlyHint' => false]);

        // No JWT: nothing is dropped.
        self::assertSame(['search', 'write'], self::names($this->factory()->forConnections([$docs])));
        self::assertNull($this->fake->getLastCreatedContext()?->jwt);

        // With a JWT: it is not handed to a connection that does not forward it.
        $this->jwt->setJwt(self::JWT);
        self::assertSame(['search', 'write'], self::names($this->factory()->forConnections([$docs])));
        $context = $this->fake->getLastCreatedContext();
        self::assertSame(McpTransportContext::PURPOSE_TURN, $context?->purpose);
        self::assertNull($context->jwt);
        self::assertSame('docs-secret', $this->fake->getCreatedConfigs()[1]['token']);
    }

    public function testFactoryWithoutJwtProviderFailsClosed(): void
    {
        $flowable = $this->flowable();
        $this->addTool($flowable, 'list_tasks', null, ['type' => 'object'])->setAnnotations(['readOnlyHint' => true]);
        $this->addTool($flowable, 'complete_task', null, ['type' => 'object']);

        $tools = (new McpToolFactory($this->entityManager(), $this->fake, null, ConfigSecretsFixture::create()))->forConnections([$flowable]);

        self::assertSame(['list_tasks'], self::names($tools));
    }

    public function testTransportGetsTheDecryptedScanSecret(): void
    {
        $secrets = ConfigSecretsFixture::create();
        $flowable = $this->flowable();
        $flowable->setConfigJson($secrets->encrypt($flowable->getConfigJson()));
        $this->addTool($flowable, 'list_tasks', null, ['type' => 'object'])->setAnnotations(['readOnlyHint' => true]);

        (new McpToolFactory($this->entityManager(), $this->fake, null, $secrets, $this->jwt))->forConnections([$flowable]);

        self::assertSame('mcp-scan-dev-secret', $this->fake->getCreatedConfigs()[0]['scan_client_secret']);
    }

    public function testScanAndHealthPassTheirContexts(): void
    {
        $flowable = $this->flowable();

        (new McpToolScanner($this->entityManager(), $this->fake, ConfigSecretsFixture::create()))->scanConnection($flowable);
        (new McpHealthClient($flowable->getConfigJson(), $this->fake))->getHealthInfo();

        $contexts = $this->fake->getCreatedContexts();
        self::assertCount(2, $contexts);
        self::assertSame(McpTransportContext::PURPOSE_SCAN, $contexts[0]?->purpose);
        self::assertSame(McpTransportContext::PURPOSE_HEALTH, $contexts[1]?->purpose);
        self::assertNull($contexts[0]->jwt);
        self::assertNull($contexts[1]->jwt);
    }

    private function flowable(): ApiConfiguration
    {
        $connection = $this->addConnection('flowable', self::FLOWABLE_URL);
        $connection->setConfigJson($connection->getConfigJson() + [
            'auth_type' => 'forward_jwt',
            'scan_token_url' => 'http://sso.example.org/realms/example/protocol/openid-connect/token',
            'scan_client_id' => 'mcp-scan',
            'scan_client_secret' => 'mcp-scan-dev-secret',
        ]);

        return $connection;
    }

    private function factory(?LoggerInterface $logger = null): McpToolFactory
    {
        return new McpToolFactory($this->entityManager(), $this->fake, $logger, null, $this->jwt);
    }

    /**
     * @param list<ToolInterface> $tools
     *
     * @return list<string>
     */
    private static function names(array $tools): array
    {
        return array_map(static fn (ToolInterface $tool): string => $tool->getName(), $tools);
    }
}
