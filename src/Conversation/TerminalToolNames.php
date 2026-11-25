<?php

// file generated with AI assistance: Claude Code - 2026-10-02 22:14:31 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Conversation;

use NeuronAI\Tools\ToolInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Checks the `terminalTools` of a request against the tools the turn
 * offers, shared by the three surfaces (the synchronous turn, the streamed
 * turn and `/chat`). Called right after the tools are built
 * (`McpToolFactory::forConnections()`), before anything is stored and
 * before any provider call.
 *
 * A name is known when one of the offered tools carries it; a catalogue
 * row the tool factory skipped is not among them and therefore unknown.
 * The surfaces reject duplicates and non-string or empty entries in their
 * own validation (JSON schema `uniqueItems`/`minLength`, `Assert\Unique`
 * and `Assert\All`); {@see resolve()} rejects them as well, so a caller
 * without that validation gets the same 422.
 */
final class TerminalToolNames
{
    /**
     * The terminal tool names in request order.
     *
     * @param array<mixed>|null   $names the request's `terminalTools`; null or [] = none
     * @param list<ToolInterface> $tools the tools the turn offers
     *
     * @return list<string>
     *
     * @throws UnprocessableEntityHttpException naming the first entry that is not a non-empty string,
     *                                          a duplicate or not the name of an offered tool
     */
    public static function resolve(?array $names, array $tools): array
    {
        if (null === $names || [] === $names) {
            return [];
        }

        $offered = [];
        foreach ($tools as $tool) {
            $offered[$tool->getName()] = true;
        }

        $resolved = [];
        foreach (array_values($names) as $i => $name) {
            if (!\is_string($name) || '' === trim($name)) {
                throw new UnprocessableEntityHttpException(\sprintf('terminalTools[%d]: expected a non-empty tool name, got %s.', $i, \is_string($name) ? '"'.$name.'"' : get_debug_type($name)));
            }
            if (\in_array($name, $resolved, true)) {
                throw new UnprocessableEntityHttpException(\sprintf('terminalTools[%d]: the tool "%s" is listed more than once.', $i, $name));
            }
            if (!isset($offered[$name])) {
                throw new UnprocessableEntityHttpException(\sprintf('terminalTools[%d]: "%s" is not a tool offered in this turn.', $i, $name));
            }
            $resolved[] = $name;
        }

        return $resolved;
    }
}
