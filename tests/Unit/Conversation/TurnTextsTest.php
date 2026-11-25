<?php

// file generated with AI assistance: Claude Code - 2026-10-03 20:23:32 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\Conversation;

use Dmstr\NeuronAiBundle\Agent\Template\TemplateSet;
use Dmstr\NeuronAiBundle\Conversation\ForcedClose;
use Dmstr\NeuronAiBundle\Conversation\TurnTexts;
use PHPUnit\Framework\TestCase;

/**
 * Kernel-free: the limit reasons and the forced-close fallback of a turn.
 * Without templates the reasons are ForcedClose's English
 * texts byte for byte and there is no fallback.
 */
final class TurnTextsTest extends TestCase
{
    private const array TEMPLATES = [
        'limitReason' => [
            'de' => '{{toolCalls}} von {{maxToolCalls}} Werkzeugaufrufen, {{customer}}.',
            'en' => '{{toolCalls}} of {{maxToolCalls}} tool calls.',
        ],
        'turnLimitReason' => ['de' => 'Runde {{turn}} von {{ maxUserTurns }}.'],
        'forcedCloseFallback' => ['de' => 'Leider keine Antwort, {{customer}}.', 'en' => 'Sorry, no answer.'],
    ];

    public function testDefaultsAreTheEnglishReasonsWithoutFallback(): void
    {
        $texts = new TurnTexts();

        self::assertSame(ForcedClose::limitReason(3, 2), $texts->limitReason(3, 2));
        self::assertSame('Tool-call limit of 2 reached after 3 calls; the model was asked to answer.', $texts->limitReason(3, 2));
        self::assertSame(ForcedClose::turnLimitReason(4), $texts->turnLimitReason(4, 4));
        self::assertNull($texts->fallback());
    }

    public function testTemplatesWithoutTheSlotsKeepTheDefaults(): void
    {
        $texts = new TurnTexts(new TemplateSet(['refusal' => ['de' => 'Nein.']], 'de'), 'de', ['customer' => 'Kim']);

        self::assertSame(ForcedClose::limitReason(1, 1), $texts->limitReason(1, 1));
        self::assertSame(ForcedClose::turnLimitReason(2), $texts->turnLimitReason(2, 3));
        self::assertNull($texts->fallback());
    }

    public function testSlotsAreRenderedInTheEffectiveLanguage(): void
    {
        $templates = new TemplateSet(self::TEMPLATES, 'de');
        $de = new TurnTexts($templates, 'de', ['customer' => 'Kim']);
        $en = new TurnTexts($templates, 'en', ['customer' => 'Kim']);

        self::assertSame('3 von 2 Werkzeugaufrufen, Kim.', $de->limitReason(3, 2));
        self::assertSame('3 of 2 tool calls.', $en->limitReason(3, 2));
        self::assertSame('Runde 2 von 5.', $de->turnLimitReason(2, 5));
        // No English text: the default language's.
        self::assertSame('Runde 2 von 5.', $en->turnLimitReason(2, 5));
        self::assertSame('Leider keine Antwort, Kim.', $de->fallback());
        self::assertSame('Sorry, no answer.', $en->fallback());
    }

    public function testSlotValuesWinAndMissingValuesRenderEmpty(): void
    {
        $texts = new TurnTexts(new TemplateSet(self::TEMPLATES, 'de'), 'de', ['toolCalls' => 'caller', 'turn' => 'caller']);

        self::assertSame('7 von 6 Werkzeugaufrufen, .', $texts->limitReason(7, 6));
        self::assertSame('Runde 1 von 1.', $texts->turnLimitReason(1, 1));
        self::assertSame('Leider keine Antwort, .', $texts->fallback());
    }
}
