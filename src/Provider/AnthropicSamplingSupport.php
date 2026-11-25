<?php

// file generated with AI assistance: Claude Code - 2026-10-06 17:20:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Provider;

/**
 * Whether an Anthropic model accepts the sampling parameters
 * `temperature`, `top_p` and `top_k`. The Models API does not report it,
 * so it is a rule table over the model lines:
 *
 * | Model line | Sampling |
 * |---|---|
 * | `claude-opus-4-7` and later Opus (`claude-opus-5`, `claude-opus-5-5`, …) | no: rejected with HTTP 400 |
 * | `claude-fable-*` | no: rejected with HTTP 400 |
 * | `claude-sonnet-5`, `claude-sonnet-5-5` and later Sonnet | no: rejected, or only the defaults accepted (`effort` steers instead) |
 * | earlier Opus (up to 4.6), Sonnet 4.x, Haiku 4.x and the `claude-3*` models | yes |
 * | any other id (a new line, a later Haiku) | unknown (null) |
 *
 * A model that accepts only the default values counts as "no", because a
 * configured value would be rejected. Extend the table when a new line is
 * released.
 */
final class AnthropicSamplingSupport
{
    /** First Opus version that rejects sampling. */
    private const array OPUS_WITHOUT_SAMPLING = [4, 7];

    private function __construct()
    {
    }

    /**
     * @return bool|null null = not covered by the table
     */
    public static function of(string $model): ?bool
    {
        if (1 === preg_match('/^claude-\d(?:-\d)?-(?:opus|sonnet|haiku)(?:-|$)/', $model)) {
            // Legacy ids such as claude-3-5-sonnet-20241022.
            return true;
        }

        if (1 !== preg_match('/^claude-(opus|sonnet|haiku|fable)-(\d+)(?:-(\d{1,2}))?(?:-\d{8}|-latest)?$/', $model, $matches)) {
            return null;
        }

        $line = $matches[1];
        $version = [(int) $matches[2], (int) ($matches[3] ?? 0)];

        return match ($line) {
            'fable' => false,
            'opus' => $version < self::OPUS_WITHOUT_SAMPLING,
            'sonnet' => $version[0] < 5,
            'haiku' => $version[0] <= 4 ? true : null,
        };
    }
}
