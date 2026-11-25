<?php

// file generated with AI assistance: Claude Code - 2026-11-09 15:50:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\Conversation;

use Dmstr\NeuronAiBundle\Conversation\RetrievalQuery;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use PHPUnit\Framework\TestCase;

/**
 * The search text of a turn: the message plus earlier user messages, a
 * submitted form by its values.
 */
final class RetrievalQueryTest extends TestCase
{
    public function testWithoutPreviousMessagesTheMessageAlone(): void
    {
        $history = [new UserMessage('Pumpengehäuse aus Grauguss'), new AssistantMessage('Heckert H')];

        self::assertSame('Und ein Beispiel?', RetrievalQuery::of('Und ein Beispiel?', $history, 0));
        self::assertSame('Erste Frage', RetrievalQuery::of('Erste Frage', [], 2));
    }

    public function testEarlierUserMessagesNewestFirstUpToTheLimit(): void
    {
        $history = [
            new UserMessage('eins'),
            new AssistantMessage('a'),
            new UserMessage('zwei'),
            new AssistantMessage('b'),
            new UserMessage('drei'),
            new AssistantMessage('c'),
        ];

        self::assertSame("vier\ndrei\nzwei", RetrievalQuery::of('vier', $history, 2));
    }

    public function testAFormSubmissionIsSearchedByItsValues(): void
    {
        $form = '{"form": "werkzeug", "value": {"groesse": "bis-1500", "art": ["schruppen", ""], "anzahl": 3}}';

        self::assertSame('bis-1500, schruppen, 3', RetrievalQuery::text($form));
        self::assertSame("bis-1500, schruppen, 3\nGesenke für die Automobilindustrie", RetrievalQuery::of($form, [new UserMessage('Gesenke für die Automobilindustrie')], 1));
        self::assertSame('{"a": 1}', RetrievalQuery::text('{"a": 1}'), 'JSON that is no form stays as it is');
    }
}
