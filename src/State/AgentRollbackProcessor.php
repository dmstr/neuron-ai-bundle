<?php

// file generated with AI assistance: Claude Code - 2026-10-02 22:05:06 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Dmstr\ApiPlatformUtils\Service\UuidResolver;
use Dmstr\NeuronAiBundle\Agent\AgentRollback;
use Dmstr\NeuronAiBundle\Entity\Agent;
use Dmstr\NeuronAiBundle\Entity\AgentVersion;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * `POST /agents/{id}/rollback` (`agent_rollback`).
 *
 * The operation loads the agent (`read: true`) and has already checked
 * `neuronai:agent:write`. The raw body is validated against
 * `Entity/Agent/rollback.input.json` (400 malformed JSON, 422 schema
 * violation). `version` is one of
 *  - an IRI ending in `/agent_versions/<id>`,
 *  - a full UUID,
 *  - a partial UUID (at least 8 hex digits, hyphens allowed),
 * resolved through {@see UuidResolver}. Anything else, an unknown or an
 * ambiguous reference answers 404 ("unknown version"); the pattern check
 * also keeps LIKE wildcards out of the partial lookup. A version of
 * another agent answers 422 at `version` ({@see AgentRollback}). Returns
 * the agent (200).
 *
 * @implements ProcessorInterface<Agent, Agent>
 */
final class AgentRollbackProcessor implements ProcessorInterface
{
    /** IRI of an agent version; the last segment is the id. */
    private const string IRI_PATTERN = '#/agent_versions/([^/?\#]+)$#';

    /** Full or partial UUID: at least 8 hex digits, then hex digits and hyphens. */
    private const string UUID_LIKE = '/^[0-9a-f]{8}[0-9a-f-]{0,28}$/i';

    public function __construct(
        private readonly AgentRollback $rollback,
        private readonly InputBodyValidator $inputBodyValidator,
        private readonly UuidResolver $uuidResolver,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Agent
    {
        if (!$data instanceof Agent) {
            throw new NotFoundHttpException('Not Found');
        }

        $request = $context['request'] ?? null;
        $body = $request instanceof Request ? $request->getContent() : '';

        $input = $this->inputBodyValidator->validate(Agent::OPERATION_ROLLBACK, $body);

        return $this->rollback->rollback($data, $this->version((string) $input['version']));
    }

    /**
     * @throws NotFoundHttpException for an unknown, ambiguous or malformed reference
     */
    private function version(string $reference): AgentVersion
    {
        $id = trim($reference);
        if (str_starts_with($id, '/')) {
            $id = 1 === preg_match(self::IRI_PATTERN, $id, $matches) ? $matches[1] : '';
        }

        $version = null;
        if (1 === preg_match(self::UUID_LIKE, $id)) {
            try {
                $version = $this->uuidResolver->findByPartialUuid(AgentVersion::class, $id);
            } catch (\RuntimeException) {
                // Ambiguous partial id: indistinguishable from "not found".
                $version = null;
            }
        }

        if (!$version instanceof AgentVersion) {
            throw new NotFoundHttpException(\sprintf('Unknown agent version "%s".', $reference));
        }

        return $version;
    }
}
