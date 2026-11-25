<?php

// file generated with AI assistance: Claude Code - 2026-11-09 15:45:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Conversation;

use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;

/**
 * The search text of a turn's retrieval: the current user message followed
 * by up to $previous earlier user messages of the history, newest first.
 *
 * A follow-up ("and is there an example for that?") or a submitted form
 * carries no topic of its own; searched alone it finds unrelated passages,
 * and the passages of the earlier turns are not in the history. A message
 * that is a form submission `{"form": <id>, "value": {...}}` is searched by
 * its values, not by its JSON.
 */
final class RetrievalQuery
{
    /**
     * @param list<Message> $history the turn's history, oldest first
     */
    public static function of(string $content, array $history, int $previous): string
    {
        $parts = [self::text($content)];
        if ($previous > 0) {
            foreach (array_reverse($history) as $message) {
                if (!$message instanceof UserMessage || $message instanceof ToolResultMessage) {
                    continue;
                }
                $text = $message->getContent();
                if (\is_string($text) && '' !== trim($text)) {
                    $parts[] = self::text($text);
                }
                if (\count($parts) > $previous) {
                    break;
                }
            }
        }

        return implode("\n", array_filter($parts, static fn (string $part): bool => '' !== $part));
    }

    /**
     * The searchable text of one user message: the values of a form
     * submission, else the message itself.
     */
    public static function text(string $message): string
    {
        $decoded = json_decode($message, true);
        if (!\is_array($decoded) || !isset($decoded['form']) || !\is_array($decoded['value'] ?? null)) {
            return trim($message);
        }

        $values = [];
        array_walk_recursive($decoded['value'], static function (mixed $value) use (&$values): void {
            if (\is_string($value) && '' !== trim($value)) {
                $values[] = trim($value);
            } elseif (\is_int($value) || \is_float($value)) {
                $values[] = (string) $value;
            }
        });

        return implode(', ', $values);
    }
}
