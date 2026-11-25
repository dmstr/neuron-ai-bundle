<?php

// file generated with AI assistance: Claude Code - 2026-10-01 22:27:25 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\Conversation\Anonymous;

use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Validates request bodies against the input schema of
 * `POST /api/conversations/anonymous` the way InputBodyValidator does: the
 * file is decoded to a PHP array (as OperationInputSchemaResolver does),
 * re-encoded and decoded to objects. An empty `"properties": {}` turns into
 * a JSON array on that round trip, which opis rejects as an invalid schema
 * ("properties must be an object"); this test catches that without a
 * database. Kernel-free.
 */
final class InputSchemaTest extends TestCase
{
    private const BUNDLE_ENTITY_DIR = __DIR__.'/../../../../src/Entity';

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function anonymousBodies(): iterable
    {
        yield 'empty object' => ['{}', true];
        yield 'empty array' => ['[]', false];
        yield 'collections key' => ['{"collections": []}', false];
        yield 'title key' => ['{"title": "x"}', false];
    }

    #[DataProvider('anonymousBodies')]
    public function testAnonymousBody(string $body, bool $valid): void
    {
        $schema = self::loadAsResolverDoes(self::BUNDLE_ENTITY_DIR.'/Conversation/anonymous.input.json');

        self::assertSame($valid, self::validate($schema, $body)->isValid());
    }

    /**
     * Every input schema of the bundle survives the array round trip, so
     * none of them throws a schema exception on validation.
     */
    public function testEveryBundleInputSchemaCompilesAfterTheArrayRoundTrip(): void
    {
        // No GLOB_BRACE: it is not available on musl (Alpine) builds.
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::BUNDLE_ENTITY_DIR, \FilesystemIterator::SKIP_DOTS)) as $info) {
            if ($info instanceof \SplFileInfo && str_ends_with($info->getFilename(), '.input.json')) {
                $files[] = $info->getPathname();
            }
        }
        self::assertGreaterThanOrEqual(4, \count($files));

        foreach ($files as $file) {
            $schema = self::loadAsResolverDoes($file);
            // The result does not matter here; an invalid schema throws.
            self::validate($schema, '{}');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function loadAsResolverDoes(string $file): array
    {
        $content = file_get_contents($file);
        self::assertIsString($content, $file);
        $schema = json_decode($content, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($schema, $file);

        return $schema;
    }

    /**
     * @param array<string, mixed> $schema
     */
    private static function validate(array $schema, string $body): \Opis\JsonSchema\ValidationResult
    {
        return (new Validator())->validate(
            json_decode($body, false, 512, \JSON_THROW_ON_ERROR),
            json_decode((string) json_encode($schema, \JSON_THROW_ON_ERROR), false, 512, \JSON_THROW_ON_ERROR),
        );
    }
}
