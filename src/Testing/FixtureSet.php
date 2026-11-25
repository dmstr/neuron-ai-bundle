<?php

// file generated with AI assistance: Claude Code - 2026-10-03 18:37:15 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Testing;

/**
 * Loads fixture sets for the fixture mode of the test fakes from JSON.
 *
 * A fixture file is one JSON object, key => fixture:
 *
 * - {@see load()} for {@see RecordingFakeAIProvider::useFixtures()}: key
 *   phrase => answer text, or `{answer|failure, toolCalls?, reasoning?}`;
 * - {@see loadMcp()} for {@see FakeMcpTransportFactory::useFixtures()}:
 *   `"<toolName>"` or `"<toolName>#<hash>"` => text content, or
 *   `{content: string|list, isError?: bool}`.
 *
 *     $factory->useFixtures(FixtureSet::load(__DIR__.'/../Fixtures/evaluation/answers.json'));
 *
 * Both check the file structure only (an object of strings and objects);
 * the fakes validate the fixtures themselves when they are set.
 */
final class FixtureSet
{
    /**
     * @return array<string, string|array<string, mixed>>
     *
     * @throws \InvalidArgumentException when the file is missing, unreadable, not valid JSON or not a JSON object of fixtures
     */
    public static function load(string $path): array
    {
        return self::read($path);
    }

    /**
     * @return array<string, string|array<string, mixed>>
     *
     * @throws \InvalidArgumentException as load()
     */
    public static function loadMcp(string $path): array
    {
        return self::read($path);
    }

    /**
     * @return array<string, string|array<string, mixed>>
     */
    private static function read(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new \InvalidArgumentException(\sprintf('Fixture file "%s" does not exist or is not readable.', $path));
        }
        $json = file_get_contents($path);
        if (false === $json) {
            throw new \InvalidArgumentException(\sprintf('Fixture file "%s" could not be read.', $path));
        }

        try {
            $decoded = json_decode($json, false, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException(\sprintf('Fixture file "%s" is not valid JSON: %s', $path, $e->getMessage()), 0, $e);
        }
        if (!$decoded instanceof \stdClass) {
            throw new \InvalidArgumentException(\sprintf('Fixture file "%s" must contain a JSON object (key => fixture), got %s.', $path, get_debug_type($decoded)));
        }

        $fixtures = [];
        foreach (json_decode($json, true, 512, \JSON_THROW_ON_ERROR) as $key => $fixture) {
            if (!\is_string($fixture) && !\is_array($fixture)) {
                throw new \InvalidArgumentException(\sprintf('Fixture file "%s": fixture "%s" must be a string or an object, got %s.', $path, $key, get_debug_type($fixture)));
            }
            $fixtures[(string) $key] = $fixture;
        }

        return $fixtures;
    }
}
