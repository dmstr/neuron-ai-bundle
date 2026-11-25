<?php

// file generated with AI assistance: Claude Code - 2026-10-03 22:15:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Dmstr\NeuronAiBundle\Agent\AgentConfiguration;
use Dmstr\NeuronAiBundle\Agent\AgentVersionPinner;
use Dmstr\NeuronAiBundle\Agent\StaleReferenceException;
use Dmstr\NeuronAiBundle\Conversation\ConversationStatus;
use Dmstr\NeuronAiBundle\Entity\AgentVersion;
use Dmstr\NeuronAiBundle\Entity\Conversation;
use Dmstr\NeuronAiBundle\Entity\ConversationReplay;
use Dmstr\NeuronAiBundle\Entity\Message;
use Dmstr\NeuronAiBundle\Replay\ConversationReplayStatus;
use Dmstr\SymfonyJobQueue\Service\JobQueueService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Uid\Uuid;

/**
 * `POST /conversations/{id}/replay` (`conversation_replay`):
 * creates the replay conversation and the {@see ConversationReplay} record
 * and queues the replay job.
 *
 * The operation has loaded the source conversation (`read: true`, 404 for
 * an unknown one; any owner, also anonymous and evaluation conversations)
 * and has checked `neuronai:eval:write`. The raw body is validated against
 * `Entity/Conversation/replay.input.json` through {@see InputBodyValidator}
 * (400 malformed JSON, 422 schema violation). Then, all answering 422
 * before anything is stored or queued:
 *  - the source has at least one user message;
 *  - `targetVersion` is an IRI ending in `/agent_versions/<uuid>` or a
 *    full UUID of an existing version (any version, also an older one;
 *    unlike the evaluation run start, which only takes the current one);
 *  - the version's agent is enabled;
 *  - pinning ({@see AgentVersionPinner::pin()}) accepts the effective
 *    variables and language and finds every reference of the version
 *    (422 `variables/<name>`, `language`, or the stale reference's path).
 *
 * Variables: `variables` of the body replaces the source's
 * set as a whole; omitted, the source's `variables` apply (null, e.g. a
 * conversation without an agent, becomes `{}`). Language: `language` of
 * the body; omitted, the source's language when the target version lists
 * it in `languages`, else null (the target's `defaultLanguage`).
 *
 * On success the replay conversation (owner = the caller, `origin`
 * `replay`, pinned to the version) and the record (sharing its id, status
 * `pending`, the source's user-message ids in sequence order frozen as
 * `sourceMessageIds`, `overrides` = the body's `variables` and `language`
 * as given, the effective `language`) are stored, a job of type
 * {@see ConversationReplay::JOB_TYPE} is queued with the input
 * `{"replay": "<uuid>", "ownerSubject": "<identifier>"}`, its id is stored
 * as `jobId`, and the record is returned (202). Creating the conversation
 * here, not in the job, keeps it exactly one per replay whatever the job
 * queue redelivers; its creation is audited under the caller.
 *
 * @implements ProcessorInterface<Conversation, ConversationReplay>
 */
final class ConversationReplayProcessor implements ProcessorInterface
{
    /** IRI of an agent version; the last segment is the id. */
    private const string IRI_PATTERN = '#/agent_versions/([^/?\#]+)$#';

    public function __construct(
        private readonly InputBodyValidator $inputBodyValidator,
        private readonly EntityManagerInterface $entityManager,
        private readonly AgentVersionPinner $pinner,
        private readonly JobQueueService $jobQueueService,
        private readonly TokenStorageInterface $tokenStorage,
    ) {
    }

    /**
     * @throws NotFoundHttpException            without a loaded source
     * @throws BadRequestHttpException          on malformed JSON
     * @throws UnprocessableEntityHttpException for a schema violation, a source without user messages, an unknown
     *                                          version, a disabled agent, invalid variables, an unsupported language
     * @throws StaleReferenceException          for a reference of the version that no longer resolves (422)
     * @throws AccessDeniedException            without an authenticated user
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ConversationReplay
    {
        if (!$data instanceof Conversation) {
            throw new NotFoundHttpException('Not Found');
        }

        $request = $context['request'] ?? null;
        $body = $request instanceof Request ? $request->getContent() : '';

        $input = $this->inputBodyValidator->validate(Conversation::OPERATION_REPLAY, $body);

        $owner = $this->ownerSubject();
        $messageIds = $this->sourceUserMessageIds($data);
        if ([] === $messageIds) {
            throw new UnprocessableEntityHttpException('The source conversation has no user message to replay.');
        }
        $version = $this->targetVersion((string) $input['targetVersion']);

        /** @var array<array-key, mixed> $variables the schema requires an object */
        $variables = \array_key_exists('variables', $input) ? $input['variables'] : ($data->getVariables() ?? []);
        $language = isset($input['language']) ? (string) $input['language'] : self::sourceLanguage($data, $version);

