<?php

// file generated with AI assistance: Claude Code - 2026-10-01 22:14:12 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Conversation;

use Dmstr\ApiConfiguration\Entity\ApiConfiguration;
use Dmstr\NeuronAiBundle\Agent\AgentConfiguration;
use Dmstr\NeuronAiBundle\Agent\AgentLimitDefaultsInterface;
use Dmstr\NeuronAiBundle\Agent\LimitResolver;
use Dmstr\NeuronAiBundle\Agent\StaleReferenceException;
use Dmstr\NeuronAiBundle\Agent\TurnConfiguration;
use Dmstr\NeuronAiBundle\Agent\VersionTurnMapper;
use Dmstr\NeuronAiBundle\Anonymous\AnonymousAccessPolicy;
use Dmstr\NeuronAiBundle\Anonymous\AnonymousAccessPolicyInterface;
use Dmstr\NeuronAiBundle\Anonymous\ConversationCloser;
use Dmstr\NeuronAiBundle\Anonymous\ConversationLimitException;
use Dmstr\NeuronAiBundle\Entity\Conversation;
use Dmstr\NeuronAiBundle\Entity\Message;
use Dmstr\NeuronAiBundle\Provider\ProviderType;
use Dmstr\NeuronAiBundle\Stream\StreamEventStore;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * The turn gate and the record writes shared by the conversation turn
 * ({@see ConversationTurnService}) and the streaming turn
 * ({@see StreamingTurnService}); everything but {@see gate()} runs under
 * the conversation's turn lock. Not a service: both turn services build
 * one from their dependencies.
 *
 * - {@see gate()}: before the lock: a conversation that accepts no turns
 *   → {@see ConversationLimitException}; a conversation whose `turnCount`
 *   reached its `maxUserTurns` is closed (`user_turn_limit`, 422 with that
 *   code); an agent conversation (pinned `agentVersion`) rejects
 *   `responseSchema` and `terminalTools` (422 naming the key: both are
 *   fixed by the version); an anonymous conversation additionally rejects
 *   `responseSchema` (422), needs the access policy (503 without one) and
 *   is closed when its retention passed or its turn limit or token budget
 *   is reached (422 with the code);
 * - {@see prepare()}: the start of every turn, under the lock: refreshes
 *   the conversation and checks again that it accepts turns (a turn that
 *   finished between the gate and the lock may have closed it), clears
 *   the cancel flag, marks orphaned `streaming` records `aborted` and
 *   deletes the stored stream events of the conversation;
 * - {@see append()}: stores records with the next sequences;
 * - {@see finalise()}: stores the step records of a streaming turn below
 *   its final record (the placeholder), so the layout equals the
 *   non-streaming turn;
 * - {@see markFinal()}: the DBAL fallback when the entity manager cannot
 *   store the final record;
 * - {@see userTurnLimitFor()} and {@see maxToolCallsFor()}: the limits of
 *   the next turn's run, read after {@see prepare()} (fresh counters);
 * - {@see agentConfigurationFor()}: for an agent conversation, the
 *   {@see TurnConfiguration} of the next turn, mapped from the pinned
 *   version with the stored variables and language
 *   ({@see VersionTurnMapper}); the turn services take provider, model,
 *   instructions, response schema, collections, retrieval depth, tools,
 *   terminal tools, tool-call bound and model options from it instead of the
 *   conversation's columns;
 * - {@see connectionOf()}: for a conversation without an agent, its stored
 *   provider connection, refused as stale (422) when it is missing (a
 *   conversation stored before provider connections), inactive or no
 *   provider connection; an agent conversation takes the connection of its
 *   mapped version instead.
 *
 * Every sequence comes from `Conversation::$nextSequence`, read under a
 * pessimistic write lock and incremented in the same transaction. The
 * transaction that stores the final assistant record of a turn (also an
 * `error` or `aborted` one) counts the turn: `turnCount` + 1 and
 * `tokensUsed` + the input plus output tokens of the run and its answer
 * gates (cached and reasoning tokens are not counted); a turn an answer
 * gate refused counts the same way; a conversation that now reaches its
 * `maxUserTurns` is closed with `user_turn_limit` in the same transaction
 * (this wins over the anonymous turn limit when both are reached at
 * once), otherwise an anonymous conversation that now reaches its turn
 * limit or token budget is closed there. The budget is soft by one turn.
 * The last allowed turn itself is not refused: it runs forced from its
 * first inference ({@see UserTurnLimit}) and closes the conversation.
 */
