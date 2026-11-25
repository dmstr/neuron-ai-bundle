<?php

// file generated with AI assistance: Claude Code - 2026-10-01 22:20:14 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Controller;

use ApiPlatform\Metadata\Resource\Factory\ResourceMetadataCollectionFactoryInterface;
use Dmstr\NeuronAiBundle\Anonymous\ConversationLimitException;
use Dmstr\NeuronAiBundle\Conversation\StreamingTurnService;
use Dmstr\NeuronAiBundle\Conversation\StreamResumer;
use Dmstr\NeuronAiBundle\Entity\Conversation;
use Dmstr\NeuronAiBundle\Replay\ConversationReplayGuard;
use Dmstr\NeuronAiBundle\Security\ConversationSecurity;
use Dmstr\NeuronAiBundle\State\InputBodyValidator;
use Dmstr\NeuronAiBundle\Stream\SseWriter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Server-sent event delivery of a conversation's turn:
 *
 * - `POST /api/conversations/{id}/stream` runs a streamed turn
 *   (`neuronai:conversation:turn_own`, body as `POST /api/conversations/{id}/messages`:
 *   `content`, optional `responseSchema`, `maxToolCalls` and
 *   `terminalTools`);
 * - `GET /api/conversations/{id}/stream` resumes the stream after the
 *   `Last-Event-ID` header (`neuronai:conversation:read_own`).
 *
 * Every client error (404, 403, 400, 409, 422) is answered as a regular
 * JSON error response before the first byte of the stream: the turn's
 * preflight ({@see StreamingTurnService::begin()}) runs before the
 * response is created. A turn the gate refuses
 * ({@see ConversationLimitException}) gets the same problem document as on
 * `POST /api/conversations/{id}/messages` (`type`, `title`, `detail`,
 * `status`, `code`): this route is no API Platform operation, so API
 * Platform's error listener leaves it to Symfony's generic problem
 * normalizer, which drops `code`; the controller serializes the exception
 * itself with the normalization context of the error resource's problem
 * operation. The streamed callback ignores client aborts, so a
 * disconnected client never stops a turn; it is resumed or cancelled
 * instead (`POST /api/conversations/{id}/cancel`).
 *
 * A replay conversation whose replay is still pending or running refuses
 * the streamed turn with the same problem document (code
 * `conversation_closed`, {@see ConversationReplayGuard}). The guard is
 * built from the entity manager unless one is injected, so the service
 * definition needs no extra argument.
 *
 * These are plain Symfony routes (bundle `config/routes.php`), not API
 * Platform operations: they do not appear in the OpenAPI document.
 */
final class ConversationStreamController
{
    public const string HEADER_LAST_EVENT_ID = 'Last-Event-ID';

    /** Problem operation of the {@see ConversationLimitException} error resource. */
    private const string PROBLEM_OPERATION = '_api_conversation_errors_problem';

    /** Headers of every stream response; `X-Accel-Buffering` stops proxy buffering. */
    private const array STREAM_HEADERS = [
        'Content-Type' => 'text/event-stream',
        'Cache-Control' => 'private, no-cache',
        'X-Accel-Buffering' => 'no',
    ];

