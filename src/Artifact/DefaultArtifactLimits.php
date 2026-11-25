<?php

// file generated with AI assistance: Claude Code - 2026-10-16 08:40:52 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Artifact;

/**
 * The bundle default of {@see ArtifactLimitsInterface}: 512 KiB per file
 * (Markdown, JSON and code fit, the tool-input copies stay small), 50 files
 * per conversation folder, 50 MiB per user and 50 000 characters per read
 * (below a context window, above any sensible single document).
 */
final class DefaultArtifactLimits implements ArtifactLimitsInterface
{
    public const int MAX_FILE_SIZE_BYTES = 512 * 1024;
    public const int MAX_FILES_PER_CONVERSATION = 50;
    public const int MAX_TOTAL_SIZE_BYTES_PER_USER = 50 * 1024 * 1024;
    public const int MAX_READ_CHARS = 50000;

    public function maxFileSizeBytes(): int
    {
        return self::MAX_FILE_SIZE_BYTES;
    }

    public function maxFilesPerConversation(): int
    {
        return self::MAX_FILES_PER_CONVERSATION;
    }

    public function maxTotalSizeBytesPerUser(): int
    {
        return self::MAX_TOTAL_SIZE_BYTES_PER_USER;
    }

    public function maxReadChars(): int
    {
        return self::MAX_READ_CHARS;
    }
}
