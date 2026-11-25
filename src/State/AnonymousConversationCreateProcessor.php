<?php

// file generated with AI assistance: Claude Code - 2026-10-01 21:54:19 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Dmstr\NeuronAiBundle\Anonymous\AnonymousConversationCreator;
use Dmstr\NeuronAiBundle\Anonymous\AnonymousConversationResult;
use Dmstr\NeuronAiBundle\Entity\Conversation;
use Symfony\Component\HttpFoundation\Request;

/**
 * `POST /conversations/anonymous` (`conversation_anonymous`): public
 * creation of an anonymous conversation.
 *
 * The body must be `{}` (or empty): it is validated against
 * `Entity/Conversation/anonymous.input.json`, so any configuration in the
 * body (`collections`, `provider`, `model`, `mcpConnections`, `title`, …)
 * answers 422; an anonymous conversation always carries the released
 * values. The client address is the request's client IP, which honours
 * `X-Forwarded-For` only from trusted proxies. Everything else is
 * {@see AnonymousConversationCreator}.
 *
 * Like the stateless chat, this collection action runs synchronously
 * instead of returning a Job (the second deliberate exception to that guard
 * rail): it is one short insert, and the caller needs the token at once.
 *
 * @implements ProcessorInterface<mixed, AnonymousConversationResult>
 */
final class AnonymousConversationCreateProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly AnonymousConversationCreator $creator,
        private readonly InputBodyValidator $inputBodyValidator,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): AnonymousConversationResult
    {
        $request = $context['request'] ?? null;
        $request = $request instanceof Request ? $request : null;

        $this->inputBodyValidator->validate(Conversation::OPERATION_ANONYMOUS, (string) $request?->getContent());

        return $this->creator->create($request?->getClientIp());
    }
}
