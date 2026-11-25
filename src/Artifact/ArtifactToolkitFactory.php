<?php

// file generated with AI assistance: Claude Code - 2026-10-16 08:40:52 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Artifact;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Lock\LockFactory;

/**
 * Builds the {@see ArtifactToolkit} of one turn from its resolved
 * {@see ArtifactAccess}. Holds the shared collaborators: the limits port,
 * the ledger and the lock factory (`lock.factory`, a store shared by all
 * php containers in production, so the per-user write lock holds across
 * workers).
 */
final class ArtifactToolkitFactory
{
    public function __construct(
        private readonly ArtifactLimitsInterface $limits,
        private readonly ArtifactLedgerInterface $ledger,
        private readonly LockFactory $lockFactory,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly float $lockWaitSeconds = ArtifactTool::DEFAULT_LOCK_WAIT_SECONDS,
    ) {
    }

    public function forAccess(ArtifactAccess $access): ArtifactToolkit
    {
        return new ArtifactToolkit($access, $this->limits, $this->ledger, $this->lockFactory, $this->lockWaitSeconds, $this->logger);
    }
}
