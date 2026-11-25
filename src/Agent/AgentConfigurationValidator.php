<?php

// file generated with AI assistance: Claude Code - 2026-10-02 21:49:46 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Agent;

use ApiPlatform\Validator\Exception\ValidationException;
use Dmstr\ApiConfiguration\Entity\ApiConfiguration;
use Dmstr\NeuronAiBundle\Agent\Rule\Rule;
use Dmstr\NeuronAiBundle\Artifact\ArtifactToolkit;
use Dmstr\NeuronAiBundle\Agent\Template\TemplateSet;
use Dmstr\NeuronAiBundle\Conversation\ConversationReferences;
use Dmstr\NeuronAiBundle\Entity\Refs\McpTool;
use Dmstr\NeuronAiBundle\Mcp\McpConnectionResolver;
use Dmstr\NeuronAiBundle\Entity\Refs\ProviderModel;
use Dmstr\NeuronAiBundle\Provider\Model\ModelCapability;
use Dmstr\NeuronAiBundle\Provider\ModelAvailabilityCheck;
use Dmstr\NeuronAiBundle\Provider\ModelOptionsSchema;
use Dmstr\NeuronAiBundle\Provider\ModelUnavailableException;
use Dmstr\NeuronAiBundle\Provider\ProviderModelReferenceException;
use Dmstr\NeuronAiBundle\Provider\ProviderModelResolver;
use Dmstr\NeuronAiBundle\Provider\ProviderType;
use Dmstr\NeuronAiBundle\Provider\ToolModelCheck;
use Doctrine\ORM\EntityManagerInterface;
use Opis\JsonSchema\Exceptions\SchemaException;
use Opis\JsonSchema\Schemas\ExceptionSchema;
use Opis\JsonSchema\Validator;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * The publish check of an agent candidate: validates the candidate completely,
 * resolves its references and returns the configuration a version freezes.
 *
 *  - structure, types and ranges: `Entity/Agent/candidate.json`
 *    ({@see AgentConfiguration::structureErrors()});
 *  - `model` is required (there is no default model): a reference to an
 *    entry of the model catalogue, resolved by
 *    {@see ProviderModelResolver::resolve()} with the capability
 *    `completion` (empty, unknown, ambiguous, unavailable, of an inactive
 *    connection, of a connection that is not `ollama` or `anthropic`, or
 *    known to lack `completion` is a violation at `candidate/model`) and
 *    stored as the entry's full UUID; the entry's connection and name are
 *    then checked live by {@see ModelAvailabilityCheck::assertAvailable()}
 *    (not offered, or not verifiable because the provider is unreachable,
 *    is a violation at `candidate/model` with the check's text, which never
 *    contains a credential); a version never holds null for it;
 *  - `collections`: via {@see ConversationReferences::collectionIds()}, stored
 *    as full UUIDs (duplicates dropped);
 *  - `mcpTools[].connection`: via {@see McpConnectionResolver::resolve()}
 *    (active `mcp` connection), stored as a full UUID; `mcpTools[].name`
 *    must be a {@see McpTool} row of that connection, and a tool name may
 *    occur only once across all entries (the rule of
 *    {@see \Dmstr\NeuronAiBundle\Mcp\McpToolFactory::forTools()}: a provider
 *    cannot tell two tools of the same name apart); with `artifactTools`
 *    on, no entry may carry the name of an artifact tool
 *    ({@see ArtifactToolkit::TOOL_NAMES}), for the same reason, because a
 *    turn of a conversation adds those tools next to the MCP tools
 *    (violation at `candidate/mcpTools/<i>/name`);
 *  - with `mcpTools` or `artifactTools`, the model must be able to take tools
 *    ({@see ToolModelCheck}, when injected): a model the model catalogue
 *    knows to lack `tools` (or `completion`) is a violation at
 *    `candidate/model`; an unknown model (no catalogue row, capabilities never
 *    described, stale row) passes; so does every model while the
 *    connection's allow-list `tool_models` is empty, otherwise only the
 *    listed ones;
 *  - `modelOptions` must fit the model ({@see ModelOptionsSchema}), once
 *    the model resolved and passed the live check: only the
 *    options of the provider type, sampling only for a model that takes it,
 *    and, for a current catalogue entry (the resolved one; a stale entry
 *    counts as none), the limits of the entry (`numCtx`, Anthropic `maxTokens`, the
 *    reported effort levels, thinking options only with the capability
 *    `thinking`); a violation per option at `candidate/modelOptions/<key>`;
 *  - `responseSchema` and `startSchema` must be valid JSON schemas, with
 *    draft 2020-12 as the default draft like the turn's
 *    {@see \Dmstr\NeuronAiBundle\Conversation\StructuredAnswerValidator}
 *    (opis default); `startSchema` must declare `type: object`;
 *  - `defaultLanguage` must be one of `languages`; `instructions` must have
 *    an entry for `defaultLanguage` and only keys from `languages`;
 *    `contextInstructions` only keys from `languages` (no entry is
 *    required; ranges of the context limits are structural);
 *  - `rules`: a `pattern` must compile ({@see Rule::compilePattern()}), the
 *    `languages` of a `language` rule must be languages of the agent, an
 *    `answer` or `refuse` rule must reference an existing, non-reserved
 *    template ({@see TemplateSet::RESERVED}), a `continue` rule none;
 *  - `templates`: every template needs a text for `defaultLanguage`, only
 *    keys from `languages`, and may use only the placeholders `date`,
 *    `language`, `agentName`, the `properties` of `startSchema` and those of
 *    its context ({@see TemplateSet::contextPlaceholders()}); every text of
 *    the slot `frame` must contain `{{answer}}` exactly once
 *    ({@see TemplateSet::answerCount()}, path `candidate/templates/frame/<lang>`);
 *  - answer gates: with `injectionGuard` on, the slot
 *    `injectionRefusal` needs a non-empty text for `defaultLanguage`, with
 *    `answerabilityCheck` on the slot `unanswerableRefusal` (path
 *    `candidate/templates/<slot>`, reported once: a template that exists
 *    without a default-language entry is already reported by the template
 *    check); `answerabilityVotes` (1, 3, 5 or 7) is structural.
 *
 * Every violation is reported with the property path `candidate/<field>` or
 * `candidate/<list>/<index>[/<key>]`; all violations are collected and thrown
 * together as an API Platform {@see ValidationException} (422). Structural
 * violations are thrown on their own, before any reference is resolved.
 */
