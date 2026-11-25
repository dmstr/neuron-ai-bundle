<?php

// file generated with AI assistance: Claude Code - 2026-10-05 18:50:29 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Validator;

use Symfony\Component\Validator\Attribute\HasNamedArguments;
use Symfony\Component\Validator\Constraint;

/**
 * Class-level constraint for {@see \Dmstr\ApiConfiguration\Entity\ApiConfiguration}:
 * the name of a provider connection (`ollama`, `anthropic`) must be unique
 * across both provider types, so a chat or embedding reference by name is
 * never ambiguous. `mcp` and `firecrawl` connections may reuse the name.
 *
 * The vendor table has no unique index on the name, so this is an
 * application rule with a race window; the resolver treats duplicates as
 * ambiguous. Attached to the vendor entity through
 * config/validator/ApiConfiguration.xml.
 */
final class ProviderConnectionNameUnique extends Constraint
{
    public const string NOT_UNIQUE_ERROR = 'b8f3c0d2-5e4a-4f1b-8c7d-2a9e6f1b3c45';

    protected const ERROR_NAMES = [
        self::NOT_UNIQUE_ERROR => 'NOT_UNIQUE_ERROR',
    ];

    public string $message = 'A provider connection named {{ name }} already exists.';

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
