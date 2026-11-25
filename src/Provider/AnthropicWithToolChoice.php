<?php

// file generated with AI assistance: Claude Code - 2026-10-02 05:54:24 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Provider;

use NeuronAI\Chat\Messages\Message;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\Providers\Anthropic\Anthropic;
use NeuronAI\Tools\ToolCall;

/**
 * Anthropic provider with `tool_choice` support (see
 * {@see ToolChoiceAwareProviderInterface}).
 *
 * Design note (written to serve as the basis of an upstream pull request):
 *
 * - Seam: the choice is added in requestBody(), the documented override
 *   point that both HandleChat::chat() and HandleStream::stream() call, so
 *   chat and streaming requests carry the same `tool_choice` without
 *   touching either trait. AnthropicVertex overrides the same method and
 *   would compose the same way.
 * - Mapping: `auto`, `any`, `none` become `{"type": "<type>"}`, a
 *   forced tool becomes `{"type": "tool", "name": "<name>"}`. The field
 *   is emitted only when the body offers a non-empty `tools` list,
 *   because the Messages API rejects `tool_choice` without tools; with no
 *   choice set the body is exactly the parent's.
 * - Support per model: the Messages API answers HTTP 400 for the forcing
 *   choices `any` and `tool` on some model lines and whenever extended
 *   thinking is enabled, while `auto` and `none` are accepted
 *   everywhere. supportsToolChoice() therefore matches the model name
 *   against a list of PCRE patterns (constructor argument, default
 *   {@see self::UNSUPPORTED_FORCING_MODELS}) and checks the `thinking`
 *   parameter, so a caller can pick a fallback before the request fails.
 *   The bundle enables no thinking today; the check is a guard.
 */
class AnthropicWithToolChoice extends Anthropic implements ToolChoiceAwareProviderInterface, ResponseFormatAwareProviderInterface
{
    /**
     * Model lines with structured outputs (`output_config.format`). Like
     * {@see self::UNSUPPORTED_FORCING_MODELS} the maintenance point; a model
     * that matches none answers with the prompt instruction alone.
     *
     * @var list<non-empty-string>
     */
    public const array RESPONSE_FORMAT_MODELS = ['/^claude-(fable|mythos|opus|sonnet|haiku)-5(-|$)/', '/^claude-opus-4-[1-8](-|$)/', '/^claude-sonnet-4-[56](-|$)/', '/^claude-haiku-4-5(-|$)/'];

    /**
     * Model lines on which the Messages API rejects `tool_choice` `any`
     * and `tool` with HTTP 400 (`claude-haiku-4-5-20251001` still
     * accepts them). This list is the maintenance point: add a pattern when
     * a new model line declines forcing, or pass an own list to the
     * constructor.
     *
     * @var list<non-empty-string>
     */
    public const array UNSUPPORTED_FORCING_MODELS = ['/^claude-fable-/', '/^claude-opus-5(-|$)/', '/^claude-sonnet-5(-|$)/'];

    private ?ToolChoice $toolChoice = null;

    /** @var array<string, mixed>|null the API copy of the response schema ({@see AnthropicJsonSchema}) */
    private ?array $responseFormat = null;

    /**
     * @param array<string, mixed>   $parameters
     * @param list<non-empty-string> $unsupportedForcingModels PCRE patterns matched against the model name; a match declines `any` and `tool`
     * @param string|null            $baseUri                  base URI of the Messages API with the version path (`base_url` of an `anthropic` connection); null = neuron-ai's https://api.anthropic.com/v1/. Set before the parent constructor, which reads it
     */
    public function __construct(
        string $key,
        string $model,
        string $version = '2023-06-01',
        int $max_tokens = 8192,
        array $parameters = [],
        ?HttpClientInterface $httpClient = null,
        private readonly array $unsupportedForcingModels = self::UNSUPPORTED_FORCING_MODELS,
        ?string $baseUri = null,
    ) {
        if (null !== $baseUri && '' !== $baseUri) {
            $this->baseUri = rtrim($baseUri, '/').'/';
        }

        parent::__construct(
            key: $key,
            model: $model,
            version: $version,
            max_tokens: $max_tokens,
            parameters: $parameters,
            httpClient: $httpClient,
        );
    }

    public function supportsToolChoice(ToolChoice $choice): bool
    {
        if ($choice->isAuto() || $choice->isNone()) {
            return true;
        }

        if ($this->thinkingEnabled()) {
            return false;
        }

        foreach ($this->unsupportedForcingModels as $pattern) {
            if (1 === preg_match($pattern, $this->model)) {
                return false;
            }
        }

        return true;
    }

    public function setToolChoice(?ToolChoice $choice): static
    {
        $this->toolChoice = $choice;

        return $this;
    }

    public function getToolChoice(): ?ToolChoice
    {
        return $this->toolChoice;
    }

    public function setResponseFormat(?array $schema): bool
    {
        if (null === $schema) {
            $this->responseFormat = null;

            return true;
        }

        $supported = array_filter(self::RESPONSE_FORMAT_MODELS, fn (string $pattern): bool => 1 === preg_match($pattern, $this->model));
        $format = [] !== $supported ? AnthropicJsonSchema::of($schema) : null;
        if (null === $format) {
            return false;
        }
        $this->responseFormat = $format;

        return true;
    }

    /**
     * The parent's body plus `output_config.format` when a response format
     * is set and `tool_choice` when a choice is set and the body offers
     * tools.
     *
     * @param Message[] $messages
     *
     * @return array<string, mixed>
     */
    protected function requestBody(array $messages, bool $stream = false): array
    {
        $body = parent::requestBody($messages, $stream);

        if (null !== $this->responseFormat) {
            // Merged into an `output_config` the parameters may carry (effort).
            $body['output_config'] = ['format' => ['type' => 'json_schema', 'schema' => $this->responseFormat]] + (array) ($body['output_config'] ?? []);
        }

        if (null === $this->toolChoice || !isset($body['tools']) || [] === $body['tools']) {
            return $body;
        }

        $body['tool_choice'] = $this->toolChoice->isTool()
            ? ['type' => ToolChoice::TOOL, 'name' => $this->toolChoice->name]
            : ['type' => $this->toolChoice->type];

        return $body;
    }

    /**
     * A call of a tool the request did not offer (a model that invents a
     * tool, e.g. `json` for a JSON answer) becomes a call without a live
     * tool instead of the parent's ProviderException, which failed the
     * whole turn: the tool node cannot resolve it and hands its error to
     * the tool error handler, so the model gets it as a tool error and can
     * answer on.
     *
     * @param array<string, mixed> $inputs
     */
    public function newToolCall(string $name, ?string $callId, array $inputs): ToolCall
    {
        try {
            return parent::newToolCall($name, $callId, $inputs);
        } catch (ProviderException) {
            return new ToolCall($name, $callId, $inputs);
        }
    }

    /**
     * Extended thinking excludes forcing on Anthropic. An explicit
     * `{"type": "disabled"}` does not count as enabled.
     */
    private function thinkingEnabled(): bool
    {
        $thinking = $this->parameters['thinking'] ?? null;

        if (null === $thinking) {
            return false;
        }

        return !(\is_array($thinking) && 'disabled' === ($thinking['type'] ?? null));
    }
}

// - revised 2026-11-09
// - revised 2026-11-09
