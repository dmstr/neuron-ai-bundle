<?php

// file generated with AI assistance: Claude Code - 2026-10-05 20:27:34 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Reindex;

/**
 * A reindex request the {@see CollectionReindexer} refuses, with the request
 * field it concerns, so the API answers 422 with the violation at that
 * field and the console prints the message. The only field is `model`: the
 * target catalogue entry names the connection.
 */
final class ReindexRequestException extends \InvalidArgumentException
{
    public const string FIELD_MODEL = 'model';

    /**
     * @param self::FIELD_* $field
     */
    public function __construct(string $message, public readonly string $field, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
