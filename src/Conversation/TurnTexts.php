<?php

// file generated with AI assistance: Claude Code - 2026-10-03 20:23:32 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Conversation;

use Dmstr\NeuronAiBundle\Agent\Template\TemplateSet;

/**
 * The texts the runner writes itself into a turn: the reason of a reached
 * tool-call bound (`limit` record and event), the reason of the last
 * allowed user turn (`limit` record with `limit: user_turns`, `turn_limit`
 * event) and the fallback answer of a failed or empty forced close
 * ({@see TurnRunner}, {@see TurnResult::$fallbackAnswer}).
 *
 * An agent version may replace them with its reserved templates
 * `limitReason`, `turnLimitReason` and `forcedCloseFallback`
 * ({@see TemplateSet::RESERVED}), rendered leniently in the effective
 * language (fallback: default language) with the caller and system values
 * plus the slot's own values. Without a template (and with the defaults of
 * the constructor, i.e. every non-agent path) the reasons are exactly
 * {@see ForcedClose::limitReason()} and {@see ForcedClose::turnLimitReason()}
 * and there is no fallback answer.
 *
 * The refusal texts of the answer gates (`injectionRefusal`,
 * `unanswerableRefusal`) are rendered the same way through
 * {@see slot()}; {@see Gate\TurnGates::refusalText()} adds the fallback.
 */
final readonly class TurnTexts
{
    /**
     * Defaults reproduce today's English texts and have no fallback.
     *
     * @param TemplateSet|null      $templates the version's output templates; null = none
     * @param string                $language  the effective language of the turn
     * @param array<string, string> $values    caller and system values from the mapper (placeholder name => value)
     */
    public function __construct(
        private ?TemplateSet $templates = null,
        private string $language = '',
        private array $values = [],
    ) {
    }

    /**
     * Rendered `limitReason` (toolCalls, maxToolCalls), else
     * ForcedClose::limitReason($calls, $bound) byte for byte.
     */
    public function limitReason(int $calls, int $bound): string
    {
        return $this->render('limitReason', ['toolCalls' => (string) $calls, 'maxToolCalls' => (string) $bound])
            ?? ForcedClose::limitReason($calls, $bound);
    }

    /**
     * Rendered `turnLimitReason` (turn, maxUserTurns), else
     * ForcedClose::turnLimitReason($turn) byte for byte.
     */
    public function turnLimitReason(int $turn, int $maxUserTurns): string
    {
        return $this->render('turnLimitReason', ['turn' => (string) $turn, 'maxUserTurns' => (string) $maxUserTurns])
            ?? ForcedClose::turnLimitReason($turn);
    }

    /**
     * Rendered `forcedCloseFallback`, or null when the version has none.
     */
    public function fallback(): ?string
    {
        return $this->render('forcedCloseFallback', []);
    }

    /**
     * Rendered reserved slot $name without slot values of its own (the
     * gate refusal slots), or null when the version has no such template.
     */
    public function slot(string $name): ?string
    {
        return $this->render($name, []);
    }

    /**
     * The slot values win over caller values of the same name.
     *
     * @param array<string, string> $slotValues
     */
    private function render(string $name, array $slotValues): ?string
    {
        return $this->templates?->render($name, $this->language, $slotValues + $this->values);
    }
}
