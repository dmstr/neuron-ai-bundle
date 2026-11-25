<?php

// file generated with AI assistance: Claude Code - 2026-10-06 17:20:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Provider\Model;

/**
 * What a provider connection reports about one model
 * ({@see \Dmstr\NeuronAiBundle\Provider\ModelDescriberInterface}).
 *
 * `capabilities` has three states: null = unknown (not reported, never
 * described), a list without `tools` = known to lack tools, a list with
 * `tools` = known to support tools. `maxContextLength` is the trained
 * maximum (Ollama `<arch>.context_length`, Anthropic `max_input_tokens`),
 * not Ollama's runtime `num_ctx`; that one, if the Modelfile sets it, is
 * in `providerData.parameters.num_ctx`. `supportsSampling` states whether the
 * model accepts `temperature`, `top_p` and `top_k` (Ollama always; for
 * Anthropic see {@see \Dmstr\NeuronAiBundle\Provider\AnthropicSamplingSupport});
 * null = unknown.
 *
 * `described = false` means this call did not describe the model (digest
 * unchanged, describe budget reached, `/show` failed or blocked): only the
 * listing fields (name, family, size, quantization, digest) are fresh, and
 * a catalogue keeps the stored capabilities, context length and sync time.
 * `unchanged` marks the digest skip among them: the stored description is
 * still current, so a stored per-model error is obsolete. `error` is a
 * redacted per-model failure text.
 */
final readonly class ModelDescription
{
    /**
     * @param list<string>|null    $capabilities values of {@see ModelCapability::ALL}, sorted
     * @param array<string, mixed> $providerData small extras that are not filterable
     */
    public function __construct(
        public string $name,
        public ?string $displayName = null,
        public ?string $family = null,
        public ?string $parameterSize = null,
        public ?string $quantization = null,
        public ?string $digest = null,
        public ?array $capabilities = null,
        public ?int $maxContextLength = null,
        public ?int $maxOutputTokens = null,
        public ?bool $supportsSampling = null,
        public array $providerData = [],
        public bool $described = true,
        public ?string $error = null,
        public bool $unchanged = false,
    ) {
    }

    /**
     * Whether the description reports the capability. False also when the
     * capabilities are unknown.
     */
    public function hasCapability(string $capability): bool
    {
        return null !== $this->capabilities && \in_array($capability, $this->capabilities, true);
    }
}

// - revised 2026-10-07
