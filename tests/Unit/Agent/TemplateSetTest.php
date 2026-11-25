<?php

// file generated with AI assistance: Claude Code - 2026-10-03 18:59:07 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\Agent;

use Dmstr\NeuronAiBundle\Agent\Template\TemplateRenderer;
use Dmstr\NeuronAiBundle\Agent\Template\TemplateSet;
use PHPUnit\Framework\TestCase;

/**
 * Kernel-free: template lookup with language fallback and lenient
 * rendering, the split of the `frame` slot.
 */
final class TemplateSetTest extends TestCase
{
    private const array TEMPLATES = [
        'refusal' => ['de' => 'Nein, {{ customer }}.', 'en' => 'No, {{customer}}.'],
        'only_en' => ['en' => 'English only.'],
    ];

    public function testHas(): void
    {
        $templates = new TemplateSet(self::TEMPLATES, 'de');

        self::assertTrue($templates->has('refusal'));
        self::assertFalse($templates->has('missing'));
        self::assertFalse(new TemplateSet()->has('refusal'));
    }

    public function testTextOfTheLanguage(): void
    {
        $templates = new TemplateSet(self::TEMPLATES, 'de');

        self::assertSame('No, {{customer}}.', $templates->text('refusal', 'en'));
        self::assertSame('Nein, {{ customer }}.', $templates->text('refusal', 'de'));
    }

    public function testTextFallsBackToTheDefaultLanguage(): void
    {
        $templates = new TemplateSet(self::TEMPLATES, 'de');

        self::assertSame('Nein, {{ customer }}.', $templates->text('refusal', 'fr'));
    }

    public function testMissingTemplateOrTextIsNull(): void
    {
        $templates = new TemplateSet(self::TEMPLATES, 'de');

        self::assertNull($templates->text('missing', 'de'));
        self::assertNull($templates->text('only_en', 'fr'));
        self::assertNull($templates->render('missing', 'de', []));
    }

    public function testRender(): void
    {
        $templates = new TemplateSet(self::TEMPLATES, 'de');

        self::assertSame('Nein, Kim.', $templates->render('refusal', 'de', ['customer' => 'Kim']));
        self::assertSame('No, .', $templates->render('refusal', 'en', []));
    }

    public function testRendererIsLenientAndSinglePass(): void
    {
        self::assertSame('a  c', TemplateRenderer::render('a {{b}} c', []));
        self::assertSame('{{y}}', TemplateRenderer::render('{{x}}', ['x' => '{{y}}', 'y' => 'no']));
        self::assertSame('{{ 1x }} {x}', TemplateRenderer::render('{{ 1x }} {x}', ['x' => 'no']));
        self::assertSame('Grüße, Kim', TemplateRenderer::render('Grüße, {{name}}', ['name' => 'Kim']));
    }

    public function testReservedSlotsAndContextPlaceholders(): void
    {
        self::assertSame(['frame', 'limitReason', 'turnLimitReason', 'forcedCloseFallback', 'injectionRefusal', 'unanswerableRefusal'], TemplateSet::RESERVED);
        self::assertSame(TemplateSet::RESERVED, array_keys(TemplateSet::SLOT_PLACEHOLDERS));
        self::assertTrue(TemplateSet::isReserved('frame'));
        self::assertFalse(TemplateSet::isReserved('refusal'));
        self::assertSame(['answer'], TemplateSet::contextPlaceholders('frame'));
        self::assertSame(['toolCalls', 'maxToolCalls'], TemplateSet::contextPlaceholders('limitReason'));
        self::assertSame(['turn', 'maxUserTurns'], TemplateSet::contextPlaceholders('turnLimitReason'));
        self::assertSame([], TemplateSet::contextPlaceholders('forcedCloseFallback'));
        self::assertTrue(TemplateSet::isReserved(TemplateSet::INJECTION_REFUSAL));
        self::assertTrue(TemplateSet::isReserved(TemplateSet::UNANSWERABLE_REFUSAL));
        self::assertSame([], TemplateSet::contextPlaceholders('injectionRefusal'));
        self::assertSame([], TemplateSet::contextPlaceholders('unanswerableRefusal'));
        self::assertSame(['rule', 'outcome'], TemplateSet::contextPlaceholders('refusal'));
    }

    public function testAnswerCount(): void
    {
        self::assertSame(0, TemplateSet::answerCount('Hallo {{name}}.'));
        self::assertSame(1, TemplateSet::answerCount('> {{ answer }} <'));
        self::assertSame(2, TemplateSet::answerCount('{{answer}}{{answer}}'));
        self::assertSame(0, TemplateSet::answerCount('{{answers}} {answer} {{ 1answer }}'));
    }

    public function testFrameIsSplitAtTheAnswerAndRendered(): void
    {
        $templates = new TemplateSet(['frame' => [
            'de' => "Hallo {{customer}},\n\n{{ answer }}\n\n-- {{agentName}}",
            'en' => '{{answer}}',
        ]], 'de');

        self::assertSame(
            ['prefix' => "Hallo Kim,\n\n", 'suffix' => "\n\n-- Bot"],
            $templates->frame('de', ['customer' => 'Kim', 'agentName' => 'Bot']),
        );
        self::assertSame(['prefix' => '', 'suffix' => ''], $templates->frame('en', []));
        // Fallback to the default language; missing values render as ''.
        self::assertSame(['prefix' => "Hallo ,\n\n", 'suffix' => "\n\n-- "], $templates->frame('fr', []));
    }

    public function testFrameIgnoresAnAnswerValueAndValuesDoNotMoveTheSplit(): void
    {
        $templates = new TemplateSet(['frame' => ['de' => '[{{customer}}] {{answer}} [{{customer}}]']], 'de');

        self::assertSame(
            ['prefix' => '[{{answer}}] ', 'suffix' => ' [{{answer}}]'],
            $templates->frame('de', ['customer' => '{{answer}}', 'answer' => 'X']),
        );
    }

    public function testFrameIsNullWithoutATemplateOrWithoutExactlyOneAnswer(): void
    {
        self::assertNull(new TemplateSet()->frame('de', []));
        self::assertNull(new TemplateSet(self::TEMPLATES, 'de')->frame('de', []));
        self::assertNull(new TemplateSet(['frame' => ['de' => 'Kein Platzhalter.']], 'de')->frame('de', []));
        self::assertNull(new TemplateSet(['frame' => ['de' => '{{answer}} {{answer}}']], 'de')->frame('de', []));
        self::assertNull(new TemplateSet(['frame' => ['en' => '{{answer}}']], 'de')->frame('fr', []));
    }
}
