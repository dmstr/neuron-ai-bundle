<?php

// file generated with AI assistance: Claude Code - 2026-10-01 09:53:20 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Integration\Mcp;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Resource\Factory\ResourceMetadataCollectionFactoryInterface;
use ApiPlatform\State\SerializerContextBuilderInterface;
use Dmstr\ApiConfiguration\Entity\ApiConfiguration;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * Pins how the REST health route serializes the nested part of the health
 * contract. ApiConfigurationHealthProvider returns a \stdClass from the
 * `api_configuration_health` operation, which has no output class; `jsonld`
 * is the only enabled format. Up to dmstr/api-configuration-bundle 0.4,
 * every nested array of that object was matched by the Hydra
 * CollectionNormalizer, so `metadata` and `error` arrived as keyless
 * `hydra:Collection` objects. Since 0.5.0-beta1 the operation
 * carries `api_sub_level` in its normalizationContext, which the context
 * builder used here picks up just like the real route does, and the nested
 * arrays keep their keys. The functional tests in McpConnectionResourceTest
 * read `metadata.custom` and
 * `error.code` over REST on that basis.
 *
 * Kernel only, no database.
 */
final class McpHealthSerializationTest extends KernelTestCase
{
    public function testJsonLdKeepsTheKeysOfNestedHealthArrays(): void
    {
        $data = $this->serialize('jsonld');

        self::assertSame('error', $data['status']);
        self::assertSame('MCP server rejected the credentials: 401', $data['message']);
        self::assertSame(['apiName' => 'mcp', 'custom' => ['toolCount' => 1]], $data['metadata']);
        self::assertSame(['code' => 'UNAUTHENTICATED', 'message' => '401', 'details' => ['exception' => 'RuntimeException']], $data['error']);
        self::assertStringNotContainsString('hydra:Collection', json_encode($data, \JSON_THROW_ON_ERROR));
    }

    public function testPlainJsonWouldKeepTheNestedHealthArrays(): void
    {
        $data = $this->serialize('json');

        self::assertSame(['apiName' => 'mcp', 'custom' => ['toolCount' => 1]], $data['metadata']);
        self::assertSame('UNAUTHENTICATED', $data['error']['code']);
    }

    public function testHealthOperationHasNoOutputClassAndJsonLdIsTheOnlyFormat(): void
    {
        self::bootKernel();

        self::assertNull($this->operation()->getOutput());
        self::assertSame(['jsonld'], array_keys((array) static::getContainer()->getParameter('api_platform.formats')));
    }

    /**
     * Serializes a health result shaped like the provider's \stdClass with
     * the context API Platform's SerializeProcessor builds for the operation.
     *
     * @return array<string, mixed>
     */
    private function serialize(string $format): array
    {
        self::bootKernel();
        $container = static::getContainer();

        $result = new \stdClass();
        $result->status = 'error';
        $result->authenticated = false;
        $result->endpoint = 'https://mcp.example.org/mcp';
        $result->responseTime = 1.5;
        $result->message = 'MCP server rejected the credentials: 401';
        $result->metadata = ['apiName' => 'mcp', 'custom' => ['toolCount' => 1]];
        $result->error = ['code' => 'UNAUTHENTICATED', 'message' => '401', 'details' => ['exception' => 'RuntimeException']];

        $request = Request::create('/api/admin/api_configurations/0199a1b2/health');
        $request->setRequestFormat($format);

        $contextBuilder = $container->get('api_platform.serializer.context_builder');
        self::assertInstanceOf(SerializerContextBuilderInterface::class, $contextBuilder);
        $context = $contextBuilder->createFromRequest($request, true, [
            'resource_class' => ApiConfiguration::class,
            'operation' => $this->operation(),
        ]);
        $context['uri_variables'] = ['id' => '0199a1b2'];

        $serializer = $container->get('serializer');
        self::assertInstanceOf(SerializerInterface::class, $serializer);

        /** @var array<string, mixed> $data */
        $data = json_decode($serializer->serialize($result, $format, $context), true, 512, \JSON_THROW_ON_ERROR);

        return $data;
    }

    private function operation(): Operation
    {
        $factory = static::getContainer()->get('api_platform.metadata.resource.metadata_collection_factory');
        self::assertInstanceOf(ResourceMetadataCollectionFactoryInterface::class, $factory);

        return $factory->create(ApiConfiguration::class)->getOperation('api_configuration_health');
    }
}
