<?php

// file generated with AI assistance: Claude Code - 2026-11-20 13:50:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Chat;

use Dmstr\NeuronAiBundle\Conversation\SourceLocator;

/**
 * The sources an answer cites as `[n]` or `[n, m]`, in the shape of the
 * chat API of RAG services (`url`, `title`, `locators` with `link`, `quote` and
 * `page`, `synthetic`, `last_verified_at`, `last_changed_at`).
 *
 * The turn returns every passage it sent to the model; `[n]` points at the
 * n-th of them. The cited ones are grouped by page (`sourceUrl`, else
 * `filename`) in the order of their first citation and numbered from 1;
 * the markers of the answer follow that numbering (`[2, 5]` becomes e.g.
 * `[1, 2]`, a number that repeats within a marker once), a number without a
 * passage is dropped (the marker too when none is left) and a repeated
 * marker (`[1][1]`) collapses. An answer without markers has no sources.
 *
 * {@see self::ofStructured()} does the same for a schema answer: only its
 * string values are renumbered, so numbers in JSON arrays (`"pages": [7]`)
 * are never read as markers.
 */
final class CitedSources
{
    /** @var array<string, array<string, mixed>> source url => group */
    private array $groups = [];

    /**
     * Source url => its new number. Kept apart from the group keys: PHP turns a
     * numeric key like "2024" into an int, which a strict search of
     * `array_keys($this->groups)` would no longer find.
     *
     * @var array<string, int>
     */
    private array $numberOf = [];

    /**
     * @param list<array<string, mixed>> $passages
     */
    private function __construct(private readonly array $passages)
    {
    }

    /**
     * @param list<array<string, mixed>> $passages the `sources` of an {@see AgentChatResult}
     *
     * @return array{0: string, 1: list<array<string, mixed>>} the renumbered answer and its sources
     */
    public static function of(string $answer, array $passages): array
    {
        $cited = new self($passages);

        return [$cited->renumber($answer), array_values($cited->groups)];
    }

    /**
     * @param list<array<string, mixed>> $passages
     *
     * @return array{0: mixed, 1: list<array<string, mixed>>} the schema answer with renumbered strings and its sources
     */
    public static function ofStructured(mixed $structured, array $passages): array
    {
        $cited = new self($passages);
        $walk = static function (mixed $value) use (&$walk, $cited): mixed {
            return match (true) {
                \is_string($value) => $cited->renumber($value),
                \is_array($value) => array_map($walk, $value),
                default => $value,
            };
        };
        $structured = $walk($structured);

        return [$structured, array_values($cited->groups)];
    }

    private function renumber(string $text): string
    {
        $text = (string) preg_replace_callback(SourceLocator::CITATION, function (array $match): string {
            $numbers = [];
            foreach (preg_split('/\s*,\s*/', $match[1]) ?: [] as $number) {
                $mapped = $this->number((int) $number);
                if (null !== $mapped && !\in_array($mapped, $numbers, true)) {
                    $numbers[] = $mapped;
                }
            }

            return [] === $numbers ? '' : '['.implode(', ', $numbers).']';
        }, $text);

        return (string) preg_replace('/(\[\d+(?:, \d+)*\])(?:\s*\1)+/', '$1', $text);
    }

    /** The new number of the n-th passage (from 1), or null when there is none. */
    private function number(int $n): ?int
    {
        $passage = $this->passages[$n - 1] ?? null;
        if (null === $passage) {
            return null;
        }
        $url = (string) ($passage['sourceUrl'] ?? $passage['filename'] ?? '');
        $this->groups[$url] ??= [
            'url' => $url,
            'title' => $passage['title'] ?? null,
            'locators' => [],
            // An upload without a web address (only its filename): clients show it as text, not as a link.
            'synthetic' => 1 !== preg_match('#^https?://#i', $url),
            'last_verified_at' => $passage['checkedAt'] ?? null,
            'last_changed_at' => $passage['changedAt'] ?? null,
        ];
        $locator = ['link' => $passage['link'] ?? $url, 'quote' => (string) ($passage['quote'] ?? ''), 'page' => $passage['page'] ?? null];
        if (!\in_array($locator, $this->groups[$url]['locators'], true)) {
            $this->groups[$url]['locators'][] = $locator;
        }

        return $this->numberOf[$url] ??= \count($this->numberOf) + 1;
    }
}

// - revised 2026-11-24
// - revised 2026-11-24
