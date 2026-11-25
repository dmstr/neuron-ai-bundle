<?php

// file generated with AI assistance: Claude Code - 2026-10-03 18:59:07 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Agent\Template;

use Dmstr\NeuronAiBundle\Agent\InstructionTemplate;

/**
 * The output templates of an agent version (candidate key `templates`): texts
 * per template name and language tag, looked up with a fallback to the
 * default language and rendered leniently ({@see TemplateRenderer}).
 *
 * Template names are free, except the reserved slots ({@see RESERVED}),
 * which the bundle fills itself and a rule must not reference.
 * The placeholders a template may use besides the common ones (`date`,
 * `language`, `agentName`, the properties of `startSchema`) depend on where
 * it is used ({@see contextPlaceholders()}).
 *
 * The slot `frame` wraps the final answer: its text holds `{{answer}}`
 * exactly once (checked when publishing, {@see answerCount()}) and is
 * delivered split at it ({@see frame()}), so the stored answer stays
 * unframed.
 *
 * The slots `injectionRefusal` and `unanswerableRefusal` are
 * the refusal texts of the answer gates
 * ({@see \Dmstr\NeuronAiBundle\Conversation\Gate\TurnGates}); they
 * supply no placeholders of their own, and publishing requires a
 * default-language text for each gate that is switched on.
 */
final readonly class TemplateSet
{
    /** Reserved slot names (filled by the bundle, the refusal slots by the answer gates); a rule must not reference them. */
    public const array RESERVED = ['frame', 'limitReason', 'turnLimitReason', 'forcedCloseFallback', self::INJECTION_REFUSAL, self::UNANSWERABLE_REFUSAL];

    /** Placeholders a reserved slot supplies, by slot name. */
    public const array SLOT_PLACEHOLDERS = [
        'frame' => ['answer'],
        'limitReason' => ['toolCalls', 'maxToolCalls'],
        'turnLimitReason' => ['turn', 'maxUserTurns'],
        'forcedCloseFallback' => [],
        self::INJECTION_REFUSAL => [],
        self::UNANSWERABLE_REFUSAL => [],
    ];

    /** The slot that wraps the final answer ({@see frame()}). */
    public const string FRAME = 'frame';

    /** The refusal text of the prompt-injection guard. */
    public const string INJECTION_REFUSAL = 'injectionRefusal';

    /** The refusal text of the answerability check. */
    public const string UNANSWERABLE_REFUSAL = 'unanswerableRefusal';

    /** The placeholder of {@see FRAME} that stands for the answer. */
    public const string ANSWER = 'answer';

    /** Placeholders a rule supplies to the template it references. */
    public const array RULE_PLACEHOLDERS = ['rule', 'outcome'];

    /**
     * @param array<string, array<string, string>> $templates name => (language tag => text), as in the candidate key `templates`
     */
    public function __construct(
        private array $templates = [],
        private string $defaultLanguage = '',
    ) {
    }

    /**
     * The context placeholders of a template: those of its slot for a
     * reserved name, {@see RULE_PLACEHOLDERS} for any other name.
     *
     * @return list<string>
     */
    public static function contextPlaceholders(string $name): array
    {
        return self::SLOT_PLACEHOLDERS[$name] ?? self::RULE_PLACEHOLDERS;
    }

    public static function isReserved(string $name): bool
    {
        return \in_array($name, self::RESERVED, true);
    }

    public function has(string $name): bool
    {
        return isset($this->templates[$name]);
    }

    /**
     * Text for $language, else the default-language text, else null.
     */
    public function text(string $name, string $language): ?string
    {
        $texts = $this->templates[$name] ?? null;
        if (null === $texts) {
            return null;
        }

        $text = $texts[$language] ?? $texts[$this->defaultLanguage] ?? null;

        return null === $text ? null : (string) $text;
    }

    /**
     * {@see text()} rendered with {@see TemplateRenderer}; null when the
     * template does not exist (or has neither the language nor the default
     * language).
     *
     * @param array<string, string> $values placeholder name => value
     */
    public function render(string $name, string $language, array $values): ?string
    {
        $text = $this->text($name, $language);

        return null === $text ? null : TemplateRenderer::render($text, $values);
    }

    /**
     * How often $text holds the {@see ANSWER} placeholder (as matched by
     * {@see InstructionTemplate::PLACEHOLDER}, whitespace inside the braces
     * allowed); a `frame` text must hold it exactly once.
     */
    public static function answerCount(string $text): int
    {
        preg_match_all(InstructionTemplate::PLACEHOLDER, $text, $matches);

        return \count(array_keys($matches[1], self::ANSWER, true));
    }

    /**
     * The version's `frame` template for $language (fallback: default
     * language), every placeholder except `answer` rendered leniently with
     * $values, split at its single `{{answer}}` (as matched by
     * InstructionTemplate::PLACEHOLDER, whitespace inside the braces
     * allowed). Null when there is no `frame` template or the text does not
     * contain exactly one `answer` placeholder.
     *
     * Prefix and suffix are rendered separately, so a value (even one that
     * contains `{{answer}}`) never moves the split; either may be empty.
     *
     * @param array<string, string> $values
     *
     * @return array{prefix: string, suffix: string}|null
     */
    public function frame(string $language, array $values): ?array
    {
        $text = $this->text(self::FRAME, $language);
        if (null === $text || 1 !== self::answerCount($text)) {
            return null;
        }

        preg_match_all(InstructionTemplate::PLACEHOLDER, $text, $matches, \PREG_OFFSET_CAPTURE);
        foreach ($matches[1] as $position => [$name]) {
            if (self::ANSWER !== $name) {
                continue;
            }
            [$placeholder, $offset] = $matches[0][$position];
            unset($values[self::ANSWER]);

            return [
                'prefix' => TemplateRenderer::render(substr($text, 0, $offset), $values),
                'suffix' => TemplateRenderer::render(substr($text, $offset + \strlen($placeholder)), $values),
            ];
        }

        return null;
    }
}
