<?php

// file generated with AI assistance: Claude Code - 2026-10-03 19:10:10 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use ApiPlatform\Validator\Exception\ValidationException;
use Dmstr\NeuronAiBundle\Agent\AgentConfiguration;
use Dmstr\NeuronAiBundle\Agent\AgentPreviewFactory;
use Dmstr\NeuronAiBundle\Agent\AgentReferenceResolver;
use Dmstr\NeuronAiBundle\Entity\AgentVersion;
use Dmstr\NeuronAiBundle\Entity\EvalQuestion;
use Dmstr\NeuronAiBundle\Entity\EvalRun;
use Dmstr\NeuronAiBundle\Evaluation\BatchRunner;
use Dmstr\NeuronAiBundle\Evaluation\EvalQuestionStatus;
use Dmstr\NeuronAiBundle\Evaluation\EvalRunStatus;
use Dmstr\SymfonyJobQueue\Service\JobQueueService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Uid\Uuid;

/**
 * `POST /eval_runs/start` (`eval_run_start`): creates an {@see EvalRun} and
 * queues its batch job.
 *
 * The operation has already checked `neuronai:eval:write`. A body that names
 * both `agentVersion` and `agent` or `candidate` answers 422 with a
 * message naming the keys ({@see self::assertSingleVersionSource()},
 * checked on the raw decoded body, before the schema, whose `oneOf` would
 * only say that no branch matched). The raw body is then validated against
 * `Entity/EvalRun/start.input.json` through {@see InputBodyValidator} (400
 * malformed JSON, 422 schema violation such as neither `agentVersion` nor
 * `agent` with `candidate: true`, `candidate: false` or an unknown
 * property). The version comes from one of two shapes
 * ({@see self::requestedVersion()}):
 *  - `agentVersion`: an IRI ending in `/agent_versions/<uuid>` or a full
 *    UUID of an existing version (anything else: unknown version) whose
 *    agent is enabled; a published version must be the agent's current
 *    version (a stale version is refused: a run evaluates what
 *    conversations get now), a preview is startable as long as its agent
 *    is enabled (the current-version rule does not apply to previews);
 *  - `agent` with `candidate: true`: the agent (IRI `/api/agents/<uuid>`,
 *    full UUID or slug, {@see AgentReferenceResolver}; unknown: 422) and
 *    the preview of its current candidate from
 *    {@see AgentPreviewFactory::previewFor()}, created by the caller's
 *    user identifier (422 for a disabled agent, 422 at `candidate/<field>`
 *    for an invalid candidate as on publish, 409 for a concurrent
 *    creation). The preview is created (or reused) before the checks
 *    below, so a start refused by them can leave a preview behind; the
 *    next start of the unchanged candidate reuses it.
 * Then, all answering 422 before a run or a job exists:
 *  - `retrievalOnly` is not combined with `mode` `agent`;
 *  - `mode` `retrieval` and `retrievalOnly` need a version with knowledge
 *    collections;
 *  - at least one question is `active`.
 *
 * Mode and retrieval-only work the same for previews and published
 * versions.
 *
 * Mode (which metric set applies, {@see BatchRunner::MODES}):
 * the optional `mode` of the body, else derived from the version:
 * `retrieval` when its configuration has knowledge collections, otherwise
 * `agent`; with `retrievalOnly` it is always `retrieval`. The mode is
 * always stored on the run.
 *
 * Retrieval only: the optional `retrievalOnly` (default false)
 * is stored on the run; the batch then measures each question's
 * retrieval without chat call and without conversation.
 *
 * On success the run is stored with `ownerSubject` = the caller's user
 * identifier (no user denies, fail closed), status `pending`, the mode,
 * the retrieval-only flag and
 * `questionsTotal` = the number of active questions (the batch job sets it
 * again when it takes its question list, see {@see BatchRunner}), a job of
 * type {@see EvalRun::JOB_TYPE_START} is queued with the input
 * `{"run": "<uuid>", "ownerSubject": "<identifier>"}`, its id is stored as
 * `jobId`, and the run is returned (202).
 *
 * @implements ProcessorInterface<mixed, EvalRun>
 */
final class EvalRunStartProcessor implements ProcessorInterface
{
    /** IRI of an agent version; the last segment is the id. */
    private const string IRI_PATTERN = '#/agent_versions/([^/?\#]+)$#';

