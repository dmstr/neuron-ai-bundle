<?php

// file generated with AI assistance: Claude Code - 2026-10-01 15:21:48 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Conversation;

/**
 * Status of a conversation message record (`neuron_message.status`).
 *
 * - streaming: written before the provider call, not finished yet (the
 *   placeholder of a streaming turn's final record);
 * - complete: finished successfully;
 * - aborted: the final record of a cancelled turn (with the partial text),
 *   or an orphan of a turn whose worker died (marked by the next turn);
 * - error: the provider failed or the turn deadline passed.
 */
final class MessageStatus
{
    public const string STREAMING = 'streaming';
    public const string COMPLETE = 'complete';
    public const string ABORTED = 'aborted';
    public const string ERROR = 'error';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [self::STREAMING, self::COMPLETE, self::ABORTED, self::ERROR];
    }
}
