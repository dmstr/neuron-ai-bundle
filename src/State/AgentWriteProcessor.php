<?php

// file generated with AI assistance: Claude Code - 2026-10-06 19:10:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Post;
use ApiPlatform\State\ProcessorInterface;
use Dmstr\NeuronAiBundle\Entity\Agent;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * `POST /agents` and `PATCH /agents/{id}`: refuses the key `draft`, which
 * was renamed to `candidate` (no transition), and hands
 * everything else to the persist processor. On `POST` a candidate that
 * lacks a top-level key of {@see Agent::NEW_AGENT_DEFAULTS} gets it, so an
 * agent created through the API starts like one created in the form; a key
 * the client sent (also `modelOptions` without `maxTokens`) is kept.
 *
 * Without this check the serializer would ignore the unknown key, so an
 * old client would create an agent with the schema defaults or save a
 * PATCH that silently drops its configuration.
 */
final class AgentWriteProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly ProcessorInterface $persistProcessor,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        $request = $context['request'] ?? null;
        if ($request instanceof Request && self::hasDraftKey($request->getContent())) {
            throw new UnprocessableEntityHttpException('"draft" was renamed to "candidate": send the agent configuration as "candidate".');
        }

        if ($operation instanceof Post && $data instanceof Agent) {
            $data->setCandidate($data->getCandidate() + Agent::NEW_AGENT_DEFAULTS);
        }

        return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
    }

    private static function hasDraftKey(string $content): bool
    {
        try {
            $decoded = json_decode($content, false, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return false;
        }

        return $decoded instanceof \stdClass && property_exists($decoded, 'draft');
    }
}

// - revised 2026-10-20
