<?php

// file generated with AI assistance: Claude Code - 2026-10-02 05:54:24 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Provider;

/**
 * Optional provider capability: force or forbid tool use per request.
 *
 * Proposed as an addition to neuron-ai, which has no `tool_choice` today.
 * It is a separate interface rather than new methods on
 * `AIProviderInterface`, so existing providers stay valid and callers
 * detect the capability with `instanceof`. A caller (typically a workflow
 * middleware that bounds a tool loop) asks supportsToolChoice() first and
 * falls back to its own strategy when the provider declines, e.g. by
 * removing the tools from the request.
 *
 * Mutating setter, not a "with*" clone: the agent's inference nodes resolve
 * the provider once and keep that instance for the whole run, so a clone
 * would never reach the next request. setToolChoice() therefore changes the
 * instance and returns it, like `setTools()` and `systemPrompt()`; the
 * choice applies to every following request until it is cleared with null.
 * The caller that sets a choice is responsible for clearing it.
 *
 * Support is decided per instance, i.e. per provider and model: an API may
 * accept `none` on a model that answers `any` with an error, and other
 * request parameters (e.g. Anthropic's extended thinking) can exclude
 * forcing. `auto` is always the provider's default behaviour.
 */
interface ToolChoiceAwareProviderInterface
{
    /**
     * Whether this provider instance, with its model and parameters, can
     * send $choice without the API rejecting the request.
     */
    public function supportsToolChoice(ToolChoice $choice): bool;

    /**
     * Sets the choice for every following request (null clears it, which
     * restores the API default). Mutates and returns this instance.
     *
     * Implementations send the choice only when the request offers tools,
     * because APIs reject a tool choice without tool definitions.
     */
    public function setToolChoice(?ToolChoice $choice): static;

    public function getToolChoice(): ?ToolChoice;
}