final class TurnPersistence
{
    private readonly LimitResolver $limitResolver;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Connection $connection,
        private readonly StreamEventStore $eventStore,
        private readonly ConversationCloser $closer,
        private readonly ClockInterface $clock,
        private readonly ?AnonymousAccessPolicyInterface $anonymousPolicy = null,
        ?LimitResolver $limitResolver = null,
        private readonly ?AgentLimitDefaultsInterface $limitDefaults = null,
        private readonly ?int $maxToolCallsDefault = null,
        private readonly ?VersionTurnMapper $versionTurnMapper = null,
    ) {
        $this->limitResolver = $limitResolver ?? new LimitResolver();
    }

    /**
     * The gate of a turn, before the lock and before any retrieval or
     * provider call.
     *
     * @param array<string, mixed>|null $responseSchema the caller's schema; refused on agent and anonymous conversations
     * @param list<string>|null         $terminalTools  the caller's terminal tool names; refused on agent conversations ([] counts as given)
     *
     * @return AnonymousAccessPolicy|null the current policy for an anonymous conversation (the turn passes it on to the write that counts it), null otherwise
     *
     * @throws ConversationLimitException       when the conversation accepts no further turn (422 with a code), also
     *                                          after closing it because its `turnCount` reached `maxUserTurns`
     *                                          (`user_turn_limit`; the counting of {@see markFinal()} does not close)
     * @throws UnprocessableEntityHttpException for `responseSchema` or `terminalTools` on an agent conversation (fixed by
     *                                          the version) and for `responseSchema` on an anonymous conversation
     * @throws ServiceUnavailableHttpException  for an anonymous conversation when no access policy is configured
     */
    public function gate(Conversation $conversation, ?array $responseSchema, ?array $terminalTools = null): ?AnonymousAccessPolicy
    {
        if (!$conversation->acceptsTurns()) {
            throw ConversationLimitException::forConversation($conversation);
        }
        if (self::userTurnsExhausted($conversation)) {
            $this->closeAndRefuse($conversation, Conversation::CLOSE_REASON_USER_TURN_LIMIT, $this->clock->now());
        }

        if (null !== $conversation->getAgentVersion()) {
            if (null !== $responseSchema) {
                throw self::fixedByVersion('responseSchema');
            }
            if (null !== $terminalTools) {
                throw self::fixedByVersion('terminalTools');
            }
        }

        if (!$conversation->isAnonymous()) {
            return null;
        }
        if (null !== $responseSchema) {
            throw new UnprocessableEntityHttpException('Anonymous conversations do not accept a responseSchema.');
        }
        $policy = $this->anonymousPolicy?->current() ?? throw new ServiceUnavailableHttpException(null, 'Anonymous conversations are not configured.');
        $this->guardAnonymous($conversation, $policy);

        return $policy;
    }

    /**
     * Start of a turn, after the lock was acquired: refreshes the
     * conversation and checks again that it accepts turns, clears
     * `cancel_requested_at`, sweeps orphans and deletes the conversation's
     * stored stream events.
     *
     * @throws ConversationLimitException when a turn that finished between the gate and the lock closed the conversation
     */
    public function prepare(Conversation $conversation): void
    {
        $this->entityManager->refresh($conversation);
        if (!$conversation->acceptsTurns()) {
            throw ConversationLimitException::forConversation($conversation);
        }

        $this->connection->executeStatement(
            'UPDATE neuron_conversation SET cancel_requested_at = NULL WHERE id = :id AND cancel_requested_at IS NOT NULL',
            ['id' => $conversation->getId()->toRfc4122()],
        );

        // Records left `streaming` by a dead worker become `aborted`.
        $this->entityManager->createQuery(sprintf('UPDATE %s m SET m.status = :aborted WHERE m.conversation = :conversation AND m.status = :streaming', Message::class))
            ->setParameter('aborted', MessageStatus::ABORTED)
            ->setParameter('streaming', MessageStatus::STREAMING)
            ->setParameter('conversation', $conversation)
            ->execute();

        $this->eventStore->deleteFor($conversation->getId());
    }

    /**
     * The user-turn limit of the next turn's run: set when that turn is the
     * conversation's last allowed one, so the runner forces it from the
     * first inference; null for a conversation without `maxUserTurns` or
     * with turns left after this one. Call it after {@see prepare()}.
     */
    public function userTurnLimitFor(Conversation $conversation): ?UserTurnLimit
    {
        return UserTurnLimit::forNextTurn($conversation->getMaxUserTurns(), $conversation->getTurnCount());
    }

    /**
     * The tool-call bound of the next turn's run. Without an agent version
     * the requested value passes unchanged (null = the runner's default).
     * The turn services take the bound of an agent conversation from
     * {@see agentConfigurationFor()}; for such a conversation this method
     * resolves the same value through {@see LimitResolver} from its pinned
     * version's `maxToolCalls`, the settings default and the bundle
     * default, the requested value may only lower it.
     */
    public function maxToolCallsFor(Conversation $conversation, ?int $requested): ?int
    {
        $version = $conversation->getAgentVersion();
        if (null === $version) {
            return $requested;
        }

        return $this->limitResolver->resolve(
            AgentConfiguration::fromArray($version->getConfiguration())->maxToolCalls,
            $this->limitDefaults?->maxToolCalls(),
            $this->maxToolCallsDefault,
            $requested,
        );
    }

    /**
     * The configuration of the next turn of an agent conversation: the
     * pinned version mapped with the conversation's stored variables
     * (`{}` when none) and language, and the requested tool-call bound,
     * which may only lower the resolved one ({@see VersionTurnMapper::map()}).
     * Null for a conversation without an agent version, which runs on its
     * own columns. Call it under the lock, after {@see prepare()}: a
     * reference of the version that no longer resolves refuses the turn
     * before any record is stored.
     *
     * Whether the turn offers MCP tools ({@see offersMcpTools()}, warning
     * to $logger when the version's `mcpTools` are dropped) is decided
     * before the version is mapped: for an anonymous conversation the
     * mapper skips `mcpTools` (no check, no tool, no MCP transport), so the
     * configuration has no tools, MCP connections or terminal tools.
     *
     * The artifact tools are decided here too, under the lock: for a version
     * with `artifactTools`, {@see VersionTurnMapper::artifactAccessFor()}
     * asks {@see \Dmstr\NeuronAiBundle\Artifact\ArtifactAccessResolver}
     * (anonymous, `origin`, system actor, foreign token, invalid subject or
     * unusable storage root: none, with a warning; write tools only with
     * `neuronai:artifact:write`) and creates the conversation's working folder;
     * the access is passed to the mapper, which adds the tools and their
     * guidelines. The gate reads the security token now, never when a
     * tool runs (a streamed turn runs its tools after the request).
     *
     * @throws StaleReferenceException          for a reference of the version that no longer resolves (422 with its path)
     * @throws UnprocessableEntityHttpException for stored variables or a language the version does not accept (any more)
     * @throws \LogicException                  for an agent conversation when no mapper was configured
     */
    public function agentConfigurationFor(Conversation $conversation, ?int $maxToolCalls, ?LoggerInterface $logger = null): ?TurnConfiguration
    {
        $version = $conversation->getAgentVersion();
        if (null === $version) {
            return null;
        }
        if (null === $this->versionTurnMapper) {
            throw new \LogicException('A turn of an agent conversation needs the VersionTurnMapper.');
        }

        $agentConfiguration = AgentConfiguration::fromArray($version->getConfiguration());
        $withMcpTools = self::offersMcpTools($conversation, [] !== $agentConfiguration->mcpTools, $logger);
        $artifacts = $this->versionTurnMapper->artifactAccessFor($conversation, $agentConfiguration);

        return $this->versionTurnMapper->map($version, $conversation->getVariables() ?? [], $conversation->getLanguage(), $maxToolCalls, $withMcpTools, $artifacts);
    }

    /**
     * The provider connection of the next turn of a conversation without an
     * agent: its stored `providerConnection`, checked like a stale reference
     * of an agent version (database state only, no provider call, no model
     * check). Call it under the lock, after {@see prepare()}, so the turn is
     * refused before any record is stored.
     *
     * @throws UnprocessableEntityHttpException when the conversation has no connection (stored before provider
     *                                          connections), or its connection is inactive or no `ollama` or
     *                                          `anthropic` connection (any more)
     */
    public static function connectionOf(Conversation $conversation): ApiConfiguration
    {
        $connection = $conversation->getProviderConnection();
        if (null === $connection) {
            throw new UnprocessableEntityHttpException('connection: the conversation has no provider connection (it was created before provider connections); create a new conversation with a connection and a model.');
        }
        if (!ProviderType::isProvider($connection)) {
            throw new UnprocessableEntityHttpException(\sprintf('connection: the provider connection "%s" of the conversation is no ollama or anthropic connection.', $connection->getName()));
        }
        if (!$connection->isActive()) {
            throw new UnprocessableEntityHttpException(\sprintf('connection: the provider connection "%s" of the conversation is inactive.', $connection->getName()));
        }

        return $connection;
    }

    /**
     * Whether a turn of $conversation may offer MCP tools: never for an
     * anonymous conversation. The anonymous creation refuses releases with
     * MCP connections or an agent version with `mcpTools` (422); this is the
     * second line for a conversation row that carries connections anyway or
     * an agent version that was changed after the conversation was created.
     * Dropped tools of an anonymous conversation are logged at warning
     * (`$configured`: the conversation or its version would offer some).
     */
    public static function offersMcpTools(Conversation $conversation, bool $configured, ?LoggerInterface $logger = null): bool
    {
        if (!$conversation->isAnonymous()) {
            return true;
        }
        if ($configured) {
            $logger?->warning('MCP tools of anonymous conversation {conversation} were not offered: anonymous conversations cannot use MCP tools.', [
                'conversation' => (string) $conversation->getId(),
            ]);
        }

        return false;
    }

    /**
     * The instructions (system prompt) of a turn: the substituted text of
     * the agent configuration, null without a configuration or for an
     * empty text, so the runner adds no caller instructions.
     */
    public static function instructionsOf(?TurnConfiguration $configuration): ?string
    {
        return null === $configuration || '' === $configuration->instructions ? null : $configuration->instructions;
    }

    /**
     * @return list<Message> ordered by sequence
     */
    public function records(Conversation $conversation): array
    {
        /** @var list<Message> $records */
        $records = $this->entityManager->getRepository(Message::class)->findBy(['conversation' => $conversation], ['sequence' => 'ASC']);

        return $records;
    }

    /**
     * Stores records with the next sequences in one transaction; sets the
     * title from $titleSource when the conversation has none.
     *
     * With the $result of a turn (its assistant records), the turn is
     * counted in the same transaction ({@see countTurn()}).
     *
     * @param list<Message> $records
     */
    public function append(Conversation $conversation, array $records, ?string $titleSource = null, ?TurnResult $result = null, ?AnonymousAccessPolicy $policy = null): void
    {
        $this->entityManager->wrapInTransaction(function () use ($conversation, $records, $titleSource, $result, $policy): void {
            $this->entityManager->refresh($conversation, LockMode::PESSIMISTIC_WRITE);

            if (null !== $titleSource && '' === trim((string) $conversation->getTitle())) {
                $conversation->setTitle(ConversationTurnService::titleFrom($titleSource));
            }

            $sequence = $conversation->getNextSequence();
            foreach ($records as $record) {
                $record->setSequence($sequence++);
                $this->entityManager->persist($record);
            }
            $conversation->setNextSequence($sequence);

            if (null !== $result) {
                $this->countTurn($conversation, $result, $policy);
            }
        });
    }

    /**
     * Stores the assistant records of a streaming turn whose last record is
     * the placeholder stored by {@see append()} (its fields already set by
     * {@see TurnRecorder::assistantRecords()}).
     *
     * In one transaction under the pessimistic refresh: the placeholder
     * moves from its reserved sequence to the turn's last sequence first
     * (a free one, so the non-deferrable unique index on the sequence is
     * never violated), then the step records are inserted into the
     * sequences below it and `nextSequence` follows the placeholder. The
     * layout equals the non-streaming turn. Should another record have
     * been appended after the placeholder (not possible under the turn
     * lock), the step records follow `nextSequence` instead and the
     * placeholder keeps its sequence. The turn is counted in the same
     * transaction ({@see countTurn()}).
     *
     * @param non-empty-list<Message> $records the step records, then the placeholder
     */
    public function finalise(Conversation $conversation, array $records, TurnResult $result, ?AnonymousAccessPolicy $policy = null): void
    {
        $placeholder = $records[array_key_last($records)];
        $steps = \array_slice($records, 0, -1);
        $reserved = $placeholder->getSequence();

        try {
            $this->entityManager->wrapInTransaction(function () use ($conversation, $placeholder, $steps, $reserved, $result, $policy): void {
                $this->entityManager->refresh($conversation, LockMode::PESSIMISTIC_WRITE);
                $this->countTurn($conversation, $result, $policy);
                if ([] === $steps) {
                    return;
                }

                $next = $conversation->getNextSequence();
                if ($next === $reserved + 1) {
                    $last = $reserved + \count($steps);
                    $placeholder->setSequence($last);
                    // Updates run after inserts in a flush: move the
                    // placeholder in a flush of its own first.
                    $this->entityManager->flush();
                    $sequence = $reserved;
                    $conversation->setNextSequence($last + 1);
                } else {
                    $sequence = $next;
                    $conversation->setNextSequence($next + \count($steps));
                }

                foreach ($steps as $record) {
                    $record->setSequence($sequence++);
                    $this->entityManager->persist($record);
                }
            });
        } catch (\Throwable $e) {
            $placeholder->setSequence($reserved);

            throw $e;
        }
    }

    /**
     * Fallback when {@see finalise()} failed (e.g. a closed entity
     * manager): sets status, error and content of the final record with a
     * plain DBAL update, so no record stays `streaming`, and counts the
     * turn of $result (`turn_count`, `tokens_used`) in the same DBAL
     * transaction. A limit reached here does not close the conversation:
     * the gate of its next turn does. The entity is updated in memory to
     * match the record.
     *
     * The same update clears `close_reason`: the fallback only ever
     * leaves an `error` or `aborted` record, which carries no close reason
     * by contract, while the entity may already hold one
     * ({@see TurnRecorder::assistantRecords()} sets it before the failed
     * store). Row and entity therefore agree in every path, and
     * `done.closeReason` of a streamed turn is null here.
     */
    public function markFinal(Message $record, string $status, ?string $error, ?string $content, ?TurnResult $result = null): void
    {
        $this->connection->transactional(function (Connection $connection) use ($record, $status, $error, $content, $result): void {
            $connection->update(
                'neuron_message',
                ['status' => $status, 'error' => $error, 'content' => $content, 'close_reason' => null],
                ['id' => $record->getId()->toRfc4122()],
            );
            if (null !== $result) {
                $connection->executeStatement(
                    'UPDATE neuron_conversation SET turn_count = turn_count + 1, tokens_used = tokens_used + :tokens WHERE id = :id',
                    ['tokens' => self::tokensOf($result), 'id' => $record->getConversation()->getId()->toRfc4122()],
                );
            }
        });

        $record->setStatus($status)->setError($error)->setContent($content)->setCloseReason(null);
    }

    /**
     * Retention, turn limit and token budget of an anonymous conversation,
     * checked before anything else of the turn runs. A conversation past
     * one of them is closed (flushed, audited as an `update`) and the turn
     * refused.
     *
     * @throws ConversationLimitException
     */
    private function guardAnonymous(Conversation $conversation, AnonymousAccessPolicy $policy): void
    {
        $now = $this->clock->now();
        $reason = match (true) {
            $conversation->getCreatedAt()->add(new \DateInterval(\sprintf('P%dD', $policy->retentionDays))) <= $now => Conversation::CLOSE_REASON_RETENTION,
            $conversation->getTurnCount() >= $policy->maxTurnsPerConversation => Conversation::CLOSE_REASON_TURN_LIMIT,
            $conversation->getTokensUsed() >= $policy->maxTokensPerConversation => Conversation::CLOSE_REASON_TOKEN_LIMIT,
            default => null,
        };
        if (null === $reason) {
            return;
        }

        $this->closeAndRefuse($conversation, $reason, $now);
    }

    /**
     * Closes the conversation for $reason in a transaction under the
     * pessimistic refresh (flushed, audited as an `update`) and refuses the
     * turn. A conversation a concurrent turn closed first keeps that
     * close; the code follows the stored reason.
     *
     * @throws ConversationLimitException always
     */
    private function closeAndRefuse(Conversation $conversation, string $reason, \DateTimeImmutable $now): never
    {
        $this->entityManager->wrapInTransaction(function () use ($conversation, $reason, $now): void {
            $this->entityManager->refresh($conversation, LockMode::PESSIMISTIC_WRITE);
            $this->closer->close($conversation, $reason, $now);
        });

        throw ConversationLimitException::forConversation($conversation);
    }

    /**
     * The 422 for a turn parameter that the pinned agent version fixes.
     */
    private static function fixedByVersion(string $key): UnprocessableEntityHttpException
    {
        return new UnprocessableEntityHttpException(\sprintf('"%s" is fixed by the agent version and cannot be set on a turn of an agent conversation.', $key));
    }

    /**
     * Whether the conversation has used all user turns its `maxUserTurns`
     * allows; false without a limit.
     */
    private static function userTurnsExhausted(Conversation $conversation): bool
    {
        $max = $conversation->getMaxUserTurns();

        return null !== $max && $conversation->getTurnCount() >= $max;
    }

    /**
     * Counts a turn inside the transaction that stores its final record:
     * `turnCount` + 1, `tokensUsed` + input plus output tokens; reaching
     * `maxUserTurns` closes the conversation with `user_turn_limit` first;
     * otherwise, with a $policy (anonymous conversations), reaching the turn
     * limit or the token budget closes it.
     */
    private function countTurn(Conversation $conversation, TurnResult $result, ?AnonymousAccessPolicy $policy): void
    {
        $conversation
            ->setTurnCount($conversation->getTurnCount() + 1)
            ->setTokensUsed($conversation->getTokensUsed() + self::tokensOf($result));

        if (self::userTurnsExhausted($conversation)) {
            $this->closer->close($conversation, Conversation::CLOSE_REASON_USER_TURN_LIMIT, $this->clock->now());

            return;
        }
        if (null === $policy) {
            return;
        }
        if ($conversation->getTurnCount() >= $policy->maxTurnsPerConversation) {
            $this->closer->close($conversation, Conversation::CLOSE_REASON_TURN_LIMIT, $this->clock->now());
        } elseif ($conversation->getTokensUsed() >= $policy->maxTokensPerConversation) {
            $this->closer->close($conversation, Conversation::CLOSE_REASON_TOKEN_LIMIT, $this->clock->now());
        }
    }

    /**
     * Input plus output tokens of all responses of the run and of the
     * answer gates of the turn ({@see TurnResult::totalUsage()}), so a turn
     * an answer gate refused counts its gate tokens against the
     * conversation and the anonymous token budget.
     */
    private static function tokensOf(TurnResult $result): int
    {
        $usage = $result->totalUsage();

        return (int) ($usage['input_tokens'] ?? 0) + (int) ($usage['output_tokens'] ?? 0);
    }
}

// - revised 2026-10-09
// - revised 2026-10-09
// - revised 2026-10-16
