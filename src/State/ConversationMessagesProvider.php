<?php

// file generated with AI assistance: Claude Code - 2026-10-01 15:34:45 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use Dmstr\ApiPlatformUtils\Service\UuidResolver;
use Dmstr\NeuronAiBundle\Entity\Conversation;
use Dmstr\NeuronAiBundle\Entity\Message;
use Dmstr\NeuronAiBundle\Security\ConversationSecurity;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Collection provider of `GET /conversations/{conversationId}/messages`
 *.
 *
 * Resolves the parent conversation through {@see UuidResolver} (full or
 * partial UUID) and answers 404 when it does not exist, is ambiguous, or
 * the user may not read it (`neuronai:conversation:read_own`), so the sub-resource never
 * reveals whether a foreign conversation exists. It then rewrites the uri
 * variable to the full id and delegates to the Doctrine collection
 * provider, which applies the link to `Message::conversation`, pagination,
 * ordering and the bundle's ownership extension.
 *
 * @implements ProviderInterface<Message>
 */
final class ConversationMessagesProvider implements ProviderInterface
{
    public const string URI_VARIABLE = 'conversationId';

    /**
     * @param ProviderInterface<object> $collectionProvider
     */
    public function __construct(
        private readonly ProviderInterface $collectionProvider,
        private readonly UuidResolver $uuidResolver,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        $reference = $uriVariables[self::URI_VARIABLE] ?? null;
        $reference = $reference instanceof Uuid ? $reference->toRfc4122() : $reference;
        if (!\is_string($reference) || '' === $reference) {
            throw new NotFoundHttpException('Not Found');
        }

        try {
            $conversation = $this->uuidResolver->findByPartialUuid(Conversation::class, $reference);
        } catch (\RuntimeException) {
            // Ambiguous partial id: indistinguishable from "not found".
            $conversation = null;
        }

        if (!$conversation instanceof Conversation || !$this->authorizationChecker->isGranted(new Expression(ConversationSecurity::READ), $conversation)) {
            throw new NotFoundHttpException('Not Found');
        }

        $uriVariables[self::URI_VARIABLE] = $conversation->getId();
        $context['uri_variables'] = $uriVariables;

        return $this->collectionProvider->provide($operation, $uriVariables, $context);
    }
}
