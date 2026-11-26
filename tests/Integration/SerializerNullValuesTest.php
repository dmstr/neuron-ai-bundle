<?php

// file generated with AI assistance: Claude Code - 2026-11-25 23:30:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Integration;

use ApiPlatform\State\SerializerContextBuilderInterface;
use Dmstr\NeuronAiBundle\Entity\Collection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Null values in API responses, without a database: API Platform's context
 * builder sets `skip_null_values` (nothing in the test kernel or a host
 * application turns it off), so a property whose value is null is left
 * out of the JSON-LD and JSON representation instead of being sent as
 * null. Shown with an unlocked collection on the context of
 * `POST /api/admin/collections/{id}/unlock`: `lockedAt` and `lockedBy` are
 * missing, `locked` is false.
 *
 * Tests that read such a property from a response get no key; PHP raises a
 * warning in the test code, which `phpunit.dist.xml` does not count
 * (`restrictWarnings`, issues of src/ only).
 */
final class SerializerNullValuesTest extends KernelTestCase
{
    private const string UNLOCK = '/api/admin/collections/0199aa00-0000-7000-8000-000000000001/unlock';

    public function testTheContextBuilderSkipsNullValues(): void
    {
        self::assertTrue($this->unlockContext()['skip_null_values'] ?? null);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function formats(): iterable
    {
        yield 'json-ld' => ['jsonld'];
        yield 'json' => ['json'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('formats')]
    public function testNullPropertiesAreLeftOut(string $format): void
    {
        $collection = new Collection()->setName('null-values');
        $normalizer = static::getContainer()->get('serializer');
        self::assertInstanceOf(NormalizerInterface::class, $normalizer);

        $data = $normalizer->normalize($collection, $format, $this->unlockContext());

        self::assertIsArray($data);
        self::assertSame('null-values', $data['name'] ?? null);
        self::assertFalse($data['locked'] ?? null);
        self::assertArrayNotHasKey('lockedAt', $data);
        self::assertArrayNotHasKey('lockedBy', $data);
    }

    /**
     * The normalization context of the unlock operation, as the serialize
     * processor builds it for a request.
     *
     * @return array<string, mixed>
     */
    private function unlockContext(): array
    {
        static::bootKernel();
        $container = static::getContainer();
        $router = $container->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);
        $builder = $container->get('api_platform.serializer.context_builder');
        self::assertInstanceOf(SerializerContextBuilderInterface::class, $builder);

        $request = Request::create(self::UNLOCK, 'POST');
        $router->getContext()->setMethod('POST');
        $request->attributes->add($router->match(self::UNLOCK));

        return $builder->createFromRequest($request, true);
    }
}
