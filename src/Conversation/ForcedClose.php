<?php

// file generated with AI assistance: Claude Code - 2026-10-02 06:02:17 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Conversation;

use Dmstr\NeuronAiBundle\Provider\ToolChoice;
use Dmstr\NeuronAiBundle\Provider\ToolChoiceAwareProviderInterface;
use NeuronAI\Agent\InferenceRequest;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\SystemMessage;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Tools\ToolRegistry;

/**
 * Values of the forced close of a turn: of a bounded tool loop
 * ({@see ToolCallBoundMiddleware}: once a turn has executed its bound of
 * tool calls, the next inference is forced to answer) and of the last
 * allowed user turn of a conversation ({@see UserTurnLimitMiddleware}: the
 * turn is forced from its first inference, so it runs no tool loop).
 *
 * The forced step is armed by {@see arm()} in one of three modes (the
 * `limit.mode` values; extensible, not a boolean):
 *
 * - {@see MODE_TERMINAL_TOOL}: the request names terminal tools and the
 *   provider supports a targeted choice: the step is sent with the tool
 *   choice `tool:<first terminal name>`, so the model answers through that
 *   terminal tool ({@see TerminalClose});
 * - {@see MODE_TOOL_CHOICE}: the provider supports `none`: the tools stay
 *   in the request (the earlier tool calls stay valid), the model must
 *   answer with text, and the instruction is appended to the system prompt
 *   as in the fallback, because a model stopped by `none` without being
 *   told why may answer with an empty text (this one step then misses the
 *   cached prefix after the tools);
 * - {@see MODE_FALLBACK}: without tools and with {@see INSTRUCTION}
 *   (the bound) or {@see TURN_LIMIT_INSTRUCTION} (the last user turn)
 *   appended to the system prompt.
 *
 * The text answer of a forced step carries {@see REASON_TURN_LIMIT} on the
 * last user turn, else {@see REASON_BOUND} as its close reason; a forced
 * step that ends in a terminal call carries {@see TerminalClose::REASON}
 * (precedence: terminal tool > user-turn limit > bound).
 *
 * {@see limitReason()} and {@see turnLimitReason()} are the default texts
 * of the `limit` records and events: an agent version may replace them
 * with its templates `limitReason` and `turnLimitReason`, and may answer a
 * failed or empty forced close with its template `forcedCloseFallback`
 * ({@see TurnTexts}, carried by {@see TurnRequest::$texts}). The
 * instructions of the fallback mode ({@see INSTRUCTION},
 * {@see TURN_LIMIT_INSTRUCTION}) are not templated.
 */
final class ForcedClose
{
    public const string INSTRUCTION = 'The tool-call limit of this turn is reached. Do not call any further tool; answer the user now with the information you already have.';

    /** `neuron_message.close_reason` of the answer of a forced step. */
    public const string REASON_BOUND = 'forced_bound';

    /**
     * Appended to the system prompt of the last allowed user turn in
     * fallback mode. It also says that the user cannot reply, so the model
     * does not end with a question.
     */
    public const string TURN_LIMIT_INSTRUCTION = 'This is the last turn of this conversation; the user cannot reply again. Do not call any tool; give a complete, final answer now.';

    /**
     * `neuron_message.close_reason` of the forced text answer of the last
     * allowed user turn.
     */
    public const string REASON_TURN_LIMIT = 'user_turn_limit';

    public const string MODE_TOOL_CHOICE = 'tool_choice';
    public const string MODE_FALLBACK = 'fallback';
    public const string MODE_TERMINAL_TOOL = 'terminal_tool';

    /**
     * The content of the `limit` record of a turn; the default of
     * {@see TurnTexts::limitReason()}.
     */
    public static function limitReason(int $calls, int $bound): string
    {
        return sprintf('Tool-call limit of %d reached after %d calls; the model was asked to answer.', $bound, $calls);
    }