final class AgentConfigurationValidator
{
    public const string PATH_PREFIX = 'candidate';

    /** Keywords whose value is one subschema. */
    private const array SUBSCHEMA_KEYWORDS = [
        'additionalProperties', 'items', 'contains', 'not', 'if', 'then', 'else',
        'propertyNames', 'unevaluatedItems', 'unevaluatedProperties', 'additionalItems',
    ];

    /** Keywords whose value is a list of subschemas. */
    private const array SUBSCHEMA_LIST_KEYWORDS = ['allOf', 'anyOf', 'oneOf', 'prefixItems'];

    /** Keywords whose value maps names to subschemas. */
    private const array SUBSCHEMA_MAP_KEYWORDS = ['properties', 'patternProperties', '$defs', 'definitions', 'dependentSchemas'];

    public function __construct(
        private readonly ProviderModelResolver $models,
        private readonly ModelAvailabilityCheck $modelCheck,
        private readonly ConversationReferences $references,
        private readonly McpConnectionResolver $connectionResolver,
        private readonly EntityManagerInterface $entityManager,
        private readonly ?ToolModelCheck $toolModelCheck = null,
    ) {
    }

    /**
     * @param array<string, mixed> $candidate
     *
     * @throws ValidationException with every violation found
     */
    public function validateForPublish(array $candidate): AgentConfiguration
    {
        $violations = new ConstraintViolationList();

        foreach (AgentConfiguration::structureErrors($candidate) as $pointer => $message) {
            $violations->add(self::violation($message, self::PATH_PREFIX.$pointer, $candidate));
        }
        if (\count($violations) > 0) {
            throw new ValidationException($violations);
        }

        $configuration = AgentConfiguration::fromArray($candidate);

        $model = $this->resolveModel($configuration, $violations, $candidate);
        $collections = $this->resolveCollections($configuration, $violations, $candidate);
        $mcpTools = $this->resolveMcpTools($configuration, $violations, $candidate);
        $this->checkToolModel($model, $mcpTools, $configuration->artifactTools, $violations, $candidate);
        $this->checkModelOptions($model, $configuration, $violations, $candidate);
        $this->checkSchemas($configuration, $violations, $candidate);
        $this->checkLanguages($configuration, $violations, $candidate);
        $this->checkRules($configuration, $violations, $candidate);
        $this->checkTemplates($configuration, $violations, $candidate);
        $this->checkStartMessage($configuration, $violations, $candidate);
        $this->checkGates($configuration, $violations, $candidate);

        if (\count($violations) > 0) {
            throw new ValidationException($violations);
        }

        return new AgentConfiguration(
            instructions: $configuration->instructions,
            model: $model?->getId()->toRfc4122(),
            collections: $collections,
            retrievalDepth: $configuration->retrievalDepth,
            mcpTools: $mcpTools,
            responseSchema: $configuration->responseSchema,
            startSchema: $configuration->startSchema,
            maxToolCalls: $configuration->maxToolCalls,
            maxUserTurns: $configuration->maxUserTurns,
            languages: $configuration->languages,
            defaultLanguage: $configuration->defaultLanguage,
            rules: $configuration->rules,
            templates: $configuration->templates,
            hybrid: $configuration->hybrid,
            hybridWeight: $configuration->hybridWeight,
            contextMaxChunks: $configuration->contextMaxChunks,
            contextMaxChars: $configuration->contextMaxChars,
            contextExpansionMaxChars: $configuration->contextExpansionMaxChars,
            contextInstructions: $configuration->contextInstructions,
            injectionGuard: $configuration->injectionGuard,
            answerabilityCheck: $configuration->answerabilityCheck,
            answerabilityVotes: $configuration->answerabilityVotes,
            followUps: $configuration->followUps,
            artifactTools: $configuration->artifactTools,
            modelOptions: $configuration->modelOptions,
            startMessage: $configuration->startMessage,
        );
    }

