<?php

// file generated with AI assistance: Claude Code - 2026-10-06 18:00:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Provider;

/**
 * Another scan or sync of the same provider connection holds the
 * connection's catalogue lock ({@see ProviderModelScanner}); nothing was
 * described or written. The sync action answers 409.
 */
final class ScanInProgressException extends \RuntimeException
{
}