        $conversation = new Conversation();
        $this->pinner->pin($conversation, $version, $variables, $language);
        $conversation
            ->setOwnerSubject($owner)
            ->setOrigin(Conversation::ORIGIN_REPLAY)
            ->setStatus(ConversationStatus::ACTIVE)
            ->setNextSequence(1);

        $overrides = array_intersect_key($input, ['variables' => true, 'language' => true]);
        $replay = new ConversationReplay($conversation, $version, $owner)
            ->setSourceConversation($data)
            ->setSourceMessageIds($messageIds)
            ->setStatus(ConversationReplayStatus::PENDING)
            ->setLanguage($conversation->getLanguage())
            ->setOverrides([] === $overrides ? null : $overrides);

        $this->entityManager->persist($conversation);
        $this->entityManager->persist($replay);

        // createAndDispatch() flushes, so both rows exist before the message
        // is dispatched; no operation name: the job input is not a request
        // body of the replay schema.
        $job = $this->jobQueueService->createAndDispatch(
            ConversationReplay::JOB_TYPE,
            ['replay' => (string) $replay->getId(), 'ownerSubject' => $owner],
        );

        $replay->setJobId($job->getId());
        $this->entityManager->flush();

        return $replay;
    }

    /**
     * The referenced version, provided its agent is enabled; any published
     * version of the agent, not only the current one. A preview is refused:
     * a replay compares against a version that can actually serve chats.
     *
     * @throws UnprocessableEntityHttpException for an unknown version, a preview or a disabled agent
     */
    public function targetVersion(string $reference): AgentVersion
    {
        $version = $this->findVersion($reference)
            ?? throw new UnprocessableEntityHttpException(\sprintf('targetVersion: unknown agent version "%s".', $reference));
        if ($version->isPreview()) {
            throw new UnprocessableEntityHttpException(\sprintf('targetVersion: agent version "%s" is a preview; replay needs a published version.', $reference));
        }

        $agent = $version->getAgent();
        if (!$agent->isEnabled()) {
            throw new UnprocessableEntityHttpException(\sprintf('targetVersion: agent "%s" of version "%s" is disabled.', $agent->getSlug(), $reference));
        }

        return $version;
    }

    /**
     * IRI `/api/agent_versions/<uuid>` or full UUID; partial UUIDs are not
     * resolved.
     */
    private function findVersion(string $reference): ?AgentVersion
    {
        $id = trim($reference);
        if (str_starts_with($id, '/')) {
            $id = 1 === preg_match(self::IRI_PATTERN, $id, $matches) ? $matches[1] : '';
        }
        if (!Uuid::isValid($id)) {
            return null;
        }

        return $this->entityManager->find(AgentVersion::class, Uuid::fromString($id));
    }

    /**
     * Ids of the source's user messages in sequence order, including those
     * of aborted, failed and rule-refused turns.
     *
     * @return list<string>
     */
    private function sourceUserMessageIds(Conversation $source): array
    {
        $messages = $this->entityManager->getRepository(Message::class)->findBy(
            ['conversation' => $source, 'role' => Message::SPEAKER_USER],
            ['sequence' => 'ASC'],
        );

        return array_values(array_map(static fn (Message $message): string => (string) $message->getId(), $messages));
    }

    /**
     * The source's language when the target version supports it; null
     * (the target's default language) otherwise.
     */
    private static function sourceLanguage(Conversation $source, AgentVersion $version): ?string
    {
        $language = $source->getLanguage();
        if (null === $language) {
            return null;
        }

        return \in_array($language, AgentConfiguration::fromArray($version->getConfiguration())->languages, true) ? $language : null;
    }

    /**
     * @throws AccessDeniedException without an authenticated user
     */
    private function ownerSubject(): string
    {
        $token = $this->tokenStorage->getToken();
        $identifier = null !== $token?->getUser() ? $token->getUserIdentifier() : '';
        if ('' === $identifier) {
            throw new AccessDeniedException('Replaying a conversation requires an authenticated user.');
        }

        return $identifier;
    }
}

// - revised 2026-10-13
