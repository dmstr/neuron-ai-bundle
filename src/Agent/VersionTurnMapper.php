<?php

// file generated with AI assistance: Claude Code - 2026-10-03 10:33:41 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Agent;

use Dmstr\ApiConfiguration\Entity\ApiConfiguration;
use Dmstr\NeuronAiBundle\Artifact\ArtifactAccess;
use Dmstr\NeuronAiBundle\Artifact\ArtifactAccessResolver;
use Dmstr\NeuronAiBundle\Artifact\ArtifactToolkit;
use Dmstr\NeuronAiBundle\Artifact\ArtifactToolkitFactory;
use Dmstr\NeuronAiBundle\Agent\Rule\RuleSet;
use Dmstr\NeuronAiBundle\Agent\Template\TemplateSet;
use Dmstr\NeuronAiBundle\Conversation\ContextLimits;
use Dmstr\NeuronAiBundle\Conversation\ContextRetriever;
use Dmstr\NeuronAiBundle\Conversation\TurnTexts;
use Dmstr\NeuronAiBundle\Entity\AgentVersion;
use Dmstr\NeuronAiBundle\Entity\Collection;
use Dmstr\NeuronAiBundle\Entity\Conversation;
use Dmstr\NeuronAiBundle\Entity\Refs\McpTool;
use Dmstr\NeuronAiBundle\Entity\Refs\ProviderModel;
use Dmstr\NeuronAiBundle\Mcp\McpConnectionResolver;
use Dmstr\NeuronAiBundle\Mcp\McpToolFactory;
use Dmstr\NeuronAiBundle\Provider\Connection\ProviderConnectionReferenceException;
use Dmstr\NeuronAiBundle\Provider\Connection\ProviderConnectionResolver;
use Dmstr\NeuronAiBundle\Provider\ModelOptions;
use Dmstr\NeuronAiBundle\Provider\ProviderType;
use Doctrine\ORM\EntityManagerInterface;
use NeuronAI\Tools\ToolInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Maps a published {@see AgentVersion} plus the caller's variables,
 * language and optional `maxToolCalls` to the {@see TurnConfiguration} of
 * one turn. Everything is resolved and checked here, before any provider is
 * contacted; MCP tools are built, never called, so no MCP server is
 * reached either.
 *
 *  - language: null is the version's `defaultLanguage`; a tag outside
 *    `languages` is a 422 at `language`; without an instructions text for
 *    the tag, the `defaultLanguage` text applies;
 *  - variables: {@see CallerVariables} (system value names, scalar values,
 *    declared by and valid against `startSchema`), 422 at `variables/...`;
 *  - instructions: {@see InstructionTemplate} with the variables and the
 *    system values `date` (today in UTC from the clock), `language` (the
 *    effective tag) and `agentName`; an unfilled placeholder is a 422 at
 *    `variables/<name>`;
 *  - rules and templates: {@see TemplateSet} over the version's
 *    `templates` and {@see RuleSet::fromConfiguration()} over its `rules`,
 *    with the same placeholder values as the instructions (a missing
 *    value renders as '' in a template); a rule that fails at runtime is
 *    logged with the agent slug and the version number; with the same
 *    values the `frame` template ({@see TemplateSet::frame()}) and the turn
 *    texts ({@see TurnTexts}: limit reasons, forced-close fallback);
 *  - model and provider connection: the stored model UUID is looked up in
 *    the model catalogue ({@see ProviderModel}, database only), which
 *    becomes {@see TurnConfiguration::$providerModel}; its name is
 *    {@see TurnConfiguration::$model}. The entry's connection must be an
 *    active `ollama` or `anthropic` connection
 *    ({@see ProviderConnectionResolver::assertChat()}), which becomes
 *    {@see TurnConfiguration::$connection}; `provider` is its type
 *    ({@see ProviderType::of()}). The entry's `available` flag is not
 *    checked, and the model is checked live only at the publish, never
 *    here, because the mapper runs on every turn, gate call and judge call;
 *    a model the provider dropped after the publish (or a placeholder entry
 *    of the migration that no scan confirmed) fails as a provider error of
 *    the turn;
 *  - collections, retrieval depth and hybrid search through
 *    {@see RetrievalScope} (depth: the version's `retrievalDepth`, else
 *    `dmstr_neuron_ai.store.top_k`; `hybrid` and `hybridWeight` of the
 *    version, vector-only without them), also available alone as
 *    {@see self::retrievalScope()};
 *  - collections must still exist, every `mcpTools` entry must resolve to
 *    an active connection with a buildable catalogue row
 *    ({@see McpToolFactory::forToolsStrict()}): the turn never runs with
 *    fewer tools than the version references; a turn that must not offer
 *    MCP tools (`$withMcpTools` false, an anonymous conversation) skips
 *    `mcpTools` entirely: no resolution, no check, no tool and no MCP
 *    transport is built, and tools, MCP connections and terminal tools
 *    are empty; the version's terminal tools are limited to the tools
 *    actually built (a `forward_jwt` connection without a JWT drops its
 *    write tools, and a dropped terminal tool is not forced);
 *  - `maxToolCalls` through {@see LimitResolver} (version, settings,
 *    bundle default, request: lower only), `maxUserTurns` base only
 *    (version, settings);
 *  - context shaping ({@see contextLimits()}, {@see contextInstruction()}):
 *    `contextMaxChunks`, `contextMaxChars` and `contextExpansionMaxChars`
 *    as {@see ContextLimits}; the instruction text in front of the context
 *    from `contextInstructions` (effective language, then
 *    `defaultLanguage`, then the bundle's `turn.context_instruction`).
 *    {@see AgentConfiguration::fromArray()} fills missing keys with the new
 *    defaults, so each key is checked on the raw stored configuration
 *    instead: a version stored without it keeps the legacy behaviour
 *    (bundle cap `turn.context_max_chunks`/`context_max_chars`,
 *    no expansion, {@see ContextRetriever::LEGACY_INSTRUCTION});
 *  - answer gates: `injectionGuard`, `answerabilityCheck`,
 *    `answerabilityVotes` and `followUps` unchanged; their defaults are
 *    off (votes 1), so a version stored without them needs no raw-key
 *    check;
 *  - artifact tools: only with an {@see ArtifactAccess}, which
 *    only {@see artifactAccessFor()} produces (for a conversation turn,
 *    through {@see ArtifactAccessResolver}); every other caller (agent
 *    chat, judge, pinning, conversation start) passes none and gets none.
 *    The {@see ArtifactToolkit} of the access contributes its wrapped
 *    tools after the MCP tools (an MCP tool of the same name is a 422,
 *    the runtime guard behind the publish check) and its guidelines,
 *    appended to the rendered instructions ({@see withArtifactGuidelines()}),
 *    so the model reads them in the system prompt of the plain and the
 *    streamed turn alike. The tools are wrapped single tools, not the
 *    toolkit, so neuron-ai does not add the guidelines a second time.
 *
 * A reference that no longer resolves raises a {@see StaleReferenceException}
 * at `configuration/model` (no model UUID, which is a version published
 * before model references that the migration `Version20261008180000`
 * could not rewrite, e.g. one published before provider connections; an
 * entry that no longer exists; a connection that is inactive or of
 * another type), `configuration/collections/<i>`, `configuration/mcpTools/<i>/connection`
 * or `configuration/mcpTools/<i>/name`.
 */
final class VersionTurnMapper
{
    public function __construct(
        private readonly ProviderConnectionResolver $providerConnections,
        private readonly EntityManagerInterface $entityManager,
        private readonly McpConnectionResolver $connectionResolver,
        private readonly McpToolFactory $toolFactory,
        private readonly LimitResolver $limitResolver,
        private readonly ?AgentLimitDefaultsInterface $limitDefaults,
        private readonly ClockInterface $clock,
        private readonly int $defaultRetrievalDepth,
        private readonly ?int $maxToolCallsDefault,
        private readonly ?LoggerInterface $logger = null,
        private readonly int $contextMaxChunks = 8,
        private readonly int $contextMaxChars = 6000,
        private readonly string $contextInstruction = ContextRetriever::DEFAULT_INSTRUCTION,
        private readonly ?ArtifactToolkitFactory $artifactToolkits = null,
        private readonly ?ArtifactAccessResolver $artifactAccessResolver = null,
    ) {
    }

    /**
     * The artifact access of the next turn of $conversation, whose pinned
     * version is $configuration: null when the version has no
     * `artifactTools`, or when the gate ({@see ArtifactAccessResolver})
     * refuses (logged there). Creates the conversation's working folder, so
     * call it under the turn lock. The resolver lives here because both
     * turn services build their {@see \Dmstr\NeuronAiBundle\Conversation\TurnPersistence}
     * from this mapper; it is no mapping step of its own.
     */
    public function artifactAccessFor(Conversation $conversation, AgentConfiguration $configuration): ?ArtifactAccess
    {
        if (!$configuration->artifactTools) {
            return null;
        }

        if (null === $this->artifactAccessResolver || null === $this->artifactToolkits) {
            $this->logger?->warning('Artifact tools of conversation {conversation} were not offered: the artifact services are not configured.', [
                'conversation' => $conversation->getId()->toRfc4122(),
            ]);

            return null;
        }

        return $this->artifactAccessResolver->resolve($conversation, $configuration);
    }

    /**
     * The instructions of a turn with artifact tools: $instructions, then
     * the toolkit's guidelines in the block neuron-ai uses for toolkit
     * guidelines (`<TOOLS-GUIDELINES>`, headed by the tool names). The
     * guidelines name no server path.
     */
    public static function withArtifactGuidelines(string $instructions, ArtifactToolkit $toolkit): string
    {
        $guidelines = trim((string) $toolkit->guidelines());
        if ('' === $guidelines) {
            return $instructions;
        }

        $names = array_map(static fn (ToolInterface $tool): string => $tool->getName(), $toolkit->tools());
        $block = '<TOOLS-GUIDELINES>'.\PHP_EOL.'# '.implode(', ', $names).\PHP_EOL.$guidelines.\PHP_EOL.'</TOOLS-GUIDELINES>';

        return '' === trim($instructions) ? $block : rtrim($instructions).\PHP_EOL.\PHP_EOL.$block;
    }

    /**
     * @param array<array-key, mixed> $variables    caller variables for the instructions
     * @param string|null             $language     null: the version's default language
     * @param int|null                $maxToolCalls request bound; may only lower the resolved limit
     * @param bool                    $withMcpTools false: the version's `mcpTools` are neither checked nor built
     *                                              (tools, MCP connections and terminal tools are empty)
     * @param ArtifactAccess|null     $artifacts    the turn's artifact access ({@see artifactAccessFor()}); null: no artifact tools
     *
     * @throws StaleReferenceException          for a reference of the version that no longer resolves
     * @throws UnprocessableEntityHttpException for an unsupported language or invalid variables, and for an MCP tool
     *                                          named like an artifact tool of the turn
     * @throws \LogicException                  for an artifact access without a toolkit factory
     */
    public function map(AgentVersion $version, array $variables = [], ?string $language = null, ?int $maxToolCalls = null, bool $withMcpTools = true, ?ArtifactAccess $artifacts = null): TurnConfiguration
    {
        $configuration = AgentConfiguration::fromArray($version->getConfiguration());
        $agent = $version->getAgent();

        $language ??= $configuration->defaultLanguage;
        if (!\in_array($language, $configuration->languages, true)) {
            throw new UnprocessableEntityHttpException(\sprintf('language: "%s" is not supported by agent "%s" (supported: %s).', $language, $agent->getSlug(), implode(', ', $configuration->languages)));
        }

        $values = CallerVariables::validate($variables, $configuration->startSchema);
        $values += [
            'date' => $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d'),
            'language' => $language,
            'agentName' => $agent->getName(),
        ];
        $text = $configuration->instructions[$language] ?? '';
        if ('' === $text) {
            $text = $configuration->instructions[$configuration->defaultLanguage] ?? '';
        }
        $instructions = InstructionTemplate::render($text, $values);
        $startText = $configuration->startMessage[$language] ?? $configuration->startMessage[$configuration->defaultLanguage] ?? null;
        $startMessage = null === $startText || '' === trim($startText) ? null : InstructionTemplate::render($startText, $values);

        $templates = new TemplateSet($configuration->templates, $configuration->defaultLanguage);
        $rules = RuleSet::fromConfiguration($configuration->rules, $templates, $values, $this->logger ?? new NullLogger(), [
            'agent' => $agent->getSlug(),
            'version' => $version->getVersionNumber(),
        ]);

        [$connection, $model] = $this->resolveModel($version, $configuration);
        $this->checkCollections($version, $configuration);
        $scope = RetrievalScope::of($configuration, $this->defaultRetrievalDepth);
        [$tools, $connections, $terminalTools] = $withMcpTools ? $this->buildTools($version, $configuration) : [[], [], []];
        if (null !== $artifacts) {
            $toolkit = ($this->artifactToolkits ?? throw new \LogicException('Artifact tools need the ArtifactToolkitFactory.'))->forAccess($artifacts);
            $tools = self::appendArtifactTools($tools, $toolkit);
            $instructions = self::withArtifactGuidelines($instructions, $toolkit);
        }

        return new TurnConfiguration(
            version: $version,
            provider: ProviderType::of($connection),
            model: $model->getName(),
            instructions: $instructions,
            responseSchema: $configuration->responseSchema,
            collections: $scope->collections,
            retrievalDepth: $scope->depth,
            tools: $tools,
            mcpConnections: $connections,
            terminalTools: $terminalTools,
            maxToolCalls: $this->limitResolver->resolve($configuration->maxToolCalls, $this->limitDefaults?->maxToolCalls(), $this->maxToolCallsDefault, $maxToolCalls),
            maxUserTurns: $this->limitResolver->resolve($configuration->maxUserTurns, $this->limitDefaults?->maxUserTurns(), null, null),
            modelOptions: ModelOptions::fromArray($configuration->modelOptions),
            language: $language,
            rules: $rules,
            templates: $templates,
            frame: $templates->frame($language, $values),
            texts: new TurnTexts($templates, $language, $values),
            hybrid: $scope->hybrid,
            hybridWeight: $scope->hybridWeight,
            contextLimits: $this->contextLimits($version->getConfiguration(), $configuration),
            contextInstruction: $this->contextInstruction($version->getConfiguration(), $configuration, $language),
            injectionGuard: $configuration->injectionGuard,
            answerabilityCheck: $configuration->answerabilityCheck,
            answerabilityVotes: $configuration->answerabilityVotes,
            followUps: $configuration->followUps,
            connection: $connection,
            providerModel: $model,
            startMessage: $startMessage,
        );
    }

    /**
     * The context limits of a version: per key the version's value, or the
     * legacy value when the stored configuration $stored lacks the key
     * (bundle cap, expansion 0).
     *
     * @param array<string, mixed> $stored the raw configuration of the version, before defaults are filled in
     */
    private function contextLimits(array $stored, AgentConfiguration $configuration): ContextLimits
    {
        return new ContextLimits(
            maxChunks: \array_key_exists('contextMaxChunks', $stored) ? $configuration->contextMaxChunks : $this->contextMaxChunks,
            maxChars: \array_key_exists('contextMaxChars', $stored) ? $configuration->contextMaxChars : $this->contextMaxChars,
            expansionMaxChars: \array_key_exists('contextExpansionMaxChars', $stored) ? $configuration->contextExpansionMaxChars : 0,
        );
    }

    /**
     * The instruction text in front of the context: the version's text for
     * $language, else for its `defaultLanguage`, else the bundle default; a
     * version stored without `contextInstructions` keeps the legacy text.
     * An empty text counts as missing.
     *
     * @param array<string, mixed> $stored the raw configuration of the version, before defaults are filled in
     */
    private function contextInstruction(array $stored, AgentConfiguration $configuration, string $language): string
    {
        if (!\array_key_exists('contextInstructions', $stored)) {
            return ContextRetriever::LEGACY_INSTRUCTION;
        }

        foreach ([$language, $configuration->defaultLanguage] as $tag) {
            $text = $configuration->contextInstructions[$tag] ?? '';
            if ('' !== $text) {
                return $text;
            }
        }

        return $this->contextInstruction;
    }

    /**
     * Collections, retrieval depth and hybrid search of $version, exactly as {@see map()}
     * puts them into the turn configuration, without mapping the rest (no
     * variables, no model resolution, no MCP tools, no existence check of
     * the collections).
     */
    public function retrievalScope(AgentVersion $version): RetrievalScope
    {
        return RetrievalScope::of(AgentConfiguration::fromArray($version->getConfiguration()), $this->defaultRetrievalDepth);
    }

    /**
     * The version's model entry and its provider connection (both from the
     * database, no provider call).
     *
     * @return array{0: ApiConfiguration, 1: ProviderModel}
     *
     * @throws StaleReferenceException at `configuration/model`
     */
    private function resolveModel(AgentVersion $version, AgentConfiguration $configuration): array
    {
        $id = $configuration->model;
        if (null === $id || !Uuid::isValid($id)) {
            throw StaleReferenceException::at($version, 'configuration/model', 'The version references no entry of the model catalogue (it was published before model references and could not be migrated); publish the agent again with a model.');
        }

        $model = $this->entityManager->find(ProviderModel::class, Uuid::fromString($id));
        if (!$model instanceof ProviderModel) {
            throw StaleReferenceException::at($version, 'configuration/model', \sprintf('The model entry "%s" does not exist (any more); publish the agent again with a model.', $id));
        }

        try {
            $connection = $this->providerConnections->assertChat($model->getApiConfiguration());
        } catch (ProviderConnectionReferenceException $e) {
            throw StaleReferenceException::at($version, 'configuration/model', $e->getMessage());
        }

        return [$connection, $model];
    }

    private function checkCollections(AgentVersion $version, AgentConfiguration $configuration): void
    {
        foreach ($configuration->collections as $index => $id) {
            $exists = Uuid::isValid($id)
                && null !== $this->entityManager->find(Collection::class, Uuid::fromString($id));
            if (!$exists) {
                throw StaleReferenceException::at($version, \sprintf('configuration/collections/%d', $index), \sprintf('Collection "%s" does not exist (any more).', $id));
            }
        }
    }

    /**
     * @return array{0: list<ToolInterface>, 1: list<string>, 2: list<string>} tools, connection names, terminal tool names
     */
    private function buildTools(AgentVersion $version, AgentConfiguration $configuration): array
    {
        $rows = [];
        /** @var array<string, string> $connections connection id => name, first-use order */
        $connections = [];
        $terminalTools = [];

        foreach ($configuration->mcpTools as $index => $tool) {
            $path = \sprintf('configuration/mcpTools/%d', $index);

            try {
                $connection = $this->connectionResolver->resolve($tool['connection']);
            } catch (UnprocessableEntityHttpException $e) {
                throw StaleReferenceException::at($version, $path.'/connection', $e->getMessage());
            }

            $row = $this->entityManager->getRepository(McpTool::class)
                ->findOneBy(['apiConfiguration' => $connection, 'name' => $tool['name']]);
            if (!$row instanceof McpTool) {
                throw StaleReferenceException::at($version, $path.'/name', self::missingTool($connection, $tool['name']));
            }

            $rows[] = $row;
            $connections[(string) $connection->getId()] ??= $connection->getName();
            if ($tool['terminal']) {
                $terminalTools[] = $tool['name'];
            }
        }

        try {
            $tools = $this->toolFactory->forToolsStrict($rows);
        } catch (\UnexpectedValueException $e) {
            // The code is the position of the unbuildable row, which is the
            // index of its mcpTools entry.
            $index = $e->getCode();
            $row = $rows[$index] ?? throw $e;

            throw StaleReferenceException::at($version, \sprintf('configuration/mcpTools/%d/name', $index), self::missingTool($row->getApiConfiguration(), $row->getName()));
        }

        // The factory may build fewer tools than referenced: a forward_jwt
        // connection without the person's JWT (replay, batch evaluation)
        // drops every tool that is not read-only. A dropped terminal tool
        // must not stay terminal, or ForcedClose::arm() would force the
        // provider to call a tool the turn does not offer.
        $built = array_map(static fn (ToolInterface $tool): string => $tool->getName(), $tools);
        $terminalTools = array_values(array_filter($terminalTools, static fn (string $name): bool => \in_array($name, $built, true)));

        return [$tools, array_values($connections), $terminalTools];
    }

    /**
     * $tools followed by the toolkit's tools; a tool name offered twice is
     * refused, because a provider cannot tell two tools of the same name
     * apart (the publish refuses such a version already; this guards
     * versions published before that check).
     *
     * @param list<ToolInterface> $tools the MCP tools of the turn
     *
     * @return list<ToolInterface>
     *
     * @throws UnprocessableEntityHttpException when an MCP tool is named like an artifact tool
     */
    private static function appendArtifactTools(array $tools, ArtifactToolkit $toolkit): array
    {
        $taken = array_map(static fn (ToolInterface $tool): string => $tool->getName(), $tools);
        foreach ($toolkit->tools() as $tool) {
            if (\in_array($tool->getName(), $taken, true)) {
                throw new UnprocessableEntityHttpException(\sprintf('MCP tool "%s" collides with the artifact tool of the same name; remove it from mcpTools or turn artifactTools off.', $tool->getName()));
            }
            $tools[] = $tool;
        }

        return $tools;
    }

    private static function missingTool(ApiConfiguration $connection, string $name): string
    {
        return \sprintf('MCP connection "%s" offers no tool "%s" in the tool catalogue.', $connection->getName(), $name);
    }
}

// - revised 2026-10-09
// - revised 2026-10-09
// - revised 2026-10-14
// - revised 2026-10-16