    /**
     * Resolves the model reference to its catalogue entry and checks the
     * model live against the entry's connection.
     *
     * @param array<string, mixed> $candidate
     *
     * @return ProviderModel|null null after a violation (also when the live check failed)
     */
    private function resolveModel(AgentConfiguration $configuration, ConstraintViolationList $violations, array $candidate): ?ProviderModel
    {
        try {
            $model = $this->models->resolve($configuration->model, ModelCapability::COMPLETION);
        } catch (ProviderModelReferenceException $e) {
            $violations->add($e->violationAt(self::PATH_PREFIX.'/model', $candidate));

            return null;
        }

        try {
            $this->modelCheck->assertAvailable($model->getApiConfiguration(), $model->getName());
        } catch (ModelUnavailableException $e) {
            $violations->add(self::violation($e->getMessage(), self::PATH_PREFIX.'/model', $candidate));

            return null;
        }

        return $model;
    }

    /**
     * The tools capability of the model, only for a model that resolved
     * and passed the live check and at least one resolved tool or
     * `artifactTools` on. The artifact tools count even though a turn
     * offers them only in an owned, non-anonymous conversation: a version
     * whose model cannot call tools would otherwise publish and fail at the
     * first such turn. The message names the field(s) that attach tools.
     *
     * @param list<array{connection: string, name: string, terminal: bool}> $mcpTools
     * @param array<string, mixed>                                          $candidate
     */
    private function checkToolModel(?ProviderModel $model, array $mcpTools, bool $artifactTools, ConstraintViolationList $violations, array $candidate): void
    {
        if (null === $this->toolModelCheck || null === $model || ([] === $mcpTools && !$artifactTools)) {
            return;
        }

        $fields = implode(' and ', array_keys(array_filter(['mcpTools' => [] !== $mcpTools, 'artifactTools' => $artifactTools])));
        $message = $this->toolModelCheck->violation($model->getApiConfiguration(), $model->getName(), $fields);
        if (null !== $message) {
            $violations->add(self::violation($message, self::PATH_PREFIX.'/model', $candidate));
        }
    }

