<?php

// file generated with AI assistance: Claude Code - 2026-10-01 21:54:19 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Anonymous;

use ApiPlatform\Metadata\ApiProperty;
use Dmstr\NeuronAiBundle\Entity\Conversation;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Response of `POST /conversations/anonymous`: the new conversation
 * (embedded with its `conversation:read` fields) and its capability token.
 * This is the only response that ever contains the token.
 */
final readonly class AnonymousConversationResult
{
    public const string GROUP_READ = 'conversation:anonymous_created';

    public function __construct(
        #[Groups([self::GROUP_READ])]
        #[ApiProperty(description: 'The new anonymous conversation.')]
        public Conversation $conversation,
        #[Groups([self::GROUP_READ])]
        #[ApiProperty(description: 'Capability token of the conversation (`anon_…`), shown only here. Send it as `Authorization: Bearer <token>` to read, continue and delete the conversation.')]
        public string $token,
    ) {
    }
}
