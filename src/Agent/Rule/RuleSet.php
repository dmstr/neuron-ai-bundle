<?php

// file generated with AI assistance: Claude Code - 2026-10-03 18:59:07 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Agent\Rule;

use Dmstr\NeuronAiBundle\Agent\Template\TemplateSet;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * The compiled pre-answer rules of an agent version, evaluated against the
 * user input of a turn before retrieval and before any model call.
 *
 * Rules are evaluated in list order; the first matching rule decides. A
 * `continue` rule ends the evaluation without an outcome (the turn runs as
 * usual), an `answer` or `refuse` rule yields a {@see RuleOutcome} with its
 * template rendered in the effective language (default-language text as
 * fallback). A regular expression that fails at runtime counts as no match,
 * is logged at warning level and evaluation continues with the next rule.
 */
final class RuleSet
{
    /**
     * Without arguments: the empty rule set, {@see evaluate()} always
     * returns null.
     *
     * @param list<Rule>            $rules
     * @param array<string, string> $values     placeholder values of the templates
     * @param array<string, mixed>  $logContext merged into the warning context
     */
    public function __construct(
        private readonly array $rules = [],
        private readonly TemplateSet $templates = new TemplateSet(),
        private readonly array $values = [],
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly array $logContext = [],
    ) {
    }

    /**
     * @param list<array<string, mixed>> $rules      the version's candidate key `rules` (as returned by AgentConfiguration)
     * @param array<string, string>      $values     placeholder values: caller variables + date, language, agentName
     * @param array<string, mixed>       $logContext merged into the warning context (e.g. agent slug, version number)
     */
    public static function fromConfiguration(array $rules, TemplateSet $templates, array $values, LoggerInterface $logger = new NullLogger(), array $logContext = []): self
    {
        $compiled = [];
        foreach (array_values($rules) as $index => $rule) {
            $compiled[] = Rule::fromArray($index, $rule);
        }

        return new self($compiled, $templates, $values, $logger, $logContext);
    }

    /**
     * The decision of the first matching rule: null for no match or a
     * `continue` rule. The template is rendered with the placeholder values
     * plus `rule` (the rule name, '' without one) and `outcome`, which take
     * precedence over values of the same name.
     */
    public function evaluate(string $input, string $language): ?RuleOutcome
    {
        foreach ($this->rules as $rule) {
            $error = null;
            $matched = $rule->matches($input, $language, $error);

            if (null !== $error) {
                $this->logger->warning('Rule {ruleIndex} failed at runtime and counts as no match: {pregError}', array_replace($this->logContext, [
                    'ruleIndex' => $rule->index,
                    'ruleName' => $rule->name,
                    'pregError' => $error,
                ]));
                continue;
            }
            if (!$matched) {
                continue;
            }
            if ($rule->isContinue()) {
                return null;
            }

            $template = (string) $rule->template;
            $text = $this->templates->render($template, $language, array_replace($this->values, [
                'rule' => $rule->name ?? '',
                'outcome' => $rule->outcome,
            ]));

            return new RuleOutcome(
                index: $rule->index,
                name: $rule->name,
                match: $rule->type,
                outcome: $rule->outcome,
                template: $template,
                text: $text ?? '',
                language: $language,
            );
        }

        return null;
    }

    public function isEmpty(): bool
    {
        return [] === $this->rules;
    }
}