    /**
     * The model options against the schema of the model, only for a model
     * that resolved and passed the live check; the limits of the entry
     * apply unless it is stale.
     *
     * @param array<string, mixed> $candidate
     */
    private function checkModelOptions(?ProviderModel $model, AgentConfiguration $configuration, ConstraintViolationList $violations, array $candidate): void
    {
        if (null === $model || [] === $configuration->modelOptions) {
            return;
        }

        $connection = $model->getApiConfiguration();
        $schema = ModelOptionsSchema::of(ProviderType::of($connection), $model->getName(), $model->isStale() ? null : $model);
        $subject = \sprintf('Model "%s" of connection "%s"', $model->getName(), ProviderType::nameOf($connection));
        foreach (ModelOptionsSchema::errors($schema, $configuration->modelOptions, $subject) as $key => $message) {
            $violations->add(self::violation($message, self::PATH_PREFIX.'/modelOptions/'.$key, $candidate));
        }
    }

    /**
     * @param array<string, mixed> $candidate
     *
     * @return list<string> full UUIDs, first-seen order
     */
    private function resolveCollections(AgentConfiguration $configuration, ConstraintViolationList $violations, array $candidate): array
    {
        $ids = [];
        foreach ($configuration->collections as $index => $reference) {
            try {
                foreach ($this->references->collectionIds([$reference]) as $id) {
                    $ids[$id] = true;
                }
            } catch (UnprocessableEntityHttpException $e) {
                $violations->add(self::violation($e->getMessage(), \sprintf('%s/collections/%d', self::PATH_PREFIX, $index), $candidate));
            }
        }

        return array_keys($ids);
    }

    /**
     * @param array<string, mixed> $candidate
     *
     * @return list<array{connection: string, name: string, terminal: bool}>
     */
    private function resolveMcpTools(AgentConfiguration $configuration, ConstraintViolationList $violations, array $candidate): array
    {
        $resolved = [];
        /** @var array<string, int> $seen tool name => index of its first entry */
        $seen = [];

        foreach ($configuration->mcpTools as $index => $tool) {
            $path = \sprintf('%s/mcpTools/%d', self::PATH_PREFIX, $index);

            if (isset($seen[$tool['name']])) {
                $violations->add(self::violation(
                    \sprintf('MCP tool "%s" is already selected by mcpTools entry %d; a tool name may occur only once, because a provider cannot tell two tools of the same name apart.', $tool['name'], $seen[$tool['name']]),
                    $path.'/name',
                    $candidate,
                ));
                continue;
            }
            $seen[$tool['name']] = $index;

            if ($configuration->artifactTools && \in_array($tool['name'], ArtifactToolkit::TOOL_NAMES, true)) {
                $violations->add(self::violation(
                    \sprintf('MCP tool "%s" collides with the artifact tool of the same name, which artifactTools adds to every turn of a conversation; a tool name may occur only once, because a provider cannot tell two tools of the same name apart. Remove the entry or turn artifactTools off.', $tool['name']),
                    $path.'/name',
                    $candidate,
                ));
                continue;
            }

            try {
                $connection = $this->connectionResolver->resolve($tool['connection']);
            } catch (UnprocessableEntityHttpException $e) {
                $violations->add(self::violation($e->getMessage(), $path.'/connection', $candidate));
                continue;
            }

            if (!$this->toolExists($connection, $tool['name'])) {
                $violations->add(self::violation(
                    \sprintf('MCP connection "%s" offers no tool "%s" in the tool catalogue.', $connection->getName(), $tool['name']),
                    $path.'/name',
                    $candidate,
                ));
                continue;
            }

            $resolved[] = [
                'connection' => $connection->getId()->toRfc4122(),
                'name' => $tool['name'],
                'terminal' => $tool['terminal'],
            ];
        }

        return $resolved;
    }

    private function toolExists(ApiConfiguration $connection, string $name): bool
    {
        return null !== $this->entityManager->getRepository(McpTool::class)
            ->findOneBy(['apiConfiguration' => $connection, 'name' => $name]);
    }

