<?php

// file generated with AI assistance: Claude Code - 2026-09-30 21:41:04 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Dmstr\NeuronAiBundle\Entity\Refs\McpTool;
use Dmstr\NeuronAiBundle\Mcp\McpConnectionResolver;
use Dmstr\OpenApiJsonSchema\Interface\InputSchemaResolverInterface;
use Dmstr\SymfonyJobQueue\Entity\Job;
use Dmstr\SymfonyJobQueue\Service\JobQueueService;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * `POST /refs/mcp_tools/scan`: validates the body and queues a job of type
 * `mcp_tool_scan`.
 *
 * The platform's JobQueueService validates only against schemas below the
 * app's own entity directory, so it cannot see the bundle's
 * `Entity/Refs/McpTool/scan.input.json` and would accept any body. This
 * processor therefore validates the raw body itself against the operation's
 * input schema from the resolver chain (the same schema the OpenAPI document
 * shows) and resolves the optional connection before a job exists:
 *  - malformed JSON: 400;
 *  - schema violation (e.g. an unknown property): 422;
 *  - unknown, inactive, ambiguous or non-`mcp` connection: 422.
 *
 * The job input is `{}` (all active connections) or
 * `{"apiConfiguration": "<connection uuid>"}`.
 *
 * @implements ProcessorInterface<null, Job>
 */
final class McpToolScanProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly InputSchemaResolverInterface $inputSchemaResolver,
        private readonly McpConnectionResolver $connectionResolver,
        private readonly JobQueueService $jobQueueService,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Job
    {
        $request = $context['request'] ?? null;
        $body = $request instanceof Request ? $request->getContent() : '';

        $input = $this->validate($operation->getName() ?? McpTool::OPERATION_SCAN, $body);

        $jobInput = [];
        // An empty reference is what a form sends for the untouched optional
        // field; it means the same as an omitted one: all connections.
        $reference = trim((string) ($input['apiConfiguration'] ?? ''));
        if ('' !== $reference) {
            $connection = $this->connectionResolver->resolve($reference);
            $jobInput['apiConfiguration'] = (string) $connection->getId();
        }

        return $this->jobQueueService->createAndDispatch(McpTool::JOB_TYPE, $jobInput, McpTool::OPERATION_SCAN);
    }

    /**
     * Decodes the raw body and validates it against the operation's input
     * schema. An empty body counts as `{}`.
     *
     * @return array<string, mixed> the decoded body
     *
     * @throws BadRequestHttpException          on malformed JSON
     * @throws UnprocessableEntityHttpException on a schema violation
     * @throws \LogicException                  when the operation has no input schema
     */
    public function validate(string $operationName, string $body): array
    {
        $schema = $this->inputSchemaResolver->resolve($operationName);
        if (null === $schema) {
            throw new \LogicException(\sprintf('No input schema is registered for operation "%s".', $operationName));
        }

        $body = trim($body);
        if ('' === $body) {
            $body = '{}';
        }

        try {
            $decoded = json_decode($body, false, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new BadRequestHttpException(\sprintf('The request body is not valid JSON: %s', $e->getMessage()), $e);
        }

        $result = (new Validator())->validate($decoded, json_decode((string) json_encode($schema, \JSON_THROW_ON_ERROR), false, 512, \JSON_THROW_ON_ERROR));
        if (!$result->isValid()) {
            $errors = (new ErrorFormatter())->format($result->error());

            throw new UnprocessableEntityHttpException(\sprintf('Input validation failed for operation "%s": %s', $operationName, json_encode($errors, \JSON_UNESCAPED_SLASHES)));
        }

        /** @var array<string, mixed> $input the schema requires an object */
        $input = json_decode($body, true, 512, \JSON_THROW_ON_ERROR);

        return $input;
    }
}
