<?php

// file generated with AI assistance: Claude Code - 2026-10-06 17:40:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\ProviderConnection;

use Dmstr\NeuronAiBundle\Provider\AnthropicSamplingSupport;
use Dmstr\NeuronAiBundle\Provider\ModelName;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Name equivalence of models and the sampling rule table of Anthropic
 * models, without a kernel.
 */
final class ModelNameTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function pairs(): iterable
    {
        yield 'exact' => ['qwen3:14b', 'qwen3:14b', true];
        yield 'default tag added' => ['llama3.2', 'llama3.2:latest', true];
        yield 'default tag removed' => ['llama3.2:latest', 'llama3.2', true];
        yield 'other tag' => ['llama3.2', 'llama3.2:3b', false];
        yield 'no wildcard' => ['*', 'llama3.2', false];
        yield 'empty' => ['', '', false];
    }

    #[DataProvider('pairs')]
    public function testEquivalent(string $a, string $b, bool $expected): void
    {
        self::assertSame($expected, ModelName::equivalent($a, $b));
    }

    /**
     * @return iterable<string, array{string, bool|null}>
     */
    public static function anthropicModels(): iterable
    {
        yield 'Opus 5.5' => ['claude-opus-5-5', false];
        yield 'Opus 5' => ['claude-opus-5', false];
        yield 'Opus 4.7' => ['claude-opus-4-7', false];
        yield 'Opus 4.6' => ['claude-opus-4-6', true];
        yield 'Opus 4.1 dated' => ['claude-opus-4-1-20250805', true];
        yield 'Fable 5.1' => ['claude-fable-5-1', false];
        yield 'Fable 5' => ['claude-fable-5', false];
        yield 'Sonnet 5' => ['claude-sonnet-5', false];
        yield 'Sonnet 5.5' => ['claude-sonnet-5-5', false];
        yield 'Sonnet 4.5 dated' => ['claude-sonnet-4-5-20250929', true];
        yield 'Haiku 4.5 dated' => ['claude-haiku-4-5-20251001', true];
        yield 'legacy 3.5 Sonnet' => ['claude-3-5-sonnet-20241022', true];
        yield 'legacy 3 Opus' => ['claude-3-opus-20240229', true];
        yield 'later Haiku unknown' => ['claude-haiku-6', null];
        yield 'unknown line' => ['claude-mythos-1', null];
        yield 'not an Anthropic id' => ['llama3.2', null];
    }

    #[DataProvider('anthropicModels')]
    public function testAnthropicSamplingSupport(string $model, ?bool $expected): void
    {
        self::assertSame($expected, AnthropicSamplingSupport::of($model));
    }
}
