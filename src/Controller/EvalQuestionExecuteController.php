<?php

// file generated with AI assistance: Claude Code - 2026-10-03 20:24:04 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Controller;

use Dmstr\NeuronAiBundle\Agent\AgentVersionPinner;
use Dmstr\NeuronAiBundle\Conversation\ConversationStatus;
use Dmstr\NeuronAiBundle\Conversation\MessageStatus;
use Dmstr\NeuronAiBundle\Conversation\StreamingTurnService;
use Dmstr\NeuronAiBundle\Entity\AgentVersion;
use Dmstr\NeuronAiBundle\Entity\Conversation;
use Dmstr\NeuronAiBundle\Entity\EvalQuestion;
use Dmstr\NeuronAiBundle\Entity\EvalResult;
use Dmstr\NeuronAiBundle\Entity\Message;
use Dmstr\NeuronAiBundle\Evaluation\BatchRunner;
use Dmstr\NeuronAiBundle\Evaluation\EvalQuestionStatus;
use Dmstr\NeuronAiBundle\Evaluation\EvalResultSource;
use Dmstr\NeuronAiBundle\Evaluation\EvalResultStatus;
use Dmstr\NeuronAiBundle\Evaluation\QuestionVariables;
use Dmstr\NeuronAiBundle\Evaluation\TurnMetrics;
use Dmstr\NeuronAiBundle\Security\Permissions;
use Dmstr\NeuronAiBundle\State\EvalRunStartProcessor;
use Dmstr\NeuronAiBundle\State\InputBodyValidator;
use Dmstr\NeuronAiBundle\Stream\SseWriter;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Single execution of one evaluation question with streaming:
 * `POST /api/eval_questions/{id}/execute`, body
 * `{"agentVersion": "<IRI /api/agent_versions/<uuid> or UUID>"}` or
 * `{"agent": "<IRI /api/agents/<uuid>, UUID or slug>", "candidate": true}`.
 *
 * Checks, all answered as JSON problem documents before the stream starts:
 *  - `neuronai:eval:write`, else 403;
 *  - the question exists, else 404 (also for a malformed id);
 *  - the body is validated against its own schema
 *    (`Entity/EvalQuestion/execute.input.json`, operation
 *    {@see EvalQuestion::OPERATION_EXECUTE}): only `agentVersion`, or
 *    `agent` with `candidate: true`, is accepted, so run options of
 *    `POST /eval_runs/start` such as `mode` are rejected; malformed JSON
 *    400, both or neither of the two shapes or any other property 422
 *    (both shapes with the message of
 *    {@see EvalRunStartProcessor::assertSingleVersionSource()});
 *  - the question is not archived, else 422;
 *  - the version is resolved exactly as at run start
 *    ({@see EvalRunStartProcessor::requestedVersion()}: 422 for an unknown
 *    version or agent, a disabled agent or a published version that is
 *    not the agent's current version; a preview of an enabled agent is
 *    accepted; with `candidate` the preview of the agent's candidate,
 *    422 at `candidate/<field>` for an invalid candidate);
 *  - pinning ({@see AgentVersionPinner}) with the question's
 *    `requestData` as variables ({@see QuestionVariables}) may answer 422
 *    (stale reference, invalid or missing variables), the turn's preflight
 *    ({@see StreamingTurnService::begin()}) 422 or 409; both leave a
 *    `failed` result (see below).
 *
 * Effect: a new {@see Conversation} owned by the caller (user identifier),
 * pinned to the version (the question's `requestData` as variables,
 * checked as for `POST /conversations` with `agent` and `variables`; the
 * default language) with `origin` `eval`, and an {@see EvalResult}
 * without run (`evalRun` null, `source` single, status `pending`) are
 * stored together, as the batch does it ({@see BatchRunner}) but without
 * the system actor: the audit actor of the creation and the turn is the
 * calling user. The question
 * text is streamed as the conversation's first turn with the same SSE
 * response as `POST /api/conversations/{id}/stream`
 * ({@see ConversationStreamController}). A single execution is never
 * measured: the start processor is used only to resolve the version,
 * without a mode.
 *
 * Result finalisation: {@see \Dmstr\NeuronAiBundle\Conversation\StreamingTurn::run()}
 * returns the final record, so after the stream the result becomes
 * `completed` (record `complete`) or `failed` (any other status, with the
 * record's error), with `durationMs` (creation, preflight and stream),
 * tokens and interactions read from the turn's records
 * ({@see TurnMetrics}). When the entity manager was closed by the turn,
 * status, error and duration are written over DBAL instead (no metrics).
 * A preflight failure after the creation leaves a `failed` result with the
 * exception message before the error response. A refused pinning (e.g.
 * `variables/vehicle: not declared by the agent version.`) leaves a
 * `failed` result without conversation, with the exception message, and
 * answers that 422 as well; no conversation is stored, as in the batch,
 * where the same refusal is a `failed` result of the question. A process
 * killed during the stream leaves the result `pending`.
 *
 * Like the stream routes, this is a plain Symfony route (bundle
 * `config/routes.php`), not an API Platform operation: it does not appear
 * in the OpenAPI document. The reverse proxy routes
 * `^/api/eval_questions/[^/]+/execute$` to the stream pool.
 */
final class EvalQuestionExecuteController
{
    /** Headers of the stream response, as {@see ConversationStreamController}. */
    private const array STREAM_HEADERS = [
        'Content-Type' => 'text/event-stream',
        'Cache-Control' => 'private, no-cache',
        'X-Accel-Buffering' => 'no',
    ];

    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly InputBodyValidator $inputBodyValidator,
        private readonly EvalRunStartProcessor $startProcessor,
        private readonly AgentVersionPinner $pinner,
        private readonly StreamingTurnService $turnService,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Runs the question as the first streamed turn of a new evaluation
     * conversation.
     *
     * @throws AccessDeniedHttpException        without `neuronai:eval:write` or an authenticated user (403)
     * @throws NotFoundHttpException            unknown question or malformed id (404)
     * @throws BadRequestHttpException          malformed JSON (400)
     * @throws UnprocessableEntityHttpException invalid body, archived question, version not startable, stale reference (422)
     */
    public function execute(Request $request, string $id): Response
    {
        if (!$this->authorizationChecker->isGranted(Permissions::EVAL_WRITE)) {
            throw new AccessDeniedHttpException('Access denied.');
        }
        $owner = $this->ownerSubject();

        $question = Uuid::isValid($id) ? $this->entityManager->find(EvalQuestion::class, Uuid::fromString($id)) : null;
        if (!$question instanceof EvalQuestion) {
            throw new NotFoundHttpException('Evaluation question not found.');
        }

        EvalRunStartProcessor::assertSingleVersionSource($request->getContent());
        $input = $this->inputBodyValidator->validate(EvalQuestion::OPERATION_EXECUTE, $request->getContent());

        if (EvalQuestionStatus::ARCHIVED === $question->getStatus()) {
            throw new UnprocessableEntityHttpException(\sprintf('Evaluation question "%s" is archived and cannot be executed.', $question->getKey()));
        }

        $version = $this->startProcessor->requestedVersion($input);

        $started = hrtime(true);
        [$conversation, $result] = $this->create($question, $version, $owner, $started);

        try {
            $turn = $this->turnService->begin($conversation, $question->getQuestion());
        } catch (\Throwable $e) {
            $this->fail($result, '' !== $e->getMessage() ? $e->getMessage() : $e::class, $started);

            throw $e;
        }

        return new StreamedResponse(function () use ($turn, $conversation, $result, $started): void {
            ignore_user_abort(true);
            set_time_limit(0);
            $final = $turn->run(new SseWriter());
            $this->finish($result, $conversation, $final, $started);
        }, Response::HTTP_OK, self::STREAM_HEADERS);
    }

    /**
     * The evaluation conversation of the caller on $version with the
     * question's `requestData` as variables and its pending result with
     * the fingerprint of the question ({@see EvalResult::fingerprintOf()}),
     * stored in one flush (audited as the caller).
     *
     * @return array{Conversation, EvalResult}
     *
     * @throws UnprocessableEntityHttpException when the pinning refuses (stale reference, invalid or missing
     *                                          variables), after storing a `failed` result without conversation
     */
    private function create(EvalQuestion $question, AgentVersion $version, string $owner, int $started): array
    {
        $result = new EvalResult()
            ->setEvalRun(null)
            ->setEvalQuestion($question)
            ->setSource(EvalResultSource::SINGLE)
            ->setQuestionFingerprint(EvalResult::fingerprintOf($question))
            ->setStatus(EvalResultStatus::PENDING);

        $conversation = new Conversation();
        try {
            $this->pinner->pin($conversation, $version, QuestionVariables::of($question));
        } catch (UnprocessableEntityHttpException $e) {
            // A per-question failure, recorded like a refused preflight and
            // like the batch records it; the conversation is not stored.
            $this->entityManager->persist($result);
            $this->fail($result, '' !== $e->getMessage() ? $e->getMessage() : $e::class, $started);

            throw $e;
        }
        $conversation
            ->setOwnerSubject($owner)
            ->setOrigin(Conversation::ORIGIN_EVAL)
            ->setStatus(ConversationStatus::ACTIVE)
            ->setNextSequence(1);
        $result->setConversation($conversation);

        $this->entityManager->persist($conversation);
        $this->entityManager->persist($result);
        $this->entityManager->flush();

        return [$conversation, $result];
    }

    /**
     * Final status, duration and metrics of the result after the stream;
     * never throws (the response is already sent).
     */
    private function finish(EvalResult $result, Conversation $conversation, Message $final, int $started): void
    {
        $error = MessageStatus::COMPLETE === $final->getStatus()
            ? null
            : ($final->getError() ?? \sprintf('The turn ended with status "%s".', $final->getStatus()));
        $error = null !== $error ? mb_substr($error, 0, BatchRunner::MAX_ERROR_LENGTH) : null;
        $durationMs = self::elapsedMs($started);

        try {
            if ($this->entityManager->isOpen()) {
                $metrics = TurnMetrics::fromRecords($this->entityManager->getRepository(Message::class)->findBy(['conversation' => $conversation]));
                $result
                    ->setStatus(null === $error ? EvalResultStatus::COMPLETED : EvalResultStatus::FAILED)
                    ->setErrorMessage($error)
                    ->setDurationMs($durationMs)
                    ->setTokensInput($metrics->tokensInput)
                    ->setTokensOutput($metrics->tokensOutput)
                    ->setInteractions($metrics->interactions);
                $this->entityManager->flush();

                return;
            }

            $this->updateRow($result, null === $error ? EvalResultStatus::COMPLETED : EvalResultStatus::FAILED, $error, $durationMs);
        } catch (\Throwable $e) {
            $this->logger->error('Could not finalise evaluation result {result}: {message}', [
                'result' => (string) $result->getId(),
                'message' => $e->getMessage(),
                'exception' => $e,
            ]);
        }
    }

    /**
     * Marks the result `failed` when the pinning or the preflight refused
     * the turn; a failure here is logged, the refusal's exception is what
     * the client gets.
     */
    private function fail(EvalResult $result, string $error, int $started): void
    {
        $error = mb_substr($error, 0, BatchRunner::MAX_ERROR_LENGTH);

        try {
            if ($this->entityManager->isOpen()) {
                $result
                    ->setStatus(EvalResultStatus::FAILED)
                    ->setErrorMessage($error)
                    ->setDurationMs(self::elapsedMs($started));
                $this->entityManager->flush();

                return;
            }

            $this->updateRow($result, EvalResultStatus::FAILED, $error, self::elapsedMs($started));
        } catch (\Throwable $e) {
            $this->logger->error('Could not mark evaluation result {result} as failed: {message}', [
                'result' => (string) $result->getId(),
                'message' => $e->getMessage(),
                'exception' => $e,
            ]);
        }
    }

    /**
     * DBAL fallback for a closed entity manager.
     */
    private function updateRow(EvalResult $result, string $status, ?string $error, int $durationMs): void
    {
        $this->entityManager->getConnection()->executeStatement(
            'UPDATE neuron_eval_result SET status = :status, error_message = :error, duration_ms = :duration, updated_at = NOW() WHERE id = :id',
            ['status' => $status, 'error' => $error, 'duration' => $durationMs, 'id' => $result->getId()->toRfc4122()],
        );
    }

    /**
     * @throws AccessDeniedHttpException without an authenticated user
     */
    private function ownerSubject(): string
    {
        $token = $this->tokenStorage->getToken();
        $identifier = null !== $token?->getUser() ? $token->getUserIdentifier() : '';
        if ('' === $identifier) {
            throw new AccessDeniedHttpException('Executing an evaluation question requires an authenticated user.');
        }

        return $identifier;
    }

    private static function elapsedMs(int $started): int
    {
        return intdiv(hrtime(true) - $started, 1_000_000);
    }
}

// - revised 2026-10-13
