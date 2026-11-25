<?php

// file generated with AI assistance: Claude Code - 2026-10-02 05:54:24 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\Provider;

use Dmstr\NeuronAiBundle\Provider\ToolChoice;
use PHPUnit\Framework\TestCase;

/**
 * The tool choice value object: constructors, predicates, equality and
 * string form.
 */
final class ToolChoiceTest extends TestCase
{
    public function testNamedConstructorsSetTypeAndName(): void
    {
        self::assertSame(ToolChoice::AUTO, ToolChoice::auto()->type);
        self::assertSame(ToolChoice::ANY, ToolChoice::any()->type);
        self::assertSame(ToolChoice::NONE, ToolChoice::none()->type);
        self::assertNull(ToolChoice::none()->name);

        $tool = ToolChoice::tool('search');
        self::assertSame(ToolChoice::TOOL, $tool->type);
        self::assertSame('search', $tool->name);
    }

    public function testPredicates(): void
    {
        self::assertTrue(ToolChoice::auto()->isAuto());
        self::assertFalse(ToolChoice::auto()->isNone());
        self::assertTrue(ToolChoice::any()->isAny());
        self::assertFalse(ToolChoice::any()->isTool());
        self::assertTrue(ToolChoice::none()->isNone());
        self::assertFalse(ToolChoice::none()->isAuto());
        self::assertTrue(ToolChoice::tool('search')->isTool());
        self::assertFalse(ToolChoice::tool('search')->isAny());
    }

    public function testEquals(): void
    {
        self::assertTrue(ToolChoice::none()->equals(ToolChoice::none()));
        self::assertFalse(ToolChoice::none()->equals(ToolChoice::auto()));
        self::assertTrue(ToolChoice::tool('search')->equals(ToolChoice::tool('search')));
        self::assertFalse(ToolChoice::tool('search')->equals(ToolChoice::tool('fetch')));
        self::assertFalse(ToolChoice::tool('search')->equals(ToolChoice::any()));
    }

    public function testToString(): void
    {
        self::assertSame('auto', (string) ToolChoice::auto());
        self::assertSame('any', (string) ToolChoice::any());
        self::assertSame('none', (string) ToolChoice::none());
        self::assertSame('tool:search', (string) ToolChoice::tool('search'));
    }

    public function testEmptyToolNameIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ToolChoice::tool('');
    }
}
