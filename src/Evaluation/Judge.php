<?php

// file generated with AI assistance: Claude Code - 2026-10-03 20:34:44 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Evaluation;

use Dmstr\NeuronAiBundle\Agent\AgentReferenceResolver;
use Dmstr\NeuronAiBundle\Agent\VersionTurnMapper;
use Dmstr\NeuronAiBundle\Chat\ChatService;
use Dmstr\NeuronAiBundle\Conversation\Gate\GateClose;
use Dmstr\NeuronAiBundle\Conversation\MessageKind;
use Dmstr\NeuronAiBundle\Conversation\MessageStatus;
use Dmstr\NeuronAiBundle\Entity\AgentVersion;
use Dmstr\NeuronAiBundle\Entity\Conversation;
use Dmstr\NeuronAiBundle\Entity\EvalResult;
use Dmstr\NeuronAiBundle\Entity\Message;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * The AI judge of evaluation results: a platform agent, named
 * by the setting {@see self::SETTING}, whose current published version
 * compares the actual answer of a result's conversation with the
 * question's expected answer.
 *
 * {@see self::judge()}:
 * 1. {@see AgentReferenceResolver::publishedVersion()} of the setting (at
 *    call time: a judge disabled since the evaluation was requested fails
 *    here);
 * 2. {@see VersionTurnMapper::map()} of that version (default language, no
 *    variables);
 * 3. {@see ChatService::chatConfigured()} with ONE user message
 *    ({@see self::prompt()}): question, expected answer, the transcript of
 *    the result's conversation ({@see self::transcript()}) and the verdict
 *    schema, so a judge version without a response schema of its own still
 *    knows the expected format. The judge call runs without answer gates
 *    (`gates: false`), even when the judge version has
 *    them on: the transcript under judgement would trip the injection
 *    guard;
 * 4. {@see JudgeVerdict::fromChatResult()}: `schema_errors` of the
 *    version's own response schema, then the bundle's verdict schema
 *    ({@see VerdictSchema}), independent of the version's response schema.
 *
 * Without an {@see EvaluationSettingsInterface} implementation in the
 * application there is no judge (as with an empty setting).
 *
 * Nothing is written here; the caller stores the verdict. Every failure is
 * a {@see JudgeException} whose message is the evaluation's
 * `errorMessage`.
 */
final class Judge
{
    /** The setting that names the judge agent (section `evaluation`). */
    public const string SETTING = 'evaluation.judge_agent';

