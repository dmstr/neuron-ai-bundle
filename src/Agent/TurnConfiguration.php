<?php

// file generated with AI assistance: Claude Code - 2026-10-03 10:33:41 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Agent;

use Dmstr\ApiConfiguration\Entity\ApiConfiguration;
use Dmstr\NeuronAiBundle\Agent\Rule\RuleSet;
use Dmstr\NeuronAiBundle\Agent\Template\TemplateSet;
use Dmstr\NeuronAiBundle\Conversation\ContextLimits;
use Dmstr\NeuronAiBundle\Conversation\ContextRetriever;
use Dmstr\NeuronAiBundle\Conversation\TurnTexts;
use Dmstr\NeuronAiBundle\Entity\AgentVersion;
use Dmstr\NeuronAiBundle\Entity\Refs\ProviderModel;
use Dmstr\NeuronAiBundle\Provider\ModelOptions;
use NeuronAI\Tools\ToolInterface;

/**
 * Everything a turn takes from a published agent version, resolved and
 * checked by {@see VersionTurnMapper::map()}: the provider connection
 * (resolved from the database, active, of type `ollama` or `anthropic`) and
 * the model, the instructions of the effective language with all
 * placeholders substituted (followed by the guidelines of the turn's
 * artifact tools, if any), the existing collections, the built tool subset
 * (MCP tools, then artifact tools) and the resolved limits, plus the compiled pre-answer rules and the
 * version's templates (placeholder values bound by the mapper), the
 * rendered `frame` that wraps the final answer and the turn texts (limit
 * reasons, forced-close fallback) of the effective language, and the
 * context shaping of the version: the {@see ContextLimits} and the
 * instruction text in front of the context block (both the legacy values
 * for a version stored without the keys), and the switches of the answer
 * gates, read by
 * {@see \Dmstr\NeuronAiBundle\Conversation\Gate\TurnGates}; all off for a
 * version stored without them.
 *
 * `modelOptions` are the version's model options (null when it sets none;
 * a version stored before them maps its top-level `temperature` and
 * `maxTokens`, {@see AgentConfiguration::fromArray()}); consumers pass them
 * to the provider unchanged.
 *
 * `connection` is the resolved provider connection of the version;
 * `provider` is derived from it, the connection's type
 * ({@see \Dmstr\NeuronAiBundle\Provider\ProviderType::of()}), kept for logs,
 * records and the `done` event. The mapper always sets the connection;
 * the type stays nullable for configurations built without a provider
 * (unit tests of records, gates and limits), and every path that calls a
 * provider refuses a missing connection with a \LogicException.
 *
 * `providerModel` is the version's entry of the model catalogue; `model`
 * is its name and `connection` its connection. Like the connection it is
 * always set by the mapper and null only where no provider is called.
 */
final readonly class TurnConfiguration
{
    /**
     * @param array<string, mixed>|null $responseSchema
     * @param list<string>              $collections    full collection UUIDs as stored in the version
     * @param list<ToolInterface>       $tools          the version's MCP tool subset, in version order, then the artifact tools of the turn, if any
     * @param list<string>              $mcpConnections names of the connections the tools come from, unique, first-use order
     * @param list<string>              $terminalTools  names of the tools with `terminal: true`, in version order
     * @param int|null                  $maxToolCalls   resolved by {@see LimitResolver} (version, settings, bundle default, request)
     * @param int|null                  $maxUserTurns   base only (version, settings), no request value applied
     * @param RuleSet                   $rules          compiled `rules`, evaluated before retrieval and any model call
     * @param TemplateSet               $templates      the version's `templates` with its default language
     * @param array{prefix: string, suffix: string}|null $frame the rendered `frame` template split at its `{{answer}}` ({@see TemplateSet::frame()}); null = the answer is not framed
     * @param TurnTexts                 $texts          limit reasons and forced-close fallback of the turn, passed on as `TurnRequest::$texts`
     * @param bool                      $hybrid         keyword plus vector search over the collections, fused by weighted RRF ({@see RetrievalScope})
     * @param float                     $hybridWeight   keyword share of the fusion, 0..1
     * @param ContextLimits|null        $contextLimits  cap and expansion budget of the context ({@see ContextRetriever::retrieve()}); null = the bundle cap, no expansion
     * @param string                    $contextInstruction the instruction text in front of the context block, passed on as `TurnRequest::$contextInstruction`
     * @param bool                      $injectionGuard     run the prompt-injection guard before retrieval
     * @param bool                      $answerabilityCheck run the answerability check between retrieval and answer
     * @param int                       $answerabilityVotes votes of the answerability check (odd, 1..7)
     * @param bool                      $followUps          suggest follow-up questions after a complete text answer
     * @param string|null               $startMessage       the version's start message, rendered for the language; null = none
     * @param ApiConfiguration|null     $connection         the version's provider connection (`ollama` or `anthropic`), resolved and active; set by {@see VersionTurnMapper::map()}; null only where no provider is called
     * @param ProviderModel|null        $providerModel      the version's model catalogue entry, whose connection is $connection and whose name is $model; set by {@see VersionTurnMapper::map()}
     */
    public function __construct(
        public AgentVersion $version,
        public string $provider,
        public string $model,
        public string $instructions,
        public ?array $responseSchema,
        public array $collections,
        public int $retrievalDepth,
        public array $tools,
        public array $mcpConnections,
        public array $terminalTools,
        public ?int $maxToolCalls,
        public ?int $maxUserTurns,
        public ?ModelOptions $modelOptions,
        public string $language,
        public RuleSet $rules = new RuleSet(),
        public TemplateSet $templates = new TemplateSet(),
        public ?array $frame = null,
        public TurnTexts $texts = new TurnTexts(),
        public bool $hybrid = false,
        public float $hybridWeight = 0.5,
        public ?ContextLimits $contextLimits = null,
        public string $contextInstruction = ContextRetriever::LEGACY_INSTRUCTION,
        public bool $injectionGuard = false,
        public bool $answerabilityCheck = false,
        public int $answerabilityVotes = 1,
        public bool $followUps = false,
        public ?ApiConfiguration $connection = null,
        public ?ProviderModel $providerModel = null,
        public ?string $startMessage = null,
    ) {
    }

    public function getConnection(): ?ApiConfiguration
    {
        return $this->connection;
    }

    /**
     * A copy with $tools instead of the mapped tool list; everything else,
     * `mcpConnections` and `terminalTools` included, stays as mapped.
     *
     * @param list<ToolInterface> $tools
     */
    public function withTools(array $tools): self
    {
        $values = get_object_vars($this);
        $values['tools'] = $tools;

        return new self(...$values);
    }
}
// - revised 2026-10-14
// - revised 2026-10-16
// - revised 2026-11-27 (withTools)
