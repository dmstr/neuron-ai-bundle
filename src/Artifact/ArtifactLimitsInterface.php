<?php

// file generated with AI assistance: Claude Code - 2026-10-16 08:40:52 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Artifact;

/**
 * Limits of the artifact tools. A port: the bundle ships
 * {@see DefaultArtifactLimits}; an application that keeps the limits as
 * runtime settings aliases this interface to its own reader, which then
 * replaces the default. The values are read per tool call, so a changed
 * setting applies to the next write without a new turn.
 */
interface ArtifactLimitsInterface
{
    /**
     * Largest file `write_file` and `edit_file` may leave behind, in
     * bytes. Every written content is also stored as the tool input of the
     * message record and sent in the stream, so this bounds those copies too.
     */
    public function maxFileSizeBytes(): int;

    /** Files a conversation may create in its own working folder. */
    public function maxFilesPerConversation(): int;

    /** Sum of the sizes of all artifacts of one user, in bytes. */
    public function maxTotalSizeBytesPerUser(): int;

    /**
     * Characters `read_file` returns at most; a larger file is refused with
     * a hint to `grep_file_content`, so one read cannot fill the context.
     */
    public function maxReadChars(): int;
}
