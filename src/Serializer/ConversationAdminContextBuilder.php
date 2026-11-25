<?php

// file generated with AI assistance: Claude Code - 2026-10-01 21:50:51 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Serializer;

use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\State\SerializerContextBuilderInterface;
use Dmstr\NeuronAiBundle\Entity\Conversation;
use Dmstr\NeuronAiBundle\Security\Permissions;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Adds the serialization group `conversation:admin` (operator-only fields
 * such as the client address hash of an anonymous conversation) to the
 * normalization context of `GET /conversations` and
 * `GET /conversations/{id}` when the user holds `neuronai:conversation:read_any`.
 *
 * Never on POST outputs (creation, anonymous creation, turn) and never for
 * denormalization. The check goes through `is_granted()` and therefore
 * through the permission map: a capability-token principal has no role,
 * so it never holds `read_any` and token holders never see these fields.
 */
final class ConversationAdminContextBuilder implements SerializerContextBuilderInterface
{
    public function __construct(
        private readonly SerializerContextBuilderInterface $decorated,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
    ) {
    }

    public function createFromRequest(Request $request, bool $normalization, ?array $extractedAttributes = null): array
    {
        $context = $this->decorated->createFromRequest($request, $normalization, $extractedAttributes);

        if (!$normalization || Conversation::class !== ($context['resource_class'] ?? null)) {
            return $context;
        }

        $operation = $context['operation'] ?? $request->attributes->get('_api_operation');
        if (!$operation instanceof Get && !$operation instanceof GetCollection) {
            return $context;
        }

        if (!$this->authorizationChecker->isGranted(Permissions::READ_ANY)) {
            return $context;
        }

        $groups = (array) ($context['groups'] ?? [Conversation::GROUP_READ]);
        if (!\in_array(Conversation::GROUP_ADMIN, $groups, true)) {
            $groups[] = Conversation::GROUP_ADMIN;
        }
        $context['groups'] = $groups;

        return $context;
    }
}
