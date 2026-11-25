<?php

// file generated with AI assistance: Claude Code - 2026-10-01 13:37:11 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use ApiPlatform\Validator\Exception\ValidationException;
use Dmstr\NeuronAiBundle\Entity\Collection;
use Dmstr\NeuronAiBundle\Entity\Refs\ProviderModel;
use Dmstr\NeuronAiBundle\Provider\Model\ModelCapability;
use Dmstr\NeuronAiBundle\Provider\ProviderModelReferenceException;
use Dmstr\NeuronAiBundle\Provider\ProviderModelResolver;
use Dmstr\NeuronAiBundle\Reindex\CollectionReindexer;
use Dmstr\NeuronAiBundle\Reindex\ReindexRequestException;
use Dmstr\OpenApiJsonSchema\Interface\InputSchemaResolverInterface;
use Dmstr\SymfonyJobQueue\Entity\Job;
use Dmstr\SymfonyJobQueue\Service\JobQueueService;
use Doctrine\ORM\EntityManagerInterface;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * `POST /admin/collections/{id}/reindex`: validates the body, resolves the
 * target `model` (a catalogue entry, which names the connection), marks
 * it as the collection's pending model and queues a job of type
 * `collection_reindex`
 * ({@see \Dmstr\NeuronAiBundle\JobProcessor\CollectionReindexJobProcessor}).
 *
 * The body is validated against `Entity/Collection/reindex.input.json` from
 * the resolver chain, like {@see McpToolScanProcessor} does and for the same
 * reason (the job queue's own validation cannot see the bundle's schemas):
 *  - malformed JSON: 400;
 *  - `connection` (removed, the entry names the connection): 422 with the
 *    violation at `connection` ({@see CONNECTION_REMOVED});
 *  - schema violation (missing `model`, unknown property): 422;
 *  - a `model` that does not resolve to an entry usable for embeddings
 *    ({@see ProviderModelResolver} with the capability `embedding`), whose
 *    name is not registered, that is the current model (on any
 *    connection), or while another model is pending:
 *    422 with the violation at `model`.
 *
 * The job input is `{"collection": "<uuid>", "model": "<label>",
 * "providerModel": "<entry uuid>"}`: `model` is the registered name the
 * job processor compares with the pending label; the job id is stored as
 * the collection's `reindexJobId`.
 *
 * @implements ProcessorInterface<Collection, Job>
 */
final class CollectionReindexProcessor implements ProcessorInterface
{
    public const string CONNECTION_REMOVED = '"connection" was removed: "model" (the IRI or UUID of an entry of /api/refs/provider_models) names the connection of the reindex target.';

    public function __construct(
        private readonly InputSchemaResolverInterface $inputSchemaResolver,
        private readonly CollectionReindexer $reindexer,
        private readonly JobQueueService $jobQueueService,
        private readonly EntityManagerInterface $entityManager,
        private readonly ProviderModelResolver $models,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Job
    {
        if (!$data instanceof Collection) {
            throw new NotFoundHttpException('Not Found');
        }

        $request = $context['request'] ?? null;
        $body = $request instanceof Request ? $request->getContent() : '';

        $raw = json_decode(trim($body), true);
        if (\is_array($raw) && \array_key_exists('connection', $raw)) {
            throw self::violation($data, 'connection', self::CONNECTION_REMOVED, $raw['connection']);
        }

        $input = $this->validate($operation->getName() ?? Collection::OPERATION_REINDEX, $body);
        $reference = (string) $input['model'];
        $target = $this->target($data, $reference);

        try {
            $this->reindexer->start($data, $target);
        } catch (ReindexRequestException $e) {
            throw self::violation($data, $e->field, $e->getMessage(), $reference);
        } catch (\InvalidArgumentException $e) {
            throw new UnprocessableEntityHttpException($e->getMessage(), $e);
        }

        // No operation name: the body was validated above, and the job input
        // (with the collection id) is not a request body of that schema.
        $job = $this->jobQueueService->createAndDispatch(
            Collection::JOB_TYPE_REINDEX,
            [
                'collection' => (string) $data->getId(),
                'model' => $data->getPendingEmbeddingModel(),
                'providerModel' => $target->getId()->toRfc4122(),
            ],
        );

        $data->setReindexJobId($job->getId());
        $this->entityManager->flush();

        return $job;
    }

    /**
     * The target catalogue entry of the request; a reference that does not
     * resolve to an entry usable for embeddings is a violation at `model`.
     *
     * @throws ValidationException
     */
    private function target(Collection $collection, string $reference): ProviderModel
    {
        try {
            return $this->models->resolve($reference, ModelCapability::EMBEDDING);
        } catch (ProviderModelReferenceException $e) {
            throw self::violation($collection, ReindexRequestException::FIELD_MODEL, $e->getMessage(), $reference);
        }
    }

    /**
     * A 422 with one violation at the request field $field.
     */
    private static function violation(Collection $collection, string $field, string $message, mixed $invalidValue): ValidationException
    {
        return new ValidationException(new ConstraintViolationList([
            new ConstraintViolation($message, $message, [], $collection, $field, $invalidValue),
        ]));
    }

    /**
     * Decodes the raw body and validates it against the operation's input
     * schema. An empty body counts as `{}`. Same rules as
     * {@see McpToolScanProcessor::validate()}.
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
