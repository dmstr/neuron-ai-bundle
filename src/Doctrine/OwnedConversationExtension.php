<?php

// file generated with AI assistance: Claude Code - 2026-10-01 15:34:45 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\HttpOperation;
use ApiPlatform\Metadata\Operation;
use Dmstr\NeuronAiBundle\Entity\Conversation;
use Dmstr\NeuronAiBundle\Entity\Message;
use Dmstr\NeuronAiBundle\Security\Permissions;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Restricts the collection queries of conversations and messages to the
 * current user's own conversations: `GET /conversations`,
 * `GET /messages` and the sub-resource list
 * `GET /conversations/{conversationId}/messages`, whose provider delegates
 * to the Doctrine collection provider and therefore runs through this
 * extension too.
 *
 * Holders of `neuronai:conversation:read_any` see every conversation. Without a
 * token or user the query matches nothing (`1 = 0`), so a misconfigured
 * firewall fails closed instead of listing everything.
 *
 * Evaluation conversations (`origin` = `eval`) and replay
 * conversations (`origin` = `replay`), together
 * {@see Conversation::EVALUATION_ORIGINS}:
 *  - the lists hide every conversation with an origin (`origin IS NULL`)
 *    unless the request filters on `origin` (`?origin=eval`), also for
 *    holders of `neuronai:conversation:read_any`; for messages the condition applies
 *    to the joined conversation;
 *  - with the filter, holders of `neuronai:conversation:read_any` see every match,
 *    holders of `neuronai:conversation:read_eval` filtering on `eval` or `replay`
 *    see every conversation of that origin regardless of the owner,
 *    everybody else keeps the ownership restriction (a plain user sees only
 *    own evaluation and replay conversations); a list of origin values is
 *    restricted to the owner;
 *  - the sub-resource list of one conversation is not filtered by origin:
 *    its provider already checked `neuronai:conversation:read_own` on that conversation
 *    (which the voter grants for evaluation and replay conversations to
 *    holders of `neuronai:conversation:read_eval`), so the restriction here is the
 *    owner or one of those origins for those holders.
 *
 * The `origin` filter of messages is applied here through the joined
 * conversation, because `Message` has no `origin` search filter; for
 * conversations the search filter applies it, and the READ_EVAL branch adds
 * the origin condition itself so it never depends on the filter running.
 */
final class OwnedConversationExtension implements QueryCollectionExtensionInterface
{
    /** Uri variable of the sub-resource `/conversations/{conversationId}/messages`. */
    private const string SUB_RESOURCE_VARIABLE = 'conversationId';

    public function __construct(
        private readonly TokenStorageInterface $tokenStorage,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
    ) {
    }

    public function applyToCollection(QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $resourceClass, ?Operation $operation = null, array $context = []): void
    {
        if (Conversation::class !== $resourceClass && Message::class !== $resourceClass) {
            return;
        }

        $token = $this->tokenStorage->getToken();
        $identifier = null !== $token?->getUser() ? $token->getUserIdentifier() : '';
        if ('' === $identifier) {
            $queryBuilder->andWhere('1 = 0');

            return;
        }

        $rootAlias = $queryBuilder->getRootAliases()[0];
        if (Conversation::class === $resourceClass) {
            $conversationAlias = $rootAlias;
        } else {
            $conversationAlias = $queryNameGenerator->generateJoinAlias('ownedConversation');
            $queryBuilder->innerJoin(\sprintf('%s.conversation', $rootAlias), $conversationAlias);
        }

        $subResource = Message::class === $resourceClass && self::isSubResource($operation, $context);
        $filters = \is_array($context['filters'] ?? null) ? $context['filters'] : [];
        $originFilter = $filters['origin'] ?? null;

        if (!$subResource) {
            if (null === $originFilter) {
                $queryBuilder->andWhere(\sprintf('%s.origin IS NULL', $conversationAlias));
            } elseif (Message::class === $resourceClass) {
                $this->applyOriginFilter($queryBuilder, $queryNameGenerator, $conversationAlias, $originFilter);
            }
        }

        if ($this->authorizationChecker->isGranted(Permissions::READ_ANY)) {
            return;
        }

        $readsEval = ($subResource || \in_array($originFilter, Conversation::EVALUATION_ORIGINS, true))
            && $this->authorizationChecker->isGranted(Permissions::READ_EVAL);

        $owner = $queryNameGenerator->generateParameterName('ownerSubject');
        if (!$readsEval) {
            $queryBuilder
                ->andWhere(\sprintf('%s.ownerSubject = :%s', $conversationAlias, $owner))
                ->setParameter($owner, $identifier);

            return;
        }

        $origin = $queryNameGenerator->generateParameterName('origin');
        if ($subResource) {
            $queryBuilder
                ->andWhere(\sprintf('(%1$s.ownerSubject = :%2$s OR %1$s.origin IN (:%3$s))', $conversationAlias, $owner, $origin))
                ->setParameter($owner, $identifier)
                ->setParameter($origin, Conversation::EVALUATION_ORIGINS);
        } else {
            // The filter value, one of the evaluation origins (checked above).
            $queryBuilder
                ->andWhere(\sprintf('%s.origin = :%s', $conversationAlias, $origin))
                ->setParameter($origin, $originFilter);
        }
    }

    /**
     * `?origin=` on the message lists, applied to the joined conversation:
     * one value or a list of values (exact match).
     */
    private function applyOriginFilter(QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $conversationAlias, mixed $originFilter): void
    {
        $values = array_values(array_filter(
            \is_array($originFilter) ? $originFilter : [$originFilter],
            static fn (mixed $value): bool => \is_string($value),
        ));
        if ([] === $values) {
            $queryBuilder->andWhere('1 = 0');

            return;
        }

        $parameter = $queryNameGenerator->generateParameterName('originFilter');
        $queryBuilder
            ->andWhere(\sprintf('%s.origin IN (:%s)', $conversationAlias, $parameter))
            ->setParameter($parameter, $values);
    }

    /**
     * Whether the operation is the message list of one conversation, whose
     * provider has checked `neuronai:conversation:read_own` on it.
     *
     * @param array<string, mixed> $context
     */
    private static function isSubResource(?Operation $operation, array $context): bool
    {
        if (\is_array($context['uri_variables'] ?? null) && \array_key_exists(self::SUB_RESOURCE_VARIABLE, $context['uri_variables'])) {
            return true;
        }

        return $operation instanceof HttpOperation && \array_key_exists(self::SUB_RESOURCE_VARIABLE, $operation->getUriVariables() ?? []);
    }
}
