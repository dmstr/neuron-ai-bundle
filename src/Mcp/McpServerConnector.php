<?php

// file generated with AI assistance: Claude Code - 2026-09-30 21:22:22 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Mcp;

use NeuronAI\MCP\McpConnector;
use NeuronAI\MCP\McpTransportInterface;
use NeuronAI\Tools\ToolInterface;

/**
 * Neuron McpConnector bound to a transport instance from
 * {@see McpTransportFactoryInterface}.
 *
 * Differences to the vendor class:
 *  - it requires `config['transport']` (an McpTransportInterface) and wraps
 *    it in {@see McpProtocolGuard}, so malformed server answers raise an
 *    {@see McpProtocolException} instead of PHP warnings;
 *  - it exposes the raw tool definitions (listTools()) and the handshake
 *    result (getInitializeResult()) for health, scan and sync;
 *  - it builds Neuron tools from catalogue data (createCatalogueTool(),
 *    createToolFromDefinition()) without contacting the server; the vendor's
 *    createTool() is protected;
 *  - it derives the type of an argument property that has only `oneOf` or
 *    `anyOf` branches (createTool()); neuron-ai 4.x refuses such a schema
 *    with a ToolException (3.x announced the property as string).
 *
 * The built tools are neuron-ai 4.x {@see \NeuronAI\MCP\McpTool}s: their
 * result is the vendor's {@see \NeuronAI\Tools\ToolOutput} (text, image and
 * audio blocks, `isError()` from the server's `isError`), and a JSON-RPC
 * error of `tools/call` is thrown as an McpException.
 *
 * Like the vendor class, nothing connects before the first listTools(),
 * getInitializeResult() or tool execution.
 *
 * Usage:
 *
 *     $connector = McpServerConnector::forTransport($factory->create($config));
 *     $tool = $connector->createCatalogueTool('search', 'Full-text search', $inputSchema);
 */
final class McpServerConnector extends McpConnector
{
    private McpProtocolGuard $guard;

    /**
     * @param array<string, mixed> $config must contain 'transport'
     */
    public function __construct(array $config)
    {
        $this->guard = self::guard($config['transport'] ?? null);
        $config['transport'] = $this->guard;

        parent::__construct($config);
    }

    public static function forTransport(McpTransportInterface $transport): self
    {
        return new self(['transport' => $transport]);
    }

    /**
     * Raw tool definitions from `tools/list` (all pages), each with at least
     * a non-empty `name`; `description`, `inputSchema` and `annotations`
     * as the server sent them. Connects and performs the handshake on first
     * use. Ignores only()/exclude().
     *
     * @return list<array<string, mixed>>
     *
     * @throws \NeuronAI\MCP\McpException on transport, authentication or protocol failure
     */
    public function listTools(): array
    {
        return array_values($this->client()->listTools());
    }

    /**
     * The `result` of the `initialize` handshake (`protocolVersion`,
     * `serverInfo.name`, `serverInfo.version`, `capabilities`, ...).
     * Connects and performs the handshake if that has not happened yet.
     *
     * @return array<string, mixed>
     *
     * @throws \NeuronAI\MCP\McpException
     */
    public function getInitializeResult(): array
    {
        $this->client();

        return $this->guard->getInitializeResult() ?? [];
    }

    /**
     * Builds a Neuron tool from catalogue data without contacting the server.
     * The tool connects lazily on its first execution (through
     * McpConnector::invokeTool()), which returns a ToolOutput.
     *
     * @param array<string, mixed> $inputSchema  JSON schema of the arguments
     *                                           (type object, properties, required)
     * @param array<string, mixed> $annotations
     *
     * @throws \NeuronAI\Exceptions\ToolException           when the schema cannot be expressed as tool
     *                                                      properties (type lists, `$ref`, `allOf`,
     *                                                      nested `oneOf`/`anyOf`, unsupported types)
     * @throws \NeuronAI\Exceptions\ArrayPropertyException
     * @throws \ReflectionException
     */
    public function createCatalogueTool(string $name, ?string $description = null, array $inputSchema = [], array $annotations = []): ToolInterface
    {
        return $this->createTool([
            'name' => $name,
            'description' => $description,
            'inputSchema' => $inputSchema,
            'annotations' => $annotations,
        ]);
    }

    /**
     * Builds a Neuron tool from one raw `tools/list` definition, see
     * createCatalogueTool().
     *
     * @param array<string, mixed> $definition
     */
    public function createToolFromDefinition(array $definition): ToolInterface
    {
        return $this->createTool($definition);
    }

    /**
     * Fills in the `type` of argument properties that declare their types only
     * through `oneOf`/`anyOf` branches before the vendor builds the tool, and
     * drops the branches.
     *
     * neuron-ai 3.x treated a property without `type` as a string, so the
     * model was told to send text and the server rejected it (e.g. `variables`
     * of the Flowable tools: `oneOf` object | array → "must match the type:
     * object / array"); 4.x refuses `oneOf`/`anyOf` altogether. The branch
     * types are collected in order; `object` comes first when present,
     * because a map is the most general argument. The property keeps its own
     * keywords (description, ...), not those of the branches, as in 3.x.
     *
     * @param array<string, mixed> $item
     */
    protected function createTool(array $item): ToolInterface
    {
        $properties = $item['inputSchema']['properties'] ?? null;
        if (\is_array($properties)) {
            foreach ($properties as $name => $prop) {
                if (\is_array($prop) && !isset($prop['type']) && null !== $type = self::branchType($prop)) {
                    unset($prop['oneOf'], $prop['anyOf']);
                    $item['inputSchema']['properties'][$name] = ['type' => $type] + $prop;
                }
            }
        }

        return parent::createTool($item);
    }

    /**
     * @param array<string, mixed> $prop
     */
    private static function branchType(array $prop): ?string
    {
        $types = [];
        foreach (['oneOf', 'anyOf'] as $keyword) {
            foreach (\is_array($prop[$keyword] ?? null) ? $prop[$keyword] : [] as $branch) {
                foreach ((array) (\is_array($branch) ? ($branch['type'] ?? []) : []) as $type) {
                    if (\is_string($type) && 'null' !== $type) {
                        $types[] = $type;
                    }
                }
            }
        }

        return \in_array('object', $types, true) ? 'object' : ($types[0] ?? null);
    }

    public function __unserialize(array $data): void
    {
        parent::__unserialize($data);
        $this->guard = self::guard($this->config['transport'] ?? null);
        $this->config['transport'] = $this->guard;
    }

    private static function guard(mixed $transport): McpProtocolGuard
    {
        if ($transport instanceof McpProtocolGuard) {
            return $transport;
        }
        if (!$transport instanceof McpTransportInterface) {
            throw new \InvalidArgumentException(\sprintf('%s requires config["transport"] to be an instance of %s.', self::class, McpTransportInterface::class));
        }

        return new McpProtocolGuard($transport);
    }
}
// - revised 2026-10-09
// - revised 2026-10-14
