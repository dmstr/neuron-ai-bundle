<?php

// file generated with AI assistance: Claude Code - 2026-10-02 21:49:46 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Agent;

use Dmstr\NeuronAiBundle\Entity\Agent;
use Dmstr\NeuronAiBundle\Provider\ModelOptions;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;

/**
 * The configuration of an agent in the shape of `Entity/Agent/candidate.json`,
 * shared by the candidate ({@see Agent::getCandidate()}) and the published version
 * ({@see \Dmstr\NeuronAiBundle\Entity\AgentVersion::getConfiguration()}).
 *
 * Every field is read at runtime: the configuration of a
 * published version is mapped to one turn by {@see VersionTurnMapper}
 * (instructions with placeholders, `startSchema` for the variables,
 * provider connection, model, collections, retrieval depth, hybrid retrieval
 * (`hybrid`, `hybridWeight`), context shaping
 * (`contextMaxChunks`, `contextMaxChars`, `contextExpansionMaxChars`,
 * `contextInstructions`), `mcpTools` with their
 * `terminal` flags, response schema, model options, limits, languages) for
 * `POST /agents/{id}/chat` and for every turn of a conversation created
 * with the agent; `maxUserTurns` is resolved once at the creation of such
 * a conversation. `modelOptions` ({@see ModelOptions}) are wired into the
 * provider call but not verified against live providers; a version stored
 * before them carries top-level `temperature` and `maxTokens`, which
 * {@see fromArray()} maps into `modelOptions`. `rules` and
 * `templates` are compiled into a
 * {@see Rule\RuleSet} and a {@see Template\TemplateSet} per turn. The
 * answer gates (`injectionGuard`, `answerabilityCheck` with
 * `answerabilityVotes`, `followUps`) default to off, so a version stored
 * without them keeps its behaviour through {@see fromArray()} alone.
 * `artifactTools` defaults to off the same way; whether a turn
 * actually gets the file tools is decided per turn from the conversation
 * and the token, not here.
 *
 * `model` is a reference to an entry of the model catalogue
 * ({@see \Dmstr\NeuronAiBundle\Entity\Refs\ProviderModel}, IRI or full or
 * partial UUID) in a candidate and the full UUID of the entry in a
 * published version; the entry names the provider connection, so there is
 * no `connection` key any more. A candidate may leave `model` null, the
 * publish requires it. The migration `Version20261008180000` rewrote stored
 * versions and candidates from `{connection, model: <name>}` to the entry's
 * UUID; a stored `connection` key (a version it could not migrate) and the
 * `provider` string of a version stored before provider connections are
 * ignored here (the mapper reports such a version as stale at
 * `configuration/model`). A candidate that still sends `connection` fails
 * the structural check with a hint at `model` ({@see structureErrors()}).
 *
 * Besides the value object, this class owns the structural check against
 * the candidate schema ({@see structureErrors()}), used by the PATCH constraint
 * {@see \Dmstr\NeuronAiBundle\Validator\ValidAgentCandidateValidator} and by the
 * publish check {@see AgentConfigurationValidator}, so both report the same
 * paths.
 */
