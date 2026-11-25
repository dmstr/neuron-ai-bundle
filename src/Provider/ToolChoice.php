<?php

// file generated with AI assistance: Claude Code - 2026-10-02 05:54:24 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Provider;

/**
 * Which tool use a provider request asks the model for.
 *
 * The four choices mirror the common denominator of the major chat APIs
 * (Anthropic `tool_choice`, OpenAI `tool_choice`, Gemini
 * `function_calling_config`):
 *
 * - `auto`: the model decides whether to call a tool (the API default);
 * - `any`: the model must call one of the offered tools;
 * - `none`: the model must not call a tool and answers with text, while
 *   the tool definitions stay in the request (so earlier tool calls and
 *   results in the history remain valid);
 * - `tool`: the model must call the named tool.
 *
 * A value object: compare with equals(), not with `===`.
 */
final readonly class ToolChoice implements \Stringable
{
    public const string AUTO = 'auto';
    public const string ANY = 'any';
    public const string NONE = 'none';
    public const string TOOL = 'tool';

    /**
     * @param self::AUTO|self::ANY|self::NONE|self::TOOL $type
     * @param string|null                                $name the tool name, only for `tool`
     */
    private function __construct(
        public string $type,
        public ?string $name = null,
    ) {
    }

    public static function auto(): self
    {
        return new self(self::AUTO);
    }

    public static function any(): self
    {
        return new self(self::ANY);
    }

    public static function none(): self
    {
        return new self(self::NONE);
    }

    /**
     * @throws \InvalidArgumentException for an empty tool name
     */
    public static function tool(string $name): self
    {
        if ('' === trim($name)) {
            throw new \InvalidArgumentException('The tool name of a forced tool choice must not be empty.');
        }

        return new self(self::TOOL, $name);
    }

    public function isAuto(): bool
    {
        return self::AUTO === $this->type;
    }

    public function isAny(): bool
    {
        return self::ANY === $this->type;
    }

    public function isNone(): bool
    {
        return self::NONE === $this->type;
    }

    public function isTool(): bool
    {
        return self::TOOL === $this->type;
    }

    public function equals(self $other): bool
    {
        return $this->type === $other->type && $this->name === $other->name;
    }

    /**
     * `auto`, `any`, `none` or `tool:<name>`.
     */
    public function __toString(): string
    {
        return self::TOOL === $this->type ? sprintf('tool:%s', $this->name) : $this->type;
    }
}
