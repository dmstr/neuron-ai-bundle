<?php

// file generated with AI assistance: Claude Code - 2026-10-09 16:16:39 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Mcp\Server\Dto;

use ApiPlatform\Metadata\ApiProperty;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Arguments of the MCP tool `rag_search`.
 *
 * API Platform maps the tool arguments onto this object without type
 * coercion, so the properties are untyped and the constraints check the
 * types: a wrong type is a validation error of the tool, not a mapping
 * failure. The JSON schema of each argument is declared explicitly.
 */
final class KnowledgeSearchInput
{
    public const int DEFAULT_K = 5;

    #[Assert\NotBlank]
    #[Assert\Type('string')]
    #[Assert\Length(max: 2000)]
    #[ApiProperty(
        description: 'What to search for, in natural language.',
        required: true,
        schema: ['type' => 'string', 'minLength' => 1, 'maxLength' => 2000],
    )]
    public mixed $query = null;

    #[Assert\Type('string')]
    #[ApiProperty(
        description: 'Restrict the search to one collection, by name or id as listed by rag_list_collections; without it every collection of this endpoint is searched.',
        schema: ['type' => ['string', 'null']],
    )]
    public mixed $collection = null;

    #[Assert\Type('integer')]
    #[Assert\Positive]
    #[ApiProperty(
        description: 'Number of hits to return (default 5); larger values are capped at the maximum of this endpoint.',
        schema: ['type' => 'integer', 'minimum' => 1, 'default' => self::DEFAULT_K],
    )]
    public mixed $k = self::DEFAULT_K;

    #[Assert\Type('string')]
    #[ApiProperty(
        description: 'Restrict the search to passages of this topic (exact match of the topic metadata).',
        schema: ['type' => ['string', 'null']],
    )]
    public mixed $topic = null;
}