    public function __construct(
        private readonly ?EvaluationSettingsInterface $settings,
        private readonly AgentReferenceResolver $resolver,
        private readonly VersionTurnMapper $mapper,
        private readonly ChatService $chatService,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * The current published version of the configured judge agent.
     *
     * @throws UnprocessableEntityHttpException naming the setting, when it is empty or names an unknown, disabled or
     *                                          unpublished agent
     */
    public function judgeVersion(): AgentVersion
    {
        $reference = $this->settings?->judgeAgent();
        if (null === $reference) {
            throw new UnprocessableEntityHttpException(\sprintf('No judge agent is configured (setting "%s").', self::SETTING));
        }

        try {
            return $this->resolver->publishedVersion($reference);
        } catch (UnprocessableEntityHttpException $e) {
            throw new UnprocessableEntityHttpException(\sprintf('Judge agent of setting "%s": %s', self::SETTING, $e->getMessage()), $e);
        }
    }

    /**
     * Judges the result.
     *
     * @throws JudgeException when the judge is not runnable, the result has no conversation, the call fails or the
     *                        answer violates a schema
     */
    public function judge(EvalResult $result): JudgeVerdict
    {
        try {
            $version = $this->judgeVersion();
        } catch (UnprocessableEntityHttpException $e) {
            throw new JudgeException($e->getMessage(), 0, $e);
        }

        $conversation = $result->getConversation()
            ?? throw new JudgeException(\sprintf('Evaluation result "%s" has no conversation (any more) to judge.', $result->getId()));
        $question = $result->getEvalQuestion();
        $content = self::prompt($question->getQuestion(), $question->getExpectedAnswer(), self::transcript($this->records($conversation)));

        $started = hrtime(true);
        try {
            $configuration = $this->mapper->map($version);
            // Never the answer gates of the judge version.
            $answer = $this->chatService->chatConfigured([['role' => 'user', 'content' => $content]], $configuration, gates: false);
        } catch (\Throwable $e) {
            throw new JudgeException(JudgeVerdict::limit(\sprintf(
                'The judge call (version %d of agent "%s") failed: %s',
                $version->getVersionNumber(),
                $version->getAgent()->getSlug(),
                '' !== $e->getMessage() ? $e->getMessage() : $e::class,
            )), 0, $e);
        }
        $durationMs = intdiv(hrtime(true) - $started, 1_000_000);

        return JudgeVerdict::fromChatResult($answer, $version, $durationMs, $configuration->providerModel);
    }

    /**
     * The user message of the judge call.
     */
    public static function prompt(string $question, string $expectedAnswer, string $transcript): string
    {
        return implode("\n\n", [
            'Evaluate how well the assistant answered the evaluation question below. Compare the actual answer in the conversation transcript with the expected answer: pass = correct and complete, mostly_pass = correct with minor gaps, mostly_fail = substantial errors or omissions, fail = wrong, missing or misleading. Add suggestions of type "data" for gaps in the knowledge base (documents, collections, tools) and of type "prompt" for changes to the assistant\'s instructions; leave the list empty when nothing should change.',
            "## Question\n".$question,
            "## Expected answer\n".$expectedAnswer,
            "## Conversation transcript\n".('' !== $transcript ? $transcript : '(no messages)'),
            "## Answer format\nRespond ONLY with a single JSON object, no prose, that validates against this JSON schema:\n".VerdictSchema::json(),
        ]);
    }

    /**
     * Plain-text transcript of a conversation's records, in order: user
     * messages, assistant answers (`text`, `schema`, `rule_answer`,
     * `rule_refusal`), tool calls with input and result, and errors.
     * Reasoning and `limit` records are left out. An answer gate refusal
     * (`injection_refusal`, `unanswerable_refusal`) is the refusal text
     * followed by the refusing gate and its internal reason from
     * `providerPayload.gates` ({@see refusalNote()}), so the judge can tell
     * a refusal from a failed answer; the reason is never in
     * `message:read`.
     *
     * @param iterable<Message> $records ordered by sequence and step
     */
    public static function transcript(iterable $records): string
    {
        $lines = [];
        foreach ($records as $record) {
            $kind = $record->getKind();
            if (MessageKind::REASONING === $kind || MessageKind::LIMIT === $kind) {
                continue;
            }

            if (MessageKind::TOOL_CALL === $kind) {
                $lines[] = \sprintf(
                    'Tool call %s(%s)%s: %s',
                    (string) $record->getToolName(),
                    json_encode($record->getToolInput() ?? new \stdClass(), \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '{}',
                    $record->isToolError() ? ' failed' : '',
                    (string) $record->getToolResult(),
                );

                continue;
            }

            $speaker = Message::SPEAKER_USER === $record->getRole() ? 'User' : 'Assistant';
            $content = (string) $record->getContent();
            if (MessageStatus::ERROR === $record->getStatus()) {
                $content = trim($content.' [error: '.(string) $record->getError().']');
            }
            if (GateClose::isRefusalKind($kind)) {
                $content = trim($content.' '.self::refusalNote($record));
            }
            $lines[] = $speaker.': '.$content;
        }

        return implode("\n\n", $lines);
    }

    /**
     * The note on a gate refusal record in the transcript:
     * `[refused by the <gate> gate: <reason>]`, `[refused by the <gate>
     * gate]` without a reason.
     */
    private static function refusalNote(Message $record): string
    {
        $gate = $record->getProviderPayload()['gate'] ?? null;
        $gate = \is_string($gate) ? $gate : (GateClose::gateOf($record->getKind()) ?? $record->getKind());
        $reason = $record->getProviderPayload()['gates'][$gate]['reason'] ?? null;

        return \is_string($reason) && '' !== trim($reason)
            ? \sprintf('[refused by the %s gate: %s]', $gate, trim($reason))
            : \sprintf('[refused by the %s gate]', $gate);
    }

    /**
     * @return list<Message>
     */
    private function records(Conversation $conversation): array
    {
        return array_values($this->entityManager->getRepository(Message::class)->findBy(
            ['conversation' => $conversation],
            ['sequence' => 'ASC', 'step' => 'ASC'],
        ));
    }
}
