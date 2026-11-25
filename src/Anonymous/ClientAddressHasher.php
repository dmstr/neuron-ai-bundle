<?php

// file generated with AI assistance: Claude Code - 2026-10-01 21:54:19 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Anonymous;

/**
 * Keyed hash of a client address (HMAC-SHA256, lowercase hex). The raw
 * address of an anonymous visitor is never stored: the hash is the rate
 * limiter key and the `address_hash` of the conversation. Without the key
 * the hash cannot be reversed by trying the IPv4 space.
 *
 * The key is the bundle configuration `anonymous.address_hash_key`; an empty
 * key fails at construction, so anonymous creation fails loudly instead of
 * hashing with a guessable key.
 */
final class ClientAddressHasher
{
    /**
     * @throws \InvalidArgumentException when the key is empty
     */
    public function __construct(
        #[\SensitiveParameter]
        private readonly string $key,
    ) {
        if ('' === trim($key)) {
            throw new \InvalidArgumentException('The address hash key of anonymous conversations is empty (dmstr_neuron_ai.anonymous.address_hash_key).');
        }
    }

    /**
     * Hash of the normalized address: surrounding whitespace removed, IPv6
     * lowercased, so equal addresses in different spellings of case share
     * one hash.
     */
    public function hash(string $address): string
    {
        return hash_hmac('sha256', strtolower(trim($address)), $this->key);
    }
}
