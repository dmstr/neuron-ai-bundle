<?php

// file generated with AI assistance: Claude Code - 2026-10-03 18:59:07 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Agent\Rule;

/**
 * One compiled pre-answer rule of an agent version; internal to
 * {@see RuleSet}, built once per turn from the candidate shape of a rule
 * (`Entity/Agent/candidate.json`, key `rules`).
 *
 *  - `keywords`: every keyword `preg_quote()`d, joined as alternatives
 *    (any keyword matches), flags `iu` (Unicode simple case folding:
 *    `Ä` matches `ä`, `SS` does not match `ß`); with `wholeWord` the
 *    alternatives are enclosed in `(?<![\p{L}\p{N}])…(?![\p{L}\p{N}])`;
 *  - `pattern`: the stored pattern between `/` delimiters (unescaped `/`
 *    escaped) with the stored flags and `u` always on
 *    ({@see compilePattern()});
 *  - `language`: matches when the effective language is NOT one of
 *    `languages`; no regular expression.
 *
 * The input is matched whole, without truncation; PCRE runs with the
 * runtime's `pcre.backtrack_limit` and `pcre.jit` settings.
 */
final readonly class Rule
{
    public const string CONTINUE = 'continue';

    /**
     * @param list<string> $languages allowed languages of a `language` rule
     */
    private function __construct(
        public int $index,
        public ?string $name,
        public string $type,
        public ?string $regex,
        public array $languages,
        public string $outcome,
        public ?string $template,
    ) {
    }

    /**
     * @param array<string, mixed> $rule one entry of the candidate key `rules`
     */
    public static function fromArray(int $index, array $rule): self
    {
        /** @var array<string, mixed> $match */
        $match = $rule['match'];
        $type = (string) $match['type'];

        $regex = match ($type) {
            'keywords' => self::compileKeywords(
                array_map(strval(...), $match['keywords'] ?? []),
                (bool) ($match['wholeWord'] ?? true),
            ),
            'pattern' => self::compilePattern((string) ($match['pattern'] ?? ''), (string) ($match['flags'] ?? '')),
            default => null,
        };

        return new self(
            index: $index,
            name: isset($rule['name']) ? (string) $rule['name'] : null,
            type: $type,
            regex: $regex,
            languages: 'language' === $type ? array_values(array_map(strval(...), $match['languages'] ?? [])) : [],
            outcome: (string) $rule['outcome'],
            template: isset($rule['template']) ? (string) $rule['template'] : null,
        );
    }

    /**
     * The PCRE of a `pattern` rule: `/` delimiters, every `/` not preceded
     * by a backslash escaped, the stored flags (`i`, `m`, `s`, `x`) and `u`.
     */
    public static function compilePattern(string $pattern, string $flags): string
    {
        return '/'.preg_replace('~(?<!\\\\)/~', '\/', $pattern).'/'.$flags.'u';
    }

    /**
     * The PCRE of a `keywords` rule.
     *
     * @param list<string> $keywords
     */
    public static function compileKeywords(array $keywords, bool $wholeWord): string
    {
        $alternatives = implode('|', array_map(static fn (string $keyword): string => preg_quote($keyword, '/'), $keywords));

        return $wholeWord
            ? '/(?<![\p{L}\p{N}])(?:'.$alternatives.')(?![\p{L}\p{N}])/iu'
            : '/(?:'.$alternatives.')/iu';
    }

    /**
     * Whether the rule matches the input in the effective language.
     *
     * @param string|null $error set to the PCRE error message when the
     *                           regular expression failed at runtime
     *                           (backtrack or JIT stack limit, invalid
     *                           UTF-8); the rule then does not match
     */
    public function matches(string $input, string $language, ?string &$error = null): bool
    {
        $error = null;

        if (null === $this->regex) {
            return !\in_array($language, $this->languages, true);
        }

        $result = @preg_match($this->regex, $input);
        if (false === $result || \PREG_NO_ERROR !== preg_last_error()) {
            $error = preg_last_error_msg();

            return false;
        }

        return 1 === $result;
    }

    public function isContinue(): bool
    {
        return self::CONTINUE === $this->outcome;
    }
}
