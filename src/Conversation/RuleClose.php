<?php

// file generated with AI assistance: Claude Code - 2026-10-03 19:14:38 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Conversation;

use Dmstr\NeuronAiBundle\Agent\Rule\RuleOutcome;

/**
 * The close reasons of a turn a pre-answer rule decided
 * ({@see \Dmstr\NeuronAiBundle\Agent\Rule\RuleSet::evaluate()}), sibling of
 * {@see TerminalClose}.
 *
 * Such a turn runs no model call and no retrieval: its only assistant
 * record is the rule record (kind {@see MessageKind::RULE_ANSWER} or
 * {@see MessageKind::RULE_REFUSAL}, see {@see TurnRecorder}), whose
 * `close_reason` is {@see REASON_ANSWER} or {@see REASON_REFUSAL}; the
 * `done` event of a streamed turn carries the same value as `closeReason`.
 */
final class RuleClose
{
    /** `neuron_message.close_reason` of the record of a turn a rule answered with a fixed text. */
    public const string REASON_ANSWER = 'rule_answer';

    /** `neuron_message.close_reason` of the record of a turn a rule refused. */
    public const string REASON_REFUSAL = 'rule_refusal';

    /**
     * The close reason (equal to the record kind) of a turn $outcome decided.
     */
    public static function reason(RuleOutcome $outcome): string
    {
        return $outcome->isRefusal() ? self::REASON_REFUSAL : self::REASON_ANSWER;
    }

    /**
     * The decision as stored under `providerPayload.rule` of the rule record.
     *
     * @return array{index: int, name: ?string, match: string, outcome: string, template: string, language: string}
     */
    public static function payload(RuleOutcome $outcome): array
    {
        return [
            'index' => $outcome->index,
            'name' => $outcome->name,
            'match' => $outcome->match,
            'outcome' => $outcome->outcome,
            'template' => $outcome->template,
            'language' => $outcome->language,
        ];
    }
}