    /**
     * @param array<string, mixed> $candidate
     */
    private function checkSchemas(AgentConfiguration $configuration, ConstraintViolationList $violations, array $candidate): void
    {
        foreach (['responseSchema' => $configuration->responseSchema, 'startSchema' => $configuration->startSchema] as $field => $schema) {
            if (null === $schema) {
                continue;
            }

            $error = self::schemaError($schema);
            if (null !== $error) {
                $violations->add(self::violation(\sprintf('Not a valid JSON schema: %s', $error), self::PATH_PREFIX.'/'.$field, $candidate));
                continue;
            }

            if ('startSchema' === $field && 'object' !== ($schema['type'] ?? null)) {
                $violations->add(self::violation('The start schema must declare "type": "object".', self::PATH_PREFIX.'/startSchema', $candidate));
            }
        }
    }

    /**
     * Loads the schema and each nested subschema with opis (default draft
     * 2020-12, as in the turn) and resolves its references against a probe
     * value. opis parses subschemas lazily, so the nested ones are loaded on
     * their own to find invalid keywords below the top level.
     *
     * @param array<string, mixed> $schema
     *
     * @return string|null the first error, null for a valid schema
     */
    public static function schemaError(array $schema): ?string
    {
        $json = AgentConfiguration::toJsonValue($schema);
        if (!\is_object($json)) {
            return 'the schema must be an object';
        }

        $declared = $json->{'$schema'} ?? null;
        if (null !== $declared && (!\is_string($declared) || null === new Validator()->parser()->parseDraftVersion($declared))) {
            return \sprintf('unsupported "$schema" %s (supported: drafts 06, 07, 2019-09 and 2020-12)', json_encode($declared, \JSON_UNESCAPED_SLASHES));
        }

        try {
            foreach (self::subschemas($json) as $subschema) {
                $loaded = new Validator()->loader()->loadObjectSchema($subschema);
                if ($loaded instanceof ExceptionSchema) {
                    // Rethrows the parse error it carries.
                    new Validator()->validate(null, $loaded);
                }
            }
            new Validator()->validate(null, $json);
        } catch (SchemaException $e) {
            return $e->getMessage();
        }

        return null;
    }

