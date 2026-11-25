<?php

// file generated with AI assistance: Claude Code - 2026-10-06 21:00:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Provider;

use Dmstr\NeuronAiBundle\Agent\AgentConfiguration;
use Dmstr\NeuronAiBundle\Entity\Agent;
use Dmstr\NeuronAiBundle\Entity\Refs\ProviderModel;
use Dmstr\NeuronAiBundle\Provider\Model\ModelCapability;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;

/**
 * The JSON schema (draft 2020-12) of the `modelOptions` one model accepts.
 * The option definitions (type, range, title, description) are those of
 * `modelOptions` in `Entity/Agent/candidate.json`; this class selects and
 * narrows them:
 *
 *  - per provider type: Ollama `temperature`, `topP`, `topK`, `maxTokens`,
 *    `repeatPenalty`, `seed`, `numCtx`, `think`; Anthropic `temperature`,
 *    `topP`, `topK`, `maxTokens`, `effort`, `thinkingDisplay`;
 *  - sampling (`temperature`, `topP`, `topK`) on Anthropic only for a model
 *    that takes it: the catalogue row's `supportsSampling`, else
 *    {@see AnthropicSamplingSupport} (unknown counts as accepted); there
 *    `temperature` goes up to 1, and `temperature` and `topP` must not be
 *    set together (`not` + `required`, Claude 4 rejects the pair);
 *  - with a catalogue row whose capabilities are known: `think` and
 *    `thinkingDisplay` only with the capability `thinking`
 *    (`thinkingDisplay` also needs adaptive thinking when the Models API
 *    reports thinking types);
 *  - with a catalogue row: `numCtx` up to the trained context length and
 *    with the Modelfile default named in its description,
 *    Anthropic `maxTokens` up to the output limit, `effort` with the levels
 *    the Models API reports (none reported: all of
 *    {@see ModelOptions::EFFORT_LEVELS}; reported as unsupported: no
 *    `effort`).
 *
 * Without a row (unknown model, stale row) only the provider type and the
 * sampling rule table apply.
 */
final class ModelOptionsSchema
{
    /** Upper bound of `temperature` on Anthropic (the Messages API takes 0 to 1, not 0 to 2) */
    public const float ANTHROPIC_MAX_TEMPERATURE = 1.0;

    /** @var array<string, list<string>> the options each provider type knows */
    public const array PROVIDER_OPTIONS = [
        ProviderType::OLLAMA => [
            ModelOptions::TEMPERATURE, ModelOptions::TOP_P, ModelOptions::TOP_K, ModelOptions::MAX_TOKENS,
            ModelOptions::REPEAT_PENALTY, ModelOptions::SEED, ModelOptions::NUM_CTX, ModelOptions::THINK,
        ],
        ProviderType::ANTHROPIC => [
            ModelOptions::TEMPERATURE, ModelOptions::TOP_P, ModelOptions::TOP_K, ModelOptions::MAX_TOKENS,
            ModelOptions::EFFORT, ModelOptions::THINKING_DISPLAY,
        ],
    ];

    private function __construct()
    {
    }

    /**
     * @param string             $type  provider type, `ollama` or `anthropic`
     * @param string             $model model name on the connection
     * @param ProviderModel|null $row   the model's catalogue row; null = not catalogued (or stale)
     *
     * @return array<string, mixed> the schema; `properties` is never empty
     *
     * @throws \InvalidArgumentException for a type that is no chat provider type
     */
    public static function of(string $type, string $model, ?ProviderModel $row = null): array
    {
        $keys = self::PROVIDER_OPTIONS[$type] ?? throw new \InvalidArgumentException(\sprintf('"%s" is no chat provider type (expected one of: %s).', $type, implode(', ', ProviderType::CHAT_TYPES)));
        $definitions = Agent::candidateSchema()['properties']['modelOptions']['properties'];

        $properties = [];
        foreach ($keys as $key) {
            if (self::offers($type, $model, $row, $key)) {
                $properties[$key] = $definitions[$key];
            }
        }

        if (isset($properties[ModelOptions::NUM_CTX]) && null !== $row?->getMaxContextLength()) {
            $properties[ModelOptions::NUM_CTX]['maximum'] = $row->getMaxContextLength();
        }
        if (isset($properties[ModelOptions::NUM_CTX]) && null !== $row?->getDefaultContextLength()) {
            // In the description, not as `default`: a form would store the
            // default and pin the agent to today's Modelfile value.
            $properties[ModelOptions::NUM_CTX]['description'] .= \sprintf(' Without a value the model runs with %d (Modelfile).', $row->getDefaultContextLength());
        }
        if (ProviderType::ANTHROPIC === $type && null !== $row?->getMaxOutputTokens()) {
            $properties[ModelOptions::MAX_TOKENS]['maximum'] = $row->getMaxOutputTokens();
        }
        if (isset($properties[ModelOptions::EFFORT])) {
            $properties[ModelOptions::EFFORT]['enum'] = self::effortLevels($row);
        }
        if (ProviderType::ANTHROPIC === $type && isset($properties[ModelOptions::TEMPERATURE])) {
            $properties[ModelOptions::TEMPERATURE]['maximum'] = self::ANTHROPIC_MAX_TEMPERATURE;
        }

        $schema = [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'title' => \sprintf('Model options of %s', $model),
            'description' => null !== $row
                ? \sprintf('The `modelOptions` the %s model "%s" accepts, narrowed by its entry in the model catalogue.', $type, $model)
                : \sprintf('The `modelOptions` of a %s model that is not in the model catalogue: the options of the provider, without limits of the model.', $type),
            'type' => 'object',
            'properties' => $properties,
            'additionalProperties' => false,
        ];
        if (ProviderType::ANTHROPIC === $type && isset($properties[ModelOptions::TEMPERATURE], $properties[ModelOptions::TOP_P])) {
            $schema['not'] = [
                'description' => 'Anthropic models reject `temperature` and `topP` in the same request: set at most one of them.',
                'required' => [ModelOptions::TEMPERATURE, ModelOptions::TOP_P],
            ];
        }

        return $schema;
    }

