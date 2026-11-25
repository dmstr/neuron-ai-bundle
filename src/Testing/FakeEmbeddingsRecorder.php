<?php

// file generated with AI assistance: Claude Code - 2026-10-01 13:17:06 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Testing;

use PHPUnit\Framework\Assert;

/**
 * Shared log of the texts the {@see FakeEmbeddingsProvider} instances of one
 * {@see FakeProviderFactory} embedded, with the model each text was embedded
 * with. Texts are recorded as the model received them, i.e. including the
 * task prefixes added by the PrefixedEmbeddingsProvider.
 */
final class FakeEmbeddingsRecorder
{
    /** @var list<array{model: string, text: string}> */
    private array $recorded = [];

    public function record(string $model, string $text): void
    {
        $this->recorded[] = ['model' => $model, 'text' => $text];
    }

    /**
     * @return list<array{model: string, text: string}>
     */
    public function getRecorded(): array
    {
        return $this->recorded;
    }

    /**
     * @return list<string> recorded texts, optionally only those of $model
     */
    public function getRecordedTexts(?string $model = null): array
    {
        $texts = [];
        foreach ($this->recorded as $record) {
            if ($model === null || $record['model'] === $model) {
                $texts[] = $record['text'];
            }
        }

        return $texts;
    }

    public function assertEmbeddedText(string $text, ?string $model = null): void
    {
        Assert::assertContains(
            $text,
            $this->getRecordedTexts($model),
            $model === null
                ? sprintf('Expected text was never embedded: %s', $text)
                : sprintf('Expected text was never embedded with model "%s": %s', $model, $text),
        );
    }

    public function assertModelUsed(string $model): void
    {
        Assert::assertContains(
            $model,
            array_column($this->recorded, 'model'),
            sprintf('Expected embedding model "%s" was never used.', $model),
        );
    }

    public function assertNothingEmbedded(): void
    {
        Assert::assertEmpty(
            $this->recorded,
            sprintf('Expected no embedding calls, but %d were recorded.', \count($this->recorded)),
        );
    }

    public function reset(): void
    {
        $this->recorded = [];
    }
}
