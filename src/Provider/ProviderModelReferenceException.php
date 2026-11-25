<?php

// file generated with AI assistance: Claude Code - 2026-10-08 15:38:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Provider;

use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Validator\ConstraintViolation;

/**
 * A model reference that does not resolve to a usable entry of the model
 * catalogue ({@see ProviderModelResolver}). HTTP 422 by itself; `reason`
 * lets a caller tell the cases apart, and {@see violationAt()} puts the
 * message at the caller's own field (`model`, `candidate/model`,
 * `embeddingModel`, ...). Messages never contain a credential.
 */
final class ProviderModelReferenceException extends UnprocessableEntityHttpException
{
    /** The reference is missing or empty. */
    public const string EMPTY = 'empty';

    /** No catalogue entry matches the reference. */
    public const string UNKNOWN = 'unknown';

    /** A partial UUID matches several catalogue entries. */
    public const string AMBIGUOUS = 'ambiguous';

    /** The entry exists, but its connection no longer offers the model (`available` false). */
    public const string UNAVAILABLE = 'unavailable';

    /** The entry's provider connection is not active. */
    public const string INACTIVE = 'inactive';

    /** The catalogue knows the model lacks the required capability, or its connection type cannot serve it. */
    public const string MISSING_CAPABILITY = 'missing_capability';

    /**
     * The entry is usable as an embedding model, but no model of the
     * embedding registry (`dmstr_neuron_ai.embeddings.models`) matches its
     * name ({@see \Dmstr\NeuronAiBundle\Embeddings\EmbeddingModelResolver}).
     */
    public const string UNREGISTERED = 'unregistered';

    /**
     * @param self::EMPTY|self::UNKNOWN|self::AMBIGUOUS|self::UNAVAILABLE|self::INACTIVE|self::MISSING_CAPABILITY|self::UNREGISTERED $reason
     */
    public function __construct(
        string $message,
        public readonly string $reason,
        public readonly ?string $reference,
    ) {
        parent::__construct($message);
    }

    /**
     * The message as a violation at $path, for callers that collect the
     * violations of several fields before throwing.
     *
     * @param mixed $root the validated object (the violation root)
     */
    public function violationAt(string $path, mixed $root = null): ConstraintViolation
    {
        return new ConstraintViolation($this->getMessage(), $this->getMessage(), [], $root, $path, $this->reference);
    }
}
