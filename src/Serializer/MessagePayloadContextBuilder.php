<?php

// file generated with AI assistance: Claude Code - 2026-10-01 15:34:45 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Serializer;

use ApiPlatform\Metadata\Get;
use ApiPlatform\State\SerializerContextBuilderInterface;
use Dmstr\NeuronAiBundle\Entity\Message;
use Dmstr\NeuronAiBundle\Security\Permissions;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Adds the serialization group `message:payload` (the provider payload of a
 * message) to the normalization context of `GET /messages/{id}` when the
 * user holds `neuronai:conversation:read_payload`.
 *
 * Never on lists, never on the turn response (a POST on the conversation
 * resource) and never for denormalization, so the payload only ever leaves
 * the server one item at a time and only for holders of the permission.
 */
final class MessagePayloadContextBuilder implements SerializerContextBuilderInterface
{
    public function __construct(
        private readonly SerializerContextBuilderInterface $decorated,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
    ) {
    }

    public function createFromRequest(Request $request, bool $normalization, ?array $extractedAttributes = null): array
    {
        $context = $this->decorated->createFromRequest($request, $normalization, $extractedAttributes);

        if (!$normalization || Message::class !== ($context['resource_class'] ?? null)) {
            return $context;
        }

        $operation = $context['operation'] ?? $request->attributes->get('_api_operation');
        if (!$operation instanceof Get) {
            return $context;
        }

        if (!$this->authorizationChecker->isGranted(Permissions::READ_PAYLOAD)) {
            return $context;
        }

        $groups = (array) ($context['groups'] ?? [Message::GROUP_READ]);
        if (!\in_array(Message::GROUP_PAYLOAD, $groups, true)) {
            $groups[] = Message::GROUP_PAYLOAD;
        }
        $context['groups'] = $groups;

        return $context;
    }
}