    /**
     * The schema itself and every nested object subschema, depth first.
     * Nested ones inherit the `$schema` of the root so they are parsed with
     * the same draft.
     *
     * @return iterable<object>
     */
    private static function subschemas(object $schema, ?string $draft = null): iterable
    {
        $draft ??= isset($schema->{'$schema'}) && \is_string($schema->{'$schema'}) ? $schema->{'$schema'} : null;

        $own = clone $schema;
        if (null !== $draft && !isset($own->{'$schema'})) {
            $own->{'$schema'} = $draft;
        }
        yield $own;

        foreach (get_object_vars($schema) as $keyword => $value) {
            if (\in_array($keyword, self::SUBSCHEMA_KEYWORDS, true) && \is_object($value)) {
                yield from self::subschemas($value, $draft);
            } elseif (\in_array($keyword, self::SUBSCHEMA_LIST_KEYWORDS, true) && \is_array($value)) {
                foreach ($value as $item) {
                    if (\is_object($item)) {
                        yield from self::subschemas($item, $draft);
                    }
                }
            } elseif (\in_array($keyword, self::SUBSCHEMA_MAP_KEYWORDS, true) && \is_object($value)) {
                foreach (get_object_vars($value) as $item) {
                    if (\is_object($item)) {
                        yield from self::subschemas($item, $draft);
                    }
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $candidate
     */
    private function checkLanguages(AgentConfiguration $configuration, ConstraintViolationList $violations, array $candidate): void
    {
        if (!\in_array($configuration->defaultLanguage, $configuration->languages, true)) {
            $violations->add(self::violation(
                \sprintf('The default language "%s" is not one of the languages (%s).', $configuration->defaultLanguage, implode(', ', $configuration->languages)),
                self::PATH_PREFIX.'/defaultLanguage',
                $candidate,
            ));
        }

        if (!\array_key_exists($configuration->defaultLanguage, $configuration->instructions)) {
            $violations->add(self::violation(
                \sprintf('The instructions have no entry for the default language "%s".', $configuration->defaultLanguage),
                self::PATH_PREFIX.'/instructions',
                $candidate,
            ));
        }

        foreach (array_keys($configuration->instructions) as $language) {
            if (!\in_array((string) $language, $configuration->languages, true)) {
                $violations->add(self::violation(
                    \sprintf('The instructions for "%s" belong to no language of the agent (%s).', $language, implode(', ', $configuration->languages)),
                    \sprintf('%s/instructions/%s', self::PATH_PREFIX, $language),
                    $candidate,
                ));
            }
        }

        foreach (array_keys($configuration->contextInstructions) as $language) {
            if (!\in_array((string) $language, $configuration->languages, true)) {
                $violations->add(self::violation(
                    \sprintf('The context instructions for "%s" belong to no language of the agent (%s).', $language, implode(', ', $configuration->languages)),
                    \sprintf('%s/contextInstructions/%s', self::PATH_PREFIX, $language),
                    $candidate,
                ));
            }
        }
    }

    /**
     * @param array<string, mixed> $candidate
     */
    private function checkRules(AgentConfiguration $configuration, ConstraintViolationList $violations, array $candidate): void
    {
        foreach ($configuration->rules as $index => $rule) {
            $path = \sprintf('%s/rules/%d', self::PATH_PREFIX, $index);
            $match = $rule['match'];

            if ('pattern' === $match['type']) {
                $error = self::patternError((string) $match['pattern'], (string) $match['flags']);
                if (null !== $error) {
                    $violations->add(self::violation(\sprintf('The pattern does not compile: %s', $error), $path.'/match/pattern', $candidate));
                }
            }

            if ('language' === $match['type']) {
                foreach ($match['languages'] as $position => $language) {
                    if (!\in_array($language, $configuration->languages, true)) {
                        $violations->add(self::violation(
                            \sprintf('The language "%s" is not one of the languages of the agent (%s).', $language, implode(', ', $configuration->languages)),
                            \sprintf('%s/match/languages/%d', $path, $position),
                            $candidate,
                        ));
                    }
                }
            }

            $template = $rule['template'];
            $message = match (true) {
                Rule::CONTINUE === $rule['outcome'] => null === $template
                    ? null
                    : \sprintf('A rule with outcome "continue" takes no template (got "%s").', $template),
                null === $template => \sprintf('A rule with outcome "%s" requires a template.', $rule['outcome']),
                TemplateSet::isReserved($template) => \sprintf('The template "%s" is a reserved slot (%s) and cannot be used by a rule.', $template, implode(', ', TemplateSet::RESERVED)),
                !\array_key_exists($template, $configuration->templates) => \sprintf('Unknown template "%s".', $template),
                default => null,
            };
            if (null !== $message) {
                $violations->add(self::violation($message, $path.'/template', $candidate));
            }
        }
    }

    /**
     * The compile check of a `pattern` rule: null when the compiled pattern
     * ({@see Rule::compilePattern()}) compiles, else the PCRE error message
     * (`preg_last_error_msg()`, with the compile warning when there is one).
     */
    public static function patternError(string $pattern, string $flags): ?string
    {
        error_clear_last();
        $result = @preg_match(Rule::compilePattern($pattern, $flags), '');
        if (false !== $result && \PREG_NO_ERROR === preg_last_error()) {
            return null;
        }

        $error = preg_last_error_msg();
        $warning = error_get_last()['message'] ?? null;

        return null === $warning ? $error : \sprintf('%s (%s)', $error, preg_replace('/^preg_match\(\): /', '', $warning));
    }

    /**
     * @param array<string, mixed> $candidate
     */
    private function checkTemplates(AgentConfiguration $configuration, ConstraintViolationList $violations, array $candidate): void
    {
        $common = [...CallerVariables::SYSTEM_VALUES, ...array_map(strval(...), array_keys((array) ($configuration->startSchema['properties'] ?? [])))];

        foreach ($configuration->templates as $name => $texts) {
            $name = (string) $name;
            $path = \sprintf('%s/templates/%s', self::PATH_PREFIX, $name);

            if (!\array_key_exists($configuration->defaultLanguage, $texts)) {
                $violations->add(self::violation(
                    \sprintf('The template "%s" has no text for the default language "%s".', $name, $configuration->defaultLanguage),
                    $path,
                    $candidate,
                ));
            }

            $allowed = [...$common, ...TemplateSet::contextPlaceholders($name)];
            foreach ($texts as $language => $text) {
                $language = (string) $language;
                if (!\in_array($language, $configuration->languages, true)) {
                    $violations->add(self::violation(
                        \sprintf('The text of template "%s" for "%s" belongs to no language of the agent (%s).', $name, $language, implode(', ', $configuration->languages)),
                        $path.'/'.$language,
                        $candidate,
                    ));
                }

                if (TemplateSet::FRAME === $name) {
                    $answers = TemplateSet::answerCount($text);
                    if (1 !== $answers) {
                        $violations->add(self::violation(
                            \sprintf('The template "%s" must contain the placeholder "{{%s}}" exactly once (found %d).', $name, TemplateSet::ANSWER, $answers),
                            $path.'/'.$language,
                            $candidate,
                        ));
                    }
                }

                foreach (InstructionTemplate::placeholders($text) as $placeholder) {
                    if (!\in_array($placeholder, $allowed, true)) {
                        $violations->add(self::violation(
                            \sprintf('The placeholder "{{%s}}" is not available in template "%s" (available: %s).', $placeholder, $name, implode(', ', $allowed)),
                            $path.'/'.$language,
                            $candidate,
                        ));
                    }
                }
            }
        }
    }

    /**
     * A start message needs a text for the default language, only tags of
     * the agent's languages and only placeholders the instructions may use
     * (system values and the `startSchema` properties).
     *
     * @param array<string, mixed> $candidate
     */
    private function checkStartMessage(AgentConfiguration $configuration, ConstraintViolationList $violations, array $candidate): void
    {
        if (null === $configuration->startMessage) {
            return;
        }

        $path = self::PATH_PREFIX.'/startMessage';
        if ('' === trim($configuration->startMessage[$configuration->defaultLanguage] ?? '')) {
            $violations->add(self::violation(
                \sprintf('The start message has no text for the default language "%s".', $configuration->defaultLanguage),
                $path,
                $candidate,
            ));
        }

        $allowed = [...CallerVariables::SYSTEM_VALUES, ...array_map(strval(...), array_keys((array) ($configuration->startSchema['properties'] ?? [])))];
        foreach ($configuration->startMessage as $language => $text) {
            $language = (string) $language;
            if (!\in_array($language, $configuration->languages, true)) {
                $violations->add(self::violation(
                    \sprintf('The start message for "%s" belongs to no language of the agent (%s).', $language, implode(', ', $configuration->languages)),
                    $path.'/'.$language,
                    $candidate,
                ));
            }
            foreach (InstructionTemplate::placeholders($text) as $placeholder) {
                if (!\in_array($placeholder, $allowed, true)) {
                    $violations->add(self::violation(
                        \sprintf('The placeholder "{{%s}}" is not available in the start message (available: %s).', $placeholder, implode(', ', $allowed)),
                        $path.'/'.$language,
                        $candidate,
                    ));
                }
            }
        }
    }

    /**
     * The refusal slot of every gate that is switched on needs a non-empty
     * text for the default language.
     *
     * @param array<string, mixed> $candidate
     */
    private function checkGates(AgentConfiguration $configuration, ConstraintViolationList $violations, array $candidate): void
    {
        $slots = array_filter([
            TemplateSet::INJECTION_REFUSAL => $configuration->injectionGuard ? 'injectionGuard' : null,
            TemplateSet::UNANSWERABLE_REFUSAL => $configuration->answerabilityCheck ? 'answerabilityCheck' : null,
        ]);

        foreach ($slots as $slot => $switch) {
            $texts = $configuration->templates[$slot] ?? null;
            if (null !== $texts && !\array_key_exists($configuration->defaultLanguage, $texts)) {
                // Reported by checkTemplates() at the same path.
                continue;
            }
            if ('' !== trim($texts[$configuration->defaultLanguage] ?? '')) {
                continue;
            }

            $violations->add(self::violation(
                \sprintf('"%s" is on, so the template "%s" needs a text for the default language "%s".', $switch, $slot, $configuration->defaultLanguage),
                \sprintf('%s/templates/%s', self::PATH_PREFIX, $slot),
                $candidate,
            ));
        }
    }

    /**
     * @param array<string, mixed> $candidate
     */
    private static function violation(string $message, string $propertyPath, array $candidate): ConstraintViolation
    {
        return new ConstraintViolation($message, $message, [], $candidate, $propertyPath, null);
    }
}
// - revised 2026-10-14
// - revised 2026-10-16
