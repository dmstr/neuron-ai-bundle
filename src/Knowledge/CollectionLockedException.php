<?php

// file generated with AI assistance: Claude Code - 2026-10-13 18:45:18 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Knowledge;

use Dmstr\NeuronAiBundle\Entity\Collection;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * A write to a locked collection or to its content: HTTP 423 Locked with
 * the message `Collection "<name>" is locked`. API Platform renders it as
 * a problem document with status 423 (an HttpException keeps its status
 * code); over MCP it becomes a tool error with the same message.
 */
final class CollectionLockedException extends HttpException
{
    public const int STATUS = 423;

    private readonly string $collectionName;

    public function __construct(Collection|string $collection, ?\Throwable $previous = null)
    {
        $this->collectionName = $collection instanceof Collection ? $collection->getName() : $collection;

        parent::__construct(self::STATUS, \sprintf('Collection "%s" is locked', $this->collectionName), $previous);
    }

    public function getCollectionName(): string
    {
        return $this->collectionName;
    }
}
