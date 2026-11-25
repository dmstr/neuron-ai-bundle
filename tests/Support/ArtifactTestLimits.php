<?php

// file generated with AI assistance: Claude Code - 2026-10-16 08:40:52 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Support;

use Dmstr\NeuronAiBundle\Artifact\ArtifactLimitsInterface;

/**
 * Small limits for the artifact tests.
 */
final class ArtifactTestLimits implements ArtifactLimitsInterface
{
    public function __construct(
        private readonly int $maxFileSizeBytes = 100,
        private readonly int $maxFilesPerConversation = 3,
        private readonly int $maxTotalSizeBytesPerUser = 250,
        private readonly int $maxReadChars = 20,
    ) {
    }

    public function maxFileSizeBytes(): int
    {
        return $this->maxFileSizeBytes;
    }

    public function maxFilesPerConversation(): int
    {
        return $this->maxFilesPerConversation;
    }

    public function maxTotalSizeBytesPerUser(): int
    {
        return $this->maxTotalSizeBytesPerUser;
    }

    public function maxReadChars(): int
    {
        return $this->maxReadChars;
    }
}
