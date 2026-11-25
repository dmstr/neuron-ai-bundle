<?php

// file generated with AI assistance: Claude Code - 2026-10-06 21:00:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Provider;

/**
 * The model options of one chat provider call: the `modelOptions` of an
 * agent version (schema in `Entity/Agent/candidate.json`). Every value is
 * optional; null means the provider's default (for `numCtx`, the model's
 * Modelfile `num_ctx`, else the Ollama server's default; for `maxTokens`
 * on Anthropic, the connection's `max_tokens`, else 8192).
 *
 * {@see \Dmstr\NeuronAiBundle\ProviderFactory::chatProvider()} maps them to
 * the provider request:
 *
 * | Option | Ollama | Anthropic |
 * |---|---|---|
 * | `temperature`, `topP`, `topK` | `options.temperature`, `options.top_p`, `options.top_k` | `temperature`, `top_p`, `top_k`, only for a model that takes sampling ({@see AnthropicSamplingSupport}) |
 * | `maxTokens` | `options.num_predict` | `max_tokens` |
 * | `repeatPenalty`, `seed` | `options.repeat_penalty`, `options.seed` | not sent |
 * | `numCtx` | `options.num_ctx` | not sent |
 * | `think` | `think` | not sent |
 * | `effort` | not sent | `output_config.effort` |
 * | `thinkingDisplay` | not sent | `thinking: {type: adaptive, display}` |
 *
 * Which options a model accepts is checked at the publish
 * ({@see ModelOptionsSchema}); the mapping drops what the provider does not
 * know, so a version stored with an option of the other provider still runs.
 */
final readonly class ModelOptions
{
    public const string TEMPERATURE = 'temperature';
    public const string TOP_P = 'topP';
    public const string TOP_K = 'topK';
    public const string MAX_TOKENS = 'maxTokens';
    public const string REPEAT_PENALTY = 'repeatPenalty';
    public const string SEED = 'seed';
    public const string NUM_CTX = 'numCtx';
    public const string THINK = 'think';
    public const string EFFORT = 'effort';
    public const string THINKING_DISPLAY = 'thinkingDisplay';

    /** The sampling options, which current Claude models reject. */
    public const array SAMPLING = [self::TEMPERATURE, self::TOP_P, self::TOP_K];

    /** The effort levels of the Anthropic Messages API, lowest first. */
    public const array EFFORT_LEVELS = ['low', 'medium', 'high', 'xhigh', 'max'];

    /**
     * @param float|null  $temperature     sampling temperature
     * @param float|null  $topP            nucleus sampling
     * @param int|null    $topK            top-k sampling
     * @param int|null    $maxTokens       output token bound per provider call
     * @param float|null  $repeatPenalty   Ollama repeat penalty
     * @param int|null    $seed            Ollama sampling seed
     * @param int|null    $numCtx          Ollama context window of the request
     * @param bool|null   $think           Ollama thinking switch
     * @param string|null $effort          Anthropic effort, one of {@see EFFORT_LEVELS}
     * @param string|null $thinkingDisplay Anthropic adaptive thinking display, `omitted` or `summarized`
     */
    public function __construct(
        public ?float $temperature = null,
        public ?float $topP = null,
        public ?int $topK = null,
        public ?int $maxTokens = null,
        public ?float $repeatPenalty = null,
        public ?int $seed = null,
        public ?int $numCtx = null,
        public ?bool $think = null,
        public ?string $effort = null,
        public ?string $thinkingDisplay = null,
    ) {
    }

    /**
     * The options of a `modelOptions` object; null when it sets none, so
     * "no options" has one representation and the provider is built
     * exactly as without them. Unknown keys are ignored.
     *
     * @param array<string, mixed> $options
     */
    public static function fromArray(array $options): ?self
    {
        $float = static fn (string $key): ?float => is_numeric($options[$key] ?? null) ? (float) $options[$key] : null;
        $int = static fn (string $key): ?int => \is_int($options[$key] ?? null) ? $options[$key] : null;
        $string = static fn (string $key): ?string => \is_string($options[$key] ?? null) && '' !== $options[$key] ? $options[$key] : null;

        $instance = new self(
            temperature: $float(self::TEMPERATURE),
            topP: $float(self::TOP_P),
            topK: $int(self::TOP_K),
            maxTokens: $int(self::MAX_TOKENS),
            repeatPenalty: $float(self::REPEAT_PENALTY),
            seed: $int(self::SEED),
            numCtx: $int(self::NUM_CTX),
            think: \is_bool($options[self::THINK] ?? null) ? $options[self::THINK] : null,
            effort: $string(self::EFFORT),
            thinkingDisplay: $string(self::THINKING_DISPLAY),
        );

        return [] === $instance->toArray() ? null : $instance;
    }

    /**
     * The set options under their `modelOptions` keys.
     *
     * @return array<string, int|float|bool|string>
     */
    public function toArray(): array
    {
        return array_filter([
            self::TEMPERATURE => $this->temperature,
            self::TOP_P => $this->topP,
            self::TOP_K => $this->topK,
            self::MAX_TOKENS => $this->maxTokens,
            self::REPEAT_PENALTY => $this->repeatPenalty,
            self::SEED => $this->seed,
            self::NUM_CTX => $this->numCtx,
            self::THINK => $this->think,
            self::EFFORT => $this->effort,
            self::THINKING_DISPLAY => $this->thinkingDisplay,
        ], static fn (int|float|bool|string|null $value): bool => null !== $value);
    }
}

// - revised 2026-10-07
