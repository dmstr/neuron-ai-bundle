<?php

// file generated with AI assistance: Claude Code - 2026-10-04 12:12:07 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Conversation\Gate;

use Dmstr\NeuronAiBundle\Agent\Template\TemplateSet;
use Dmstr\NeuronAiBundle\Conversation\MessageKind;

/**
 * The close reasons of a turn an answer gate refused, sibling of
 * {@see \Dmstr\NeuronAiBundle\Conversation\RuleClose}.
 *
 * Such a turn runs no answer call: its only assistant record is the
 * refusal record of kind {@see MessageKind::INJECTION_REFUSAL} or
 * {@see MessageKind::UNANSWERABLE_REFUSAL}, whose `close_reason` is the
 * same string ({@see reason()}); the `done` event of a streamed turn
 * carries it as `closeReason`. The text is the gate's reserved template
 * slot ({@see slot()}), else {@see DEFAULT_TEXTS}.
 */
final class GateClose
{
    /** `neuron_message.close_reason` of the record of a turn the injection guard refused. */
    public const string REASON_INJECTION = MessageKind::INJECTION_REFUSAL;

    /** `neuron_message.close_reason` of the record of a turn the answerability check refused. */
    public const string REASON_UNANSWERABLE = MessageKind::UNANSWERABLE_REFUSAL;

    /** Close reason (and record kind) by refusing gate. */
    private const array REASONS = [
        GateOutcome::INJECTION => self::REASON_INJECTION,
        GateOutcome::ANSWERABILITY => self::REASON_UNANSWERABLE,
    ];

    /** Template slot of the refusal text by refusing gate. */
    private const array SLOTS = [
        GateOutcome::INJECTION => TemplateSet::INJECTION_REFUSAL,
        GateOutcome::ANSWERABILITY => TemplateSet::UNANSWERABLE_REFUSAL,
    ];

    /**
     * Refusal texts for a version without the slot. Publishing requires
     * the slot while the gate is on, so these only show for a version
     * stored otherwise.
     */
    public const array DEFAULT_TEXTS = [
        GateOutcome::INJECTION => 'I cannot process this request.',
        GateOutcome::ANSWERABILITY => 'I cannot answer this question from the available sources.',
    ];

    /**
     * The close reason, equal to the record kind, of a turn $gate refused.
     *
     * @throws \InvalidArgumentException for a gate that never refuses
     */
    public static function reason(string $gate): string
    {
        return self::REASONS[$gate] ?? throw new \InvalidArgumentException(\sprintf('The gate "%s" never refuses.', $gate));
    }

    /**
     * The reserved template slot of the refusal text of $gate.
     *
     * @throws \InvalidArgumentException for a gate that never refuses
     */
    public static function slot(string $gate): string
    {
        return self::SLOTS[$gate] ?? throw new \InvalidArgumentException(\sprintf('The gate "%s" never refuses.', $gate));
    }

    /**
     * Whether $kind is the kind of a gate refusal record.
     */
    public static function isRefusalKind(string $kind): bool
    {
        return \in_array($kind, self::REASONS, true);
    }

    /**
     * The gate whose refusal records have $kind; null for any other kind.
     */
    public static function gateOf(string $kind): ?string
    {
        $gate = array_search($kind, self::REASONS, true);

        return \is_string($gate) ? $gate : null;
    }

    /**
     * The refusing gate as reported in the `gate` field of the agent-chat
     * response: the gate, the kind (= close reason) and the template slot;
     * the reason stays internal.
     *
     * @return array{gate: string, kind: string, template: string}
     */
    public static function summary(GateOutcome $outcome): array
    {
        return [
            'gate' => $outcome->gate,
            'kind' => self::reason($outcome->gate),
            'template' => self::slot($outcome->gate),
        ];
    }
}
