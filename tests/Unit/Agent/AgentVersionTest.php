<?php

// file generated with AI assistance: Claude Code - 2026-10-02 21:49:46 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\Agent;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use Dmstr\NeuronAiBundle\Agent\AgentVersionKind;
use Dmstr\NeuronAiBundle\Entity\Agent;
use Dmstr\NeuronAiBundle\Entity\AgentVersion;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Kernel-free immutability checks of {@see AgentVersion}: no setter, only
 * read operations, all fields from the constructor; the agent derives
 * `currentVersionNumber` from the version it is given. Preview versions:
 * number 0 and a hash, published versions number >= 1 and no hash, the
 * label per kind, and a preview never becomes current.
 */
final class AgentVersionTest extends TestCase
{
    public function testHasNoSetter(): void
    {
        $setters = array_values(array_filter(
            array_map(static fn (\ReflectionMethod $method): string => $method->getName(), new \ReflectionClass(AgentVersion::class)->getMethods()),
            static fn (string $name): bool => str_starts_with(strtolower($name), 'set'),
        ));

        self::assertSame([], $setters);
    }

    public function testDeclaresOnlyReadOperations(): void
    {
        $attributes = new \ReflectionClass(AgentVersion::class)->getAttributes(ApiResource::class);
        self::assertCount(1, $attributes);

        $operations = [];
        foreach ($attributes[0]->newInstance()->getOperations() ?? [] as $operation) {
            $operations[] = $operation::class;
        }
        sort($operations);

        self::assertSame([Get::class, GetCollection::class], $operations);
    }

    public function testConstructorSetsAllFields(): void
    {
        $agent = new Agent();
        $publishedAt = new \DateTimeImmutable('2026-10-02 12:00:00+00:00');

        $version = new AgentVersion($agent, 3, ['model' => 'm'], 'alice', $publishedAt);

        self::assertSame($agent, $version->getAgent());
        self::assertSame(3, $version->getVersionNumber());
        self::assertSame(['model' => 'm'], $version->getConfiguration());
        self::assertSame('alice', $version->getPublishedBy());
        self::assertSame($publishedAt, $version->getPublishedAt());
    }

    public function testSettingTheCurrentVersionDerivesItsNumber(): void
    {
        $agent = new Agent();
        self::assertNull($agent->getCurrentVersionNumber());

        $agent->setCurrentVersion(new AgentVersion($agent, 3, [], 'alice'));
        self::assertSame(3, $agent->getCurrentVersionNumber());

        $agent->setCurrentVersion(null);
        self::assertNull($agent->getCurrentVersionNumber());
    }

    public function testPublishedAtDefaultsToNow(): void
    {
        $before = new \DateTimeImmutable();
        $version = new AgentVersion(new Agent(), 1, [], 'alice');

        self::assertGreaterThanOrEqual($before, $version->getPublishedAt());
        self::assertNotEquals(new AgentVersion(new Agent(), 1, [], 'alice')->getId(), $version->getId());
    }

    public function testPublishedIsTheDefaultKind(): void
    {
        $version = new AgentVersion(new Agent(), 2, [], 'alice');

        self::assertSame(AgentVersionKind::PUBLISHED, $version->getKind());
        self::assertFalse($version->isPreview());
        self::assertNull($version->getCandidateHash());
        self::assertSame('v2', $version->label());
    }

    public function testPreviewHasNumberZeroAndItsHash(): void
    {
        $hash = hash('sha256', 'candidate');
        $version = new AgentVersion(new Agent(), 0, ['model' => 'm'], 'alice', null, AgentVersionKind::PREVIEW, $hash);

        self::assertSame(AgentVersionKind::PREVIEW, $version->getKind());
        self::assertTrue($version->isPreview());
        self::assertSame(0, $version->getVersionNumber());
        self::assertSame($hash, $version->getCandidateHash());
        self::assertSame('preview '.substr($hash, 0, 8), $version->label());
    }

    /**
     * @return iterable<string, array{int, AgentVersionKind, string|null}>
     */
    public static function invalidCombinations(): iterable
    {
        $hash = hash('sha256', 'candidate');

        yield 'published with number 0' => [0, AgentVersionKind::PUBLISHED, null];
        yield 'published with a negative number' => [-1, AgentVersionKind::PUBLISHED, null];
        yield 'published with a hash' => [1, AgentVersionKind::PUBLISHED, $hash];
        yield 'preview with number 1' => [1, AgentVersionKind::PREVIEW, $hash];
        yield 'preview without hash' => [0, AgentVersionKind::PREVIEW, null];
        yield 'preview with a short hash' => [0, AgentVersionKind::PREVIEW, substr($hash, 0, 8)];
        yield 'preview with an upper-case hash' => [0, AgentVersionKind::PREVIEW, strtoupper($hash)];
    }

    #[DataProvider('invalidCombinations')]
    public function testConstructorRefusesNumberAndHashThatDoNotFitTheKind(int $number, AgentVersionKind $kind, ?string $hash): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new AgentVersion(new Agent(), $number, [], 'alice', null, $kind, $hash);
    }

    public function testPreviewNeverBecomesTheCurrentVersion(): void
    {
        $agent = new Agent();
        $published = new AgentVersion($agent, 1, [], 'alice');
        $agent->setCurrentVersion($published);

        try {
            $agent->setCurrentVersion(new AgentVersion($agent, 0, [], 'alice', null, AgentVersionKind::PREVIEW, hash('sha256', 'x')));
            self::fail('Expected a LogicException.');
        } catch (\LogicException) {
        }

        self::assertSame($published, $agent->getCurrentVersion());
        self::assertSame(1, $agent->getCurrentVersionNumber());
    }
}

// - revised 2026-10-13
