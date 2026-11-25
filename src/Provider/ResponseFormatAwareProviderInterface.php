<?php

// file generated with AI assistance: Claude Code - 2026-11-09 14:30:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Provider;

/**
 * Optional provider capability: constrain the text answer to a JSON schema
 * on the API side (structured outputs), so the model cannot answer in prose
 * or try to deliver the JSON through a made-up tool call.
 *
 * Like {@see ToolChoiceAwareProviderInterface} a mutating setter on the
 * instance the run keeps; the caller clears it with null after the run. The
 * client-side validation of the answer stays in place: the API copy of the
 * schema may drop constraints the API does not support.
 */
interface ResponseFormatAwareProviderInterface
{
    /**
     * Sets the schema for every following request, or clears it with null.
     * Returns false and sets nothing when this provider and model cannot
     * enforce the schema (unsupported model, or a schema the API rejects);
     * the caller then relies on the prompt instruction alone.
     *
     * @param array<string, mixed>|null $schema
     */
    public function setResponseFormat(?array $schema): bool;
}
