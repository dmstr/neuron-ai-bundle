<?php

// file generated with AI assistance: Claude Code - 2026-10-09 16:30:10 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Mcp\Server\Dto;

use ApiPlatform\Metadata\ApiProperty;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Arguments of the MCP tool `rag_get_context`.
 *
 * Untyped like {@see KnowledgeSearchInput}: API Platform maps the tool
 * arguments without type coercion, so the constraints check the type. They
 * run in sequence, because the UUID check cannot take a non-string value.
 */
final class KnowledgeContextInput
{
    #[Assert\Sequentially([
        new Assert\NotBlank(),
        new Assert\Type('string'),
        new Assert\Uuid(),
    ])]
    #[ApiProperty(
        description: 'Id (UUID) of the chunk, the chunkId of a rag_search hit.',
        required: true,
        schema: ['type' => 'string', 'format' => 'uuid'],
    )]
    public mixed $chunkId = null;
}
