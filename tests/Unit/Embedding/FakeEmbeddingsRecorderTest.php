<?php

// file generated with AI assistance: Claude Code - 2026-10-01 13:17:06 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\Embedding;

use Dmstr\NeuronAiBundle\Testing\FakeEmbeddingsProvider;
use Dmstr\NeuronAiBundle\Testing\FakeEmbeddingsRecorder;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;

class FakeEmbeddingsRecorderTest extends TestCase
{
    public function testFakesOfSeveralModelsShareOneRecorder(): void
    {
        $recorder = new FakeEmbeddingsRecorder();
        $recorder->assertNothingEmbedded();

        (new FakeEmbeddingsProvider('nomic-embed-text', 8, $recorder))->embedText('eins');
        (new FakeEmbeddingsProvider('mxbai-embed-large', 8, $recorder))->embedText('zwei');

        self::assertSame([
            ['model' => 'nomic-embed-text', 'text' => 'eins'],
            ['model' => 'mxbai-embed-large', 'text' => 'zwei'],
        ], $recorder->getRecorded());
        $recorder->assertModelUsed('mxbai-embed-large');
        $recorder->assertEmbeddedText('eins');
        $recorder->assertEmbeddedText('eins', 'nomic-embed-text');
        self::assertSame(['zwei'], $recorder->getRecordedTexts('mxbai-embed-large'));

        $recorder->reset();
        $recorder->assertNothingEmbedded();
    }

    public function testAssertEmbeddedTextChecksTheModel(): void
    {
        $recorder = new FakeEmbeddingsRecorder();
        $recorder->record('nomic-embed-text', 'eins');

        $this->expectException(AssertionFailedError::class);
        $recorder->assertEmbeddedText('eins', 'mxbai-embed-large');
    }
}