    /**
     * Checks $options against a schema of {@see of()}: an option outside
     * `properties` and a value its definition rejects are reported per key.
     *
     * @param array<string, mixed> $schema
     * @param array<string, mixed> $options
     * @param string               $subject names the model in a message, e.g. `Model "llama3.2" of connection "ollama-local"`
     *
     * @return array<string, string> option key => message; empty when valid
     */
    public static function errors(array $schema, array $options, string $subject): array
    {
        $errors = [];
        $validator = new Validator();
        foreach ($options as $key => $value) {
            $definition = $schema['properties'][$key] ?? null;
            if (null === $definition) {
                $errors[$key] = \sprintf('%s does not take the option "%s" (it takes: %s).', $subject, $key, implode(', ', array_keys($schema['properties'])));

                continue;
            }

            $result = $validator->validate(AgentConfiguration::toJsonValue($value, false), AgentConfiguration::toJsonValue($definition));
            if (!$result->isValid()) {
                $errors[$key] = \sprintf('%s: %s', $subject, implode(' ', array_map(strval(...), new ErrorFormatter()->format($result->error(), false))));
            }
        }

        // The only cross-option rule the schemas carry: options that must not
        // be set together (`not` + `required`), reported at the last of them.
        $exclusive = $schema['not']['required'] ?? [];
        if ([] !== $exclusive && [] === array_diff($exclusive, array_keys($options))) {
            $errors[$exclusive[array_key_last($exclusive)]] ??= \sprintf('%s does not take the options %s together: set at most one of them.', $subject, implode(' and ', $exclusive));
        }

        return $errors;
    }

    private static function offers(string $type, string $model, ?ProviderModel $row, string $key): bool
    {
        $capabilitiesKnown = null !== $row?->getCapabilities();

        return match ($key) {
            ModelOptions::TEMPERATURE, ModelOptions::TOP_P, ModelOptions::TOP_K => ProviderType::ANTHROPIC !== $type
                || false !== ($row?->getSupportsSampling() ?? AnthropicSamplingSupport::of($model)),
            ModelOptions::THINK => !$capabilitiesKnown || $row->hasCapability(ModelCapability::THINKING),
            ModelOptions::THINKING_DISPLAY => !$capabilitiesKnown
                || ($row->hasCapability(ModelCapability::THINKING) && false !== self::reported($row, ['thinking', 'types', 'adaptive', 'supported'])),
            ModelOptions::EFFORT => false !== self::reported($row, ['effort', 'supported']),
            default => true,
        };
    }

    /**
     * The effort levels the Models API reports as supported, in
     * {@see ModelOptions::EFFORT_LEVELS} order; all levels when it reports
     * none.
     *
     * @return list<string>
     */
    private static function effortLevels(?ProviderModel $row): array
    {
        $levels = array_values(array_filter(
            ModelOptions::EFFORT_LEVELS,
            static fn (string $level): bool => true === self::reported($row, ['effort', $level, 'supported']),
        ));

        return [] !== $levels ? $levels : ModelOptions::EFFORT_LEVELS;
    }

    /**
     * A leaf of the Anthropic `capabilities` object stored in the row's
     * `providerData`; null when the row, the object or the path is missing.
     *
     * @param list<string> $path
     */
    private static function reported(?ProviderModel $row, array $path): mixed
    {
        $value = $row?->getProviderData()['capabilities'] ?? null;
        foreach ($path as $segment) {
            if (!\is_array($value) || !\array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }

        return $value;
    }
}

// - revised 2026-10-07