    public function __construct(
        private readonly InputBodyValidator $inputBodyValidator,
        private readonly EntityManagerInterface $entityManager,
        private readonly JobQueueService $jobQueueService,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly AgentReferenceResolver $agentReferenceResolver,
        private readonly AgentPreviewFactory $previewFactory,
    ) {
    }

    /**
     * @throws BadRequestHttpException          on malformed JSON
     * @throws UnprocessableEntityHttpException for a schema violation or a version or test set that cannot be run
     * @throws ValidationException              for an invalid candidate (422 at `candidate/<field>`)
     * @throws ConflictHttpException            when the candidate's preview was created concurrently (409)
     * @throws AccessDeniedException            without an authenticated user
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): EvalRun
    {
        $request = $context['request'] ?? null;
        $body = $request instanceof Request ? $request->getContent() : '';

        self::assertSingleVersionSource($body);
        $input = $this->inputBodyValidator->validate(EvalRun::OPERATION_START, $body);

        $owner = $this->ownerSubject();
        $version = $this->requestedVersion($input);
        $retrievalOnly = true === ($input['retrievalOnly'] ?? false);
        $mode = $this->mode($version, isset($input['mode']) ? (string) $input['mode'] : null, $retrievalOnly);
        $questions = $this->activeQuestionCount();

        $run = new EvalRun()
            ->setAgentVersion($version)
            ->setOwnerSubject($owner)
            ->setStatus(EvalRunStatus::PENDING)
            ->setMode($mode)
            ->setRetrievalOnly($retrievalOnly)
            ->setQuestionsTotal($questions);
        $this->entityManager->persist($run);

        // createAndDispatch() flushes, so the run row exists before the
        // message is dispatched; no operation name: the job input is not a
        // request body of the start schema.
        $job = $this->jobQueueService->createAndDispatch(
            EvalRun::JOB_TYPE_START,
            ['run' => (string) $run->getId(), 'ownerSubject' => $owner],
        );

        $run->setJobId($job->getId());
        $this->entityManager->flush();

        return $run;
    }

    /**
     * Refuses a body that names both ways to give the version: `agentVersion`
     * together with `agent` or `candidate`. Checked on the raw decoded body
     * before the schema, so the message names the keys; malformed JSON and
     * non-object bodies are left to {@see InputBodyValidator}. Shared with
     * {@see \Dmstr\NeuronAiBundle\Controller\EvalQuestionExecuteController}.
     *
     * @throws UnprocessableEntityHttpException when both ways are given
     */
    public static function assertSingleVersionSource(string $body): void
    {
        $raw = json_decode('' === trim($body) ? '{}' : $body, true);
        if (!\is_array($raw) || !\array_key_exists('agentVersion', $raw)) {
            return;
        }
        if (\array_key_exists('agent', $raw) || \array_key_exists('candidate', $raw)) {
            throw new UnprocessableEntityHttpException('"agentVersion" cannot be combined with "agent" or "candidate": give either an agent version or an agent with "candidate": true.');
        }
    }

    /**
     * The version a validated start (or execute) body names: the preview of
     * the current candidate of `agent` when `candidate` is given (the schema
     * allows only `true` and requires `agent` with it), otherwise the
     * startable version of `agentVersion` ({@see self::startableVersion()}).
     * The preview is created by the caller's user identifier.
     *
     * @param array<string, mixed> $input body validated against a schema with both shapes
     *
     * @throws UnprocessableEntityHttpException for an unknown agent or version, a disabled agent or a stale version
     * @throws ValidationException              for an invalid candidate (422 at `candidate/<field>`)
     * @throws ConflictHttpException            when the preview was created concurrently (409)
     * @throws AccessDeniedException            without an authenticated user
     */
    public function requestedVersion(array $input): AgentVersion
    {
        if (true !== ($input['candidate'] ?? null)) {
            return $this->startableVersion((string) $input['agentVersion']);
        }

        $reference = (string) $input['agent'];
        $agent = $this->agentReferenceResolver->find(trim($reference))
            ?? throw new UnprocessableEntityHttpException(\sprintf('Unknown agent "%s".', $reference));

        return $this->previewFactory->previewFor($agent, $this->ownerSubject());
    }

