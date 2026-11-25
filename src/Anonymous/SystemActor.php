<?php

// file generated with AI assistance: Claude Code - 2026-10-01 21:54:19 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Anonymous;

use Symfony\Component\Security\Core\Authentication\Token\PreAuthenticatedToken;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * Runs an action under a synthetic security principal, so audit entries
 * written meanwhile carry a meaningful actor: the audit actor of the
 * application is the user identifier of the current token
 * (`dmstr_log_entries.username`).
 *
 * Used with `anon:<conversation-uuid>` for the public creation and with
 * `system:neuron:purge` for the scheduled purge. The identifier must never
 * be a secret such as the capability token. The previous token is restored
 * afterwards, also when the action throws.
 */
final class SystemActor
{
    public const string FIREWALL = 'neuron_system';

    public function __construct(
        private readonly TokenStorageInterface $tokenStorage,
    ) {
    }

    /**
     * @template T
     *
     * @param callable(): T $action
     *
     * @return T
     */
    public function run(string $identifier, callable $action): mixed
    {
        if ('' === $identifier) {
            throw new \InvalidArgumentException('The system actor needs an identifier.');
        }

        $previous = $this->tokenStorage->getToken();
        $this->tokenStorage->setToken(new PreAuthenticatedToken(new InMemoryUser($identifier, null, []), self::FIREWALL, []));

        try {
            return $action();
        } finally {
            $this->tokenStorage->setToken($previous);
        }
    }
}
