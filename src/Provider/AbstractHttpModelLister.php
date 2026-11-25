<?php

// file generated with AI assistance: Claude Code - 2026-10-05 18:50:29 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Provider;

/**
 * Base of the model listers. The HTTP part (short timeout, JSON decoding,
 * failure mapping without credentials, `DEFAULT_TIMEOUT_SECONDS`) is
 * {@see AbstractProviderHttpClient}, which the model describers share.
 */
abstract class AbstractHttpModelLister extends AbstractProviderHttpClient implements ModelListerInterface
{
}