    /**
     * The content of the `limit` record of the last allowed user turn; the
     * default of {@see TurnTexts::turnLimitReason()}.
     *
     * @param int $turn the 1-based number of that turn ({@see UserTurnLimit::$turn})
     */
    public static function turnLimitReason(int $turn): string
    {
        return sprintf('User-turn limit of %d reached: this is the last turn of the conversation; the model was asked to answer.', $turn);
    }

    /**
     * Arms the next inference as the forced step and returns its mode:
     * `tool:<first name of $terminalTools>` when there are terminal tools
     * and the provider supports that choice ({@see MODE_TERMINAL_TOOL}),
     * else `none` when supported ({@see MODE_TOOL_CHOICE}), else the
     * fallback ({@see MODE_FALLBACK}: every tool is removed from the
     * segment's tool registry, so the inference offers none); with `none`
     * and in the fallback $instruction is added to the run's working
     * instructions as a text block of its own. The choice is set on the provider instance; the
     * caller clears it after the run. When
     * $recorder is given, the limit is reported to it
     * ({@see TurnRecorder::limitReached()} with $calls and $bound).
     *
     * Idempotent per run: the choice is set again, the tools are removed
     * again, and $instruction is added only when the instructions do not
     * contain it yet, i.e. once, however often the same request is armed
     * (the user-turn limit arms before every inference of its turn).
     *
     * Reusable outside the tool node: {@see UserTurnLimitMiddleware} arms
     * the inference node from the first inference with
     * {@see TURN_LIMIT_INSTRUCTION}.
     *
     * @param InferenceRequest    $request       the run's working request (`AgentState::$request`; its instructions are changed in place for the fallback)
     * @param ToolRegistry        $tools         the segment's tools (`AgentResources::$tools`; emptied for the fallback)
     * @param AIProviderInterface $provider      the provider set on the agent
     * @param list<string>        $terminalTools terminal tool names in request order
     * @param string              $instruction   the instruction of the fallback: {@see INSTRUCTION} (the bound) or {@see TURN_LIMIT_INSTRUCTION} (the last user turn)
     */
    public static function arm(InferenceRequest $request, ToolRegistry $tools, AIProviderInterface $provider, array $terminalTools = [], ?TurnRecorder $recorder = null, ?int $calls = null, ?int $bound = null, string $instruction = self::INSTRUCTION): string
    {
        $aware = $provider instanceof ToolChoiceAwareProviderInterface ? $provider : null;
        $terminal = [] !== $terminalTools ? ToolChoice::tool((string) reset($terminalTools)) : null;

        if (null !== $aware && null !== $terminal && $aware->supportsToolChoice($terminal)) {
            $aware->setToolChoice($terminal);
            $mode = self::MODE_TERMINAL_TOOL;
        } elseif (null !== $aware && $aware->supportsToolChoice(ToolChoice::none())) {
            $aware->setToolChoice(ToolChoice::none());
            // Without the instruction a model stopped mid-research by `none`
            // may answer with an empty text.
            self::addInstruction($request, $instruction);
            $mode = self::MODE_TOOL_CHOICE;
        } else {
            foreach ($tools->all() as $tool) {
                $name = $tool->getName();
                if (null !== $name) {
                    $tools->remove($name);
                }
            }
            self::addInstruction($request, $instruction);
            $mode = self::MODE_FALLBACK;
        }

        if (null !== $recorder && null !== $calls && null !== $bound) {
            $recorder->limitReached($calls, $bound, $mode);
        }

        return $mode;
    }

    /**
     * Adds $instruction to the run's working instructions as a text block of
     * its own (SystemMessage::getContent() joins the blocks with a blank
     * line, as the 3.x string concatenation did), once.
     */
    private static function addInstruction(InferenceRequest $request, string $instruction): void
    {
        if ('' === trim((string) $request->instructions->getContent())) {
            $request->instructions = new SystemMessage($instruction);
        } elseif (!$request->instructions->contains($instruction)) {
            // A changed copy: the message of the earlier inferences (as a
            // provider or a recording may still hold it) stays as sent.
            $request->instructions = (clone $request->instructions)->addContent(new TextContent($instruction));
        }
    }
}

// - revised 2026-10-14
// - revised 2026-11-09
