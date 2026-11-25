<?php

// file generated with AI assistance: Claude Code - 2026-10-02 22:05:06 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Dmstr\NeuronAiBundle\Agent\AgentPublisher;
use Dmstr\NeuronAiBundle\Entity\Agent;
use Dmstr\NeuronAiBundle\Entity\AgentVersion;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * `POST /agents/{id}/publish` (`agent_publish`).
 *
 * The operation loads the agent (`read: true`) and has already checked
 * `neuronai:agent:write`; there is no body. The publishing user is the user
 * identifier of the token, like the owner in
 * {@see ConversationCreateProcessor}; no token or user denies (fail
 * closed). {@see AgentPublisher} validates the candidate with all references
 * (422 at `candidate/<field>`), freezes it as the next version and makes that
 * version current (409 when a concurrent publish took the number). Returns
 * the new version (201).
 *
 * @implements ProcessorInterface<Agent, AgentVersion>
 */
final class AgentPublishProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly AgentPublisher $publisher,
        private readonly TokenStorageInterface $tokenStorage,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): AgentVersion
    {
        if (!$data instanceof Agent) {
            throw new NotFoundHttpException('Not Found');
        }

        $token = $this->tokenStorage->getToken();
        $identifier = null !== $token?->getUser() ? $token->getUserIdentifier() : '';
        if ('' === $identifier) {
            throw new AccessDeniedException('Publishing an agent requires an authenticated user.');
        }

        return $this->publisher->publish($data, $identifier);
    }
}