    /**
     * The referenced version, provided its agent is enabled and it is
     * either a preview or the agent's current published version. A preview
     * is never current, so the current-version rule applies to published
     * versions only.
     *
     * @throws UnprocessableEntityHttpException for an unknown version, a disabled agent or a stale version
     */
    public function startableVersion(string $reference): AgentVersion
    {
        $version = $this->findVersion($reference)
            ?? throw new UnprocessableEntityHttpException(\sprintf('Unknown agent version "%s".', $reference));

        $agent = $version->getAgent();
        if (!$agent->isEnabled()) {
            throw new UnprocessableEntityHttpException(\sprintf('Agent "%s" of version "%s" is disabled.', $agent->getSlug(), $reference));
        }

        if ($version->isPreview()) {
            return $version;
        }

        $current = $agent->getCurrentVersion();
        if (null === $current || !$current->getId()->equals($version->getId())) {
            throw new UnprocessableEntityHttpException(\sprintf(
                'Agent version "%s" is not the current published version of agent "%s"%s; only the current version can be evaluated.',
                $reference,
                $agent->getSlug(),
                null === $current ? '' : \sprintf(' (current: version %d)', $current->getVersionNumber()),
            ));
        }

        return $version;
    }

    /**
     * The requested mode, or the one derived from $version: `retrieval`
     * with knowledge collections, otherwise `agent`; always `retrieval`
     * for a retrieval-only run.
     *
     * @param string|null $requested `retrieval`, `agent` (checked by the schema) or null
     *
     * @throws UnprocessableEntityHttpException for `retrieval` or a retrieval-only run on a version without knowledge collections, or for a retrieval-only run in mode `agent`
     */
    private function mode(AgentVersion $version, ?string $requested, bool $retrievalOnly): string
    {
        $hasCollections = [] !== AgentConfiguration::fromArray($version->getConfiguration())->collections;

        if ($retrievalOnly) {
            if (BatchRunner::MODE_AGENT === $requested) {
                throw new UnprocessableEntityHttpException(\sprintf(
                    'retrievalOnly: true needs mode "%s"; it cannot be combined with mode "%s" (omit mode or set it to "%s").',
                    BatchRunner::MODE_RETRIEVAL,
                    BatchRunner::MODE_AGENT,
                    BatchRunner::MODE_RETRIEVAL,
                ));
            }
            if (!$hasCollections) {
                throw new UnprocessableEntityHttpException(\sprintf(
                    'retrievalOnly: true needs an agent version with knowledge collections; %s of agent "%s" has none.',
                    self::versionName($version),
                    $version->getAgent()->getSlug(),
                ));
            }

            return BatchRunner::MODE_RETRIEVAL;
        }

        if (BatchRunner::MODE_RETRIEVAL === $requested && !$hasCollections) {
            throw new UnprocessableEntityHttpException(\sprintf(
                'mode: "%s" needs an agent version with knowledge collections; %s of agent "%s" has none (use mode "%s").',
                BatchRunner::MODE_RETRIEVAL,
                self::versionName($version),
                $version->getAgent()->getSlug(),
                BatchRunner::MODE_AGENT,
            ));
        }

        return $requested ?? ($hasCollections ? BatchRunner::MODE_RETRIEVAL : BatchRunner::MODE_AGENT);
    }

    /**
     * `version <n>` for a published version, `preview <hash8>` for a
     * preview (all previews have number 0), as messages name it.
     */
    private static function versionName(AgentVersion $version): string
    {
        return $version->isPreview() ? $version->label() : \sprintf('version %d', $version->getVersionNumber());
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
     * @throws UnprocessableEntityHttpException when no question is active
     */
    private function activeQuestionCount(): int
    {
        $count = $this->entityManager->getRepository(EvalQuestion::class)->count(['status' => EvalQuestionStatus::ACTIVE]);
        if (0 === $count) {
            throw new UnprocessableEntityHttpException('There is no active evaluation question to run.');
        }

        return $count;
    }

    /**
     * @throws AccessDeniedException without an authenticated user
     */
    private function ownerSubject(): string
    {
        $token = $this->tokenStorage->getToken();
        $identifier = null !== $token?->getUser() ? $token->getUserIdentifier() : '';
        if ('' === $identifier) {
            throw new AccessDeniedException('Starting an evaluation run requires an authenticated user.');
        }

        return $identifier;
    }
}

// - revised 2026-10-13
