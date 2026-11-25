<?php

// file generated with AI assistance: Claude Code - 2026-09-30 21:22:22 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Validator;

use Symfony\Component\Validator\Attribute\HasNamedArguments;
use Symfony\Component\Validator\Constraint;

/**
 * Class-level constraint for {@see \Dmstr\ApiConfiguration\Entity\ApiConfiguration}:
 * the name of an `mcp` connection must be unique among `mcp` connections.
 *
 * The vendor table has no unique index on the name and other connection
 * types may reuse names, so the rule applies to `type = 'mcp'` only. The
 * bundle attaches it to the vendor entity through the validation mapping
 * file config/validation/ApiConfiguration.xml.
 */
final class McpConnectionNameUnique extends Constraint
{
    public const string NOT_UNIQUE_ERROR = '6f0b7c1e-3d0a-4b8e-9a51-7c2f4d9e5a10';

    protected const ERROR_NAMES = [
        self::NOT_UNIQUE_ERROR => 'NOT_UNIQUE_ERROR',
    ];

    public string $message = 'An MCP connection named {{ name }} already exists.';

    /**
     * @param string[]|null $groups
     */
    #[HasNamedArguments]
    public function __construct(
        ?string $message = null,
        ?array $groups = null,
        mixed $payload = null,
    ) {
        parent::__construct(null, $groups, $payload);

        $this->message = $message ?? $this->message;
    }

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
