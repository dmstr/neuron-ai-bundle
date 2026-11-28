<?php

// file generated with AI assistance: Claude Code - 2026-11-27 22:48:58 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Chat;

use NeuronAI\Exceptions\ToolException;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\ToolOutput;
use NeuronAI\Tools\ToolPropertyInterface;

/**
 * Wrapper around one tool of a run under a {@see ToolArgumentGuard}
 * (decoration, not inheritance, like
 * {@see \Dmstr\NeuronAiBundle\Artifact\ArtifactTool}: any
 * {@see ToolInterface} can be guarded, an MCP tool or not, and every method
 * the agent loop and the provider mappers use is delegated: name,
 * description, properties, input schema, inputs, call id, run key,
 * approval).
 *
 * {@see execute()} asks the guard about the inputs the wrapped tool has
 * bound, i.e. the values already cast by its declared property types,
 * before the wrapped tool runs. A refused call never reaches the wrapped
 * tool, so an MCP tool neither connects nor sends; its result is
 * `ToolOutput::error()` with the guard's reason, never a thrown exception,
 * and the run goes on.
 */
final class GuardedTool implements ToolInterface
{
    private string|ToolOutput|null $result = null;

    public function __construct(
        private ToolInterface $inner,
        private readonly ToolArgumentGuard $guard,
    ) {
    }

    /**
     * The agent loop binds every call onto a clone of the registered tool;
     * the wrapped tool holds the inputs, so it is cloned with it.
     */
    public function __clone()
    {
        $this->inner = clone $this->inner;
    }

    public function execute(): void
    {
        $refusal = $this->guard->refusalFor($this->inner);
        if (null !== $refusal) {
            $this->result = ToolOutput::error($refusal);

            return;
        }

        $this->inner->execute();
        $this->result = $this->inner->getResult();
    }

    public function getName(): string
    {
        return $this->inner->getName();
    }

    public function setName(string $name): ToolInterface
    {
        $this->inner->setName($name);

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->inner->getDescription();
    }

    public function setDescription(?string $description): ToolInterface
    {
        $this->inner->setDescription($description);

        return $this;
    }

    public function addProperty(ToolPropertyInterface $property): ToolInterface
    {
        $this->inner->addProperty($property);

        return $this;
    }

    public function getProperties(): array
    {
        return $this->inner->getProperties();
    }

    public function getRequiredProperties(): array
    {
        return $this->inner->getRequiredProperties();
    }

    public function getInputSchema(): array
    {
        return $this->inner->getInputSchema();
    }

    public function getParameters(): array
    {
        return $this->inner->getParameters();
    }

    public function getInputs(): array
    {
        return $this->inner->getInputs();
    }

    public function getInput(string $key): mixed
    {
        return $this->inner->getInput($key);
    }

    public function setInputs(array $inputs): ToolInterface
    {
        $this->inner->setInputs($inputs);

        return $this;
    }

    public function getCallId(): ?string
    {
        return $this->inner->getCallId();
    }

    public function setCallId(string $callId): ToolInterface
    {
        $this->inner->setCallId($callId);

        return $this;
    }

    public function hasResult(): bool
    {
        return null !== $this->result;
    }

    public function getResult(): string|ToolOutput
    {
        if (null === $this->result) {
            throw new ToolException(sprintf('Tool %s has no result: it was never executed.', $this->getName()));
        }

        return $this->result;
    }

    public function setResult(mixed $result): ToolInterface
    {
        if (!$result instanceof ToolOutput) {
            $result = \is_array($result) ? json_encode($result, \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_THROW_ON_ERROR) : (string) $result;
        }

        $this->result = $result;

        return $this;
    }

    public function getMaxRuns(): ?int
    {
        return $this->inner->getMaxRuns();
    }

    public function setMaxRuns(int $tries): ToolInterface
    {
        $this->inner->setMaxRuns($tries);

        return $this;
    }

    public function visible(bool $visible): ToolInterface
    {
        $this->inner->visible($visible);

        return $this;
    }

    public function isVisible(): bool
    {
        return $this->inner->isVisible();
    }

    public function getRunKey(): string
    {
        return $this->inner->getRunKey();
    }

    public function requiresApproval(): bool|string
    {
        return $this->inner->requiresApproval();
    }

    /**
     * The wrapped tool's serialization with the wrapper's result.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        $data = $this->inner->jsonSerialize();
        $data['result'] = $this->result instanceof ToolOutput ? $this->result->jsonSerialize() : $this->result;

        return $data;
    }
}
