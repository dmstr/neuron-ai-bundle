<?php

// file generated with AI assistance: Claude Code - 2026-10-01 15:34:45 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\State;

use Dmstr\OpenApiJsonSchema\Interface\InputSchemaResolverInterface;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Validates the raw request body of a bundle operation against the
 * operation's input schema (`Entity/<Stem>/<verb>.input.json`, the same file
 * the OpenAPI document shows as `requestBody`).
 *
 * Same rules as {@see CollectionReindexProcessor::validate()} and
 * {@see McpToolScanProcessor::validate()}, which still carry their own copy:
 *  - an empty body counts as `{}`;
 *  - malformed JSON: 400;
 *  - schema violation: 422 with the formatted opis errors.
 */
final class InputBodyValidator
{
    public function __construct(
        private readonly InputSchemaResolverInterface $inputSchemaResolver,
    ) {
    }

    /**
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
