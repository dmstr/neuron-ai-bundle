<?php

// file generated with AI assistance: Claude Code - 2026-09-30 05:42:49 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Testing;

use NeuronAI\RAG\Embeddings\AbstractEmbeddingsProvider;

/**
 * Deterministic embeddings fake for one model that honours the model's
 * dimension.
 *
 * The neuron-ai fake derives its vector from a single MD5 hex digest and
 * therefore returns at most 32 values, which does not fit a 768-dimensional
 * model. This fake keeps hashing "<text>#<i>" until the requested number of
 * dimensions is reached. Every embedText() call is recorded as
 * (model, text) on a {@see FakeEmbeddingsRecorder}, which
 * {@see FakeProviderFactory} shares between the fakes of all models.
 */
class FakeEmbeddingsProvider extends AbstractEmbeddingsProvider
{
    private readonly FakeEmbeddingsRecorder $recorder;

    public function __construct(
        private readonly string $model = 'fake',
        private readonly int $dimensions = 8,
        ?FakeEmbeddingsRecorder $recorder = null,
    ) {
        $this->recorder = $recorder ?? new FakeEmbeddingsRecorder();
    }

    /**
     * @return float[]
     */
    public function embedText(string $text): array
    {
        $this->recorder->record($this->model, $text);

        return $this->deterministicVector($text);
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function getRecorder(): FakeEmbeddingsRecorder
    {
        return $this->recorder;
    }

    /**
     * @return float[]
     */
    protected function deterministicVector(string $text): array
    {
        $vector = [];
        for ($i = 0; \count($vector) < $this->dimensions; ++$i) {
            foreach (str_split(md5($text.'#'.$i)) as $char) {
                $vector[] = \ord($char) / 255.0;
                if (\count($vector) === $this->dimensions) {
                    break;
                }
            }
        }

        return $vector;
    }
}