    private readonly ConversationReplayGuard $replayGuard;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
        private readonly InputBodyValidator $inputBodyValidator,
        private readonly StreamingTurnService $turnService,
        private readonly StreamResumer $resumer,
        private readonly SerializerInterface $serializer,
        private readonly ResourceMetadataCollectionFactoryInterface $resourceMetadataFactory,
        ?ConversationReplayGuard $replayGuard = null,
    ) {
        $this->replayGuard = $replayGuard ?? new ConversationReplayGuard($entityManager);
    }

    /**
     * Runs one streamed turn of the conversation.
     *
     * @throws NotFoundHttpException     unknown conversation or malformed id (404)
     * @throws AccessDeniedHttpException without `neuronai:conversation:turn_own` (403)
     */
    public function stream(Request $request, string $id): Response
    {
        $conversation = $this->conversation($id, ConversationSecurity::TURN);

        $input = $this->inputBodyValidator->validate(Conversation::OPERATION_TURN, $request->getContent());
        /** @var array<string, mixed>|null $responseSchema the schema requires an object */
        $responseSchema = $input['responseSchema'] ?? null;

        // The schema admits integers only; JSON `3.0` decodes as float.
        $maxToolCalls = $input['maxToolCalls'] ?? null;

        /** @var list<string>|null $terminalTools the schema requires unique non-empty strings */
        $terminalTools = $input['terminalTools'] ?? null;

        // 409 and 422 (also for an unknown terminal tool or a running
        // replay) are thrown here, before anything is sent.
        try {
            $this->replayGuard->assertAcceptsManualTurn($conversation);
            $turn = $this->turnService->begin($conversation, (string) $input['content'], $responseSchema, null === $maxToolCalls ? null : (int) $maxToolCalls, $terminalTools);
        } catch (ConversationLimitException $e) {
            return $this->limitProblem($e);
        }

        return new StreamedResponse(static function () use ($turn): void {
            ignore_user_abort(true);
            set_time_limit(0);
            $turn->run(new SseWriter());
        }, Response::HTTP_OK, self::STREAM_HEADERS);
    }

    /**
     * Resumes the conversation's stream after `Last-Event-ID` (from the
     * first stored event when the header is absent).
     *
     * @throws NotFoundHttpException     unknown conversation, malformed id, or nothing to resume (404)
     * @throws AccessDeniedHttpException without `neuronai:conversation:read_own` (403)
     * @throws BadRequestHttpException   malformed `Last-Event-ID` (400)
     */
    public function resume(Request $request, string $id): Response
    {
        $conversation = $this->conversation($id, ConversationSecurity::READ);
        $lastEventId = $this->lastEventId($request);

        if (!$this->resumer->canResume($conversation)) {
            throw new NotFoundHttpException('The conversation has no stream to resume.');
        }

        $resumer = $this->resumer;

        return new StreamedResponse(static function () use ($resumer, $conversation, $lastEventId): void {
            ignore_user_abort(true);
            set_time_limit(0);
            $resumer->resume($conversation, $lastEventId, new SseWriter());
        }, Response::HTTP_OK, self::STREAM_HEADERS);
    }

    /**
     * The 422 problem document of a refused turn, serialized like API
     * Platform's error handling does for the error resource
     * ({@see ConversationLimitException}, operation
     * `_api_conversation_errors_problem`), so the field set is the same as
     * on the turn operation.
     */
    private function limitProblem(ConversationLimitException $exception): Response
    {
        $operation = $this->resourceMetadataFactory->create(ConversationLimitException::class)->getOperation(self::PROBLEM_OPERATION);
        $context = ($operation->getNormalizationContext() ?? []) + [
            'resource_class' => ConversationLimitException::class,
            'operation' => $operation,
            'operation_name' => self::PROBLEM_OPERATION,
            'api_error_resource' => true,
        ];

        return new Response(
            $this->serializer->serialize($exception, 'json', $context),
            $exception->getStatusCode(),
            ['Content-Type' => 'application/problem+json'] + $exception->getHeaders(),
        );
    }

    private function conversation(string $id, string $expression): Conversation
    {
        $conversation = Uuid::isValid($id) ? $this->entityManager->find(Conversation::class, Uuid::fromString($id)) : null;
        if (!$conversation instanceof Conversation) {
            throw new NotFoundHttpException('Conversation not found.');
        }
        if (!$this->authorizationChecker->isGranted(new Expression($expression), $conversation)) {
            throw new AccessDeniedHttpException('Access denied.');
        }

        return $conversation;
    }

    /**
     * Absent header: null; otherwise a non-negative decimal integer.
     *
     * @throws BadRequestHttpException for anything else
     */
    private function lastEventId(Request $request): ?int
    {
        $header = $request->headers->get(self::HEADER_LAST_EVENT_ID);
        if (null === $header) {
            return null;
        }

        $header = trim($header);
        if (1 !== preg_match('/^\d{1,18}$/', $header)) {
            throw new BadRequestHttpException(\sprintf('The %s header must be a non-negative integer.', self::HEADER_LAST_EVENT_ID));
        }

        return (int) $header;
    }
}