final readonly class AgentConfiguration
{
    /**
     * Keys whose empty value is a JSON object, not a list. PHP decodes `{}`
     * and `[]` to the same empty array; these keys of the candidate and of JSON
     * schemas inside it take objects.
     */
    private const array OBJECT_KEYS = [
        'instructions', 'contextInstructions', 'responseSchema', 'startSchema', 'startMessage', 'templates', 'modelOptions',
        'properties', 'patternProperties', '$defs', 'definitions', 'dependentSchemas', 'dependentRequired',
    ];

    /**
     * @param array<string, string>                                         $instructions language tag => text
     * @param list<string>                                                  $collections
     * @param list<array{connection: string, name: string, terminal: bool}> $mcpTools
     * @param array<string, mixed>|null                                     $responseSchema
     * @param array<string, mixed>|null                                     $startSchema
     * @param list<string>                                                  $languages
     * @param list<array{name: ?string, match: array<string, mixed>, outcome: string, template: ?string}> $rules     in evaluation order; `match` holds `type` and the fields of that type (`keywords` and `wholeWord`, `pattern` and `flags`, `languages`)
     * @param array<string, array<string, string>>                                                       $templates name => (language tag => text)
     * @param array<string, string>                                                                      $contextInstructions language tag => instruction text in front of the context
     * @param array<string, int|float|bool|string>                                                       $modelOptions        option key => value ({@see ModelOptions}), only set options
     * @param array<string, string>|null                                                                 $startMessage        language tag => first user message, started when the conversation is created; null = none
     */
    public function __construct(
        public array $instructions,
        public ?string $model,
        public array $collections,
        public ?int $retrievalDepth,
        public array $mcpTools,
        public ?array $responseSchema,
        public ?array $startSchema,
        public ?int $maxToolCalls,
        public ?int $maxUserTurns,
        public array $languages,
        public string $defaultLanguage,
        public array $rules = [],
        public array $templates = [],
        public bool $hybrid = false,
        public float $hybridWeight = 0.5,
        public int $contextMaxChunks = 10,
        public int $contextMaxChars = 15000,
        public int $contextExpansionMaxChars = 6000,
        public array $contextInstructions = [],
        public bool $injectionGuard = false,
        public bool $answerabilityCheck = false,
        public int $answerabilityVotes = 1,
        public bool $followUps = false,
        public bool $artifactTools = false,
        public array $modelOptions = [],
        public ?array $startMessage = null,
    ) {
    }

    /**
     * Builds the configuration from a candidate or a stored configuration;
     * missing keys take the schema defaults ({@see Agent::candidateDefaults()}),
     * so a stored version without `hybrid` searches vector-only. The context
     * shaping keys are filled with their new defaults here as well; a
     * stored version without them keeps its legacy context only because
     * {@see VersionTurnMapper} checks the raw stored array. The schema
     * default of `retrievalDepth` is 10; stored versions hold the
     * key (null in versions stored before that default), so they keep their
     * depth. The answer gate keys default to off (votes 1), which is the behaviour of a
     * version stored before them, and so does `artifactTools` (no file
     * tools). A version stored before `modelOptions`
     * keeps its top-level `temperature` and `maxTokens` as model options
     * ({@see modelOptions()}). The data is
     * expected to be structurally valid ({@see structureErrors()}).
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $data += Agent::candidateDefaults();

        $mcpTools = [];
        foreach ($data['mcpTools'] as $tool) {
            $mcpTools[] = [
                'connection' => (string) $tool['connection'],
                'name' => (string) $tool['name'],
                'terminal' => (bool) ($tool['terminal'] ?? false),
            ];
        }

        return new self(
            instructions: array_map(strval(...), $data['instructions']),
            model: null === $data['model'] ? null : (string) $data['model'],
            collections: array_values(array_map(strval(...), $data['collections'])),
            retrievalDepth: $data['retrievalDepth'],
            mcpTools: $mcpTools,
            responseSchema: $data['responseSchema'],
            startSchema: $data['startSchema'],
            maxToolCalls: $data['maxToolCalls'],
            maxUserTurns: $data['maxUserTurns'],
            languages: array_values(array_map(strval(...), $data['languages'])),
            defaultLanguage: (string) $data['defaultLanguage'],
            rules: array_map(self::rule(...), array_values($data['rules'])),
            templates: array_map(
                static fn (array $texts): array => array_map(strval(...), $texts),
                $data['templates'],
            ),
            hybrid: (bool) $data['hybrid'],
            hybridWeight: (float) $data['hybridWeight'],
            contextMaxChunks: (int) $data['contextMaxChunks'],
            contextMaxChars: (int) $data['contextMaxChars'],
            contextExpansionMaxChars: (int) $data['contextExpansionMaxChars'],
            contextInstructions: array_map(strval(...), $data['contextInstructions']),
            injectionGuard: (bool) $data['injectionGuard'],
            answerabilityCheck: (bool) $data['answerabilityCheck'],
            answerabilityVotes: (int) $data['answerabilityVotes'],
            followUps: (bool) $data['followUps'],
            artifactTools: (bool) $data['artifactTools'],
            modelOptions: self::modelOptions($data),
            startMessage: null === $data['startMessage'] ? null : array_map(strval(...), $data['startMessage']),
        );
    }

    /**
     * The `modelOptions` of $data without null values; a top-level
     * `temperature` or `maxTokens` (a version stored before `modelOptions`)
     * fills the option of that name unless `modelOptions` sets it.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, int|float|bool|string>
     */
    private static function modelOptions(array $data): array
    {
        $options = \is_array($data['modelOptions'] ?? null) ? $data['modelOptions'] : [];
        foreach ([ModelOptions::TEMPERATURE, ModelOptions::MAX_TOKENS] as $legacy) {
            if (isset($data[$legacy]) && !isset($options[$legacy])) {
                $options[$legacy] = $data[$legacy];
            }
        }

        return array_filter($options, static fn (mixed $value): bool => \is_int($value) || \is_float($value) || \is_bool($value) || \is_string($value));
    }

    /**
     * One rule with every optional field filled: `name` and `template`
     * null, `wholeWord` true, `flags` ''. Fields of another match type are
     * dropped.
     *
     * @param array<string, mixed> $rule
     *
     * @return array{name: ?string, match: array<string, mixed>, outcome: string, template: ?string}
     */
    private static function rule(array $rule): array
    {
        $match = $rule['match'];
        $type = (string) $match['type'];

        return [
            'name' => isset($rule['name']) ? (string) $rule['name'] : null,
            'match' => match ($type) {
                'keywords' => [
                    'type' => $type,
                    'keywords' => array_values(array_map(strval(...), $match['keywords'] ?? [])),
                    'wholeWord' => (bool) ($match['wholeWord'] ?? true),
                ],
                'pattern' => [
                    'type' => $type,
                    'pattern' => (string) ($match['pattern'] ?? ''),
                    'flags' => (string) ($match['flags'] ?? ''),
                ],
                default => [
                    'type' => $type,
                    'languages' => array_values(array_map(strval(...), $match['languages'] ?? [])),
                ],
            },
            'outcome' => (string) $rule['outcome'],
            'template' => isset($rule['template']) ? (string) $rule['template'] : null,
        ];
    }

    /**
     * All 25 keys in candidate order, always present; every `mcpTools` entry
     * carries `terminal`, every rule `name`, `template` and the optional
     * fields of its match type.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'instructions' => $this->instructions,
            'model' => $this->model,
            'collections' => $this->collections,
            'retrievalDepth' => $this->retrievalDepth,
            'hybrid' => $this->hybrid,
            'hybridWeight' => $this->hybridWeight,
            'contextMaxChunks' => $this->contextMaxChunks,
            'contextMaxChars' => $this->contextMaxChars,
            'contextExpansionMaxChars' => $this->contextExpansionMaxChars,
            'contextInstructions' => $this->contextInstructions,
            'mcpTools' => $this->mcpTools,
            'artifactTools' => $this->artifactTools,
            'responseSchema' => $this->responseSchema,
            'startSchema' => $this->startSchema,
            'startMessage' => $this->startMessage,
            'modelOptions' => $this->modelOptions,
            'maxToolCalls' => $this->maxToolCalls,
            'maxUserTurns' => $this->maxUserTurns,
            'languages' => $this->languages,
            'defaultLanguage' => $this->defaultLanguage,
            'rules' => $this->rules,
            'templates' => $this->templates,
            'injectionGuard' => $this->injectionGuard,
            'answerabilityCheck' => $this->answerabilityCheck,
            'answerabilityVotes' => $this->answerabilityVotes,
            'followUps' => $this->followUps,
        ];
    }

    /** Message for the key `connection`, which the model reference replaced. */
    public const string CONNECTION_REMOVED = 'The key "connection" was removed: "model" references an entry of the model catalogue (IRI or UUID, /api/refs/provider_models), which names its provider connection.';

    /**
     * Checks the structure of a candidate against `Entity/Agent/candidate.json`
     * (opis/json-schema, draft 2020-12): unknown keys, types and ranges.
     * References are not checked here. The removed key `connection` is
     * reported at `/connection` with {@see CONNECTION_REMOVED} instead of
     * as an unknown key of the candidate.
     *
     * @param array<string, mixed> $candidate
     *
     * @return array<string, string> JSON pointer below the candidate ('' for the
     *                               candidate itself, e.g. `/maxToolCalls`,
     *                               `/mcpTools/1/name`) => message; empty when valid
     */
    public static function structureErrors(array $candidate): array
    {
        $errors = [];
        if (\array_key_exists('connection', $candidate)) {
            $errors['/connection'] = self::CONNECTION_REMOVED;
            unset($candidate['connection']);
        }

        /** @var object $schema */
        $schema = json_decode((string) file_get_contents(Agent::CANDIDATE_SCHEMA_FILE), false, 512, \JSON_THROW_ON_ERROR);

        $validator = new Validator();
        $validator->setMaxErrors(20);
        $result = $validator->validate(self::toJsonValue($candidate), $schema);
        if ($result->isValid()) {
            return $errors;
        }

        foreach (new ErrorFormatter()->format($result->error(), false) as $pointer => $message) {
            $errors['/' === $pointer ? '' : (string) $pointer] = (string) $message;
        }

        return $errors;
    }

    /**
     * Converts decoded JSON (associative arrays) back into the JSON value
     * opis validates: string-keyed arrays become objects, lists stay arrays,
     * an empty array becomes an object under one of {@see OBJECT_KEYS} or at
     * the top level of a candidate or schema (`$asObject`).
     */
    public static function toJsonValue(mixed $value, bool $asObject = true): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }
        if ([] === $value) {
            return $asObject ? new \stdClass() : [];
        }
        if (array_is_list($value)) {
            return array_map(static fn (mixed $item): mixed => self::toJsonValue($item, \is_array($item) && !array_is_list($item)), $value);
        }

        $object = new \stdClass();
        foreach ($value as $key => $item) {
            $object->{$key} = self::toJsonValue($item, \in_array($key, self::OBJECT_KEYS, true) || (\is_array($item) && [] !== $item && !array_is_list($item)));
        }

        return $object;
    }
}
// - revised 2026-10-14
// - revised 2026-10-16
