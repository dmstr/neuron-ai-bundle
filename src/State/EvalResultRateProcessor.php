<?php

// file generated with AI assistance: Claude Code - 2026-10-03 20:46:34 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use ApiPlatform\Validator\Exception\ValidationException;
use Dmstr\NeuronAiBundle\Agent\AgentConfiguration;
use Dmstr\NeuronAiBundle\Agent\AgentConfigurationValidator;
use Dmstr\NeuronAiBundle\Entity\EvalResult;
use Dmstr\NeuronAiBundle\Evaluation\EvaluationSettingsInterface;
use Doctrine\ORM\EntityManagerInterface;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\Exceptions\SchemaException;
use Opis\JsonSchema\JsonPointer;
use Opis\JsonSchema\Validator;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * `POST /eval_results/{id}/rate` (`eval_result_rate`): stores a reviewer's
 * human rating of one evaluation result.
 *
 * The operation has already checked `neuronai:eval:rate` and loaded the
 * result (404 otherwise). The body is the rating document itself, a JSON
 * object whose shape the operator defines with the setting
 * {@see self::SETTING}:
 *  - malformed JSON, or a body that is not a JSON object: 400;
 *  - the setting is empty, or is not a valid JSON Schema (checked like an
 *    agent's `responseSchema`, {@see AgentConfigurationValidator::schemaError()};
 *    default draft 2020-12): 422 whose `detail` names the setting;
 *  - the document does not match the schema: 422 as an API Platform
 *    {@see ValidationException}, one violation per error with the JSON
 *    pointer of the offending value as `propertyPath` (an unexpected
 *    property under `additionalProperties: false` is reported at the
 *    pointer of that property, e.g. `/extra`);
 *  - otherwise `humanRating`, `humanRatedBy` (the caller's user
 *    identifier) and `humanRatedAt` are stored, replacing an earlier
 *    rating, and the result is returned (200).
 *
 * The schema is read on every request, so a changed setting applies to the
 * next rating; stored ratings are not revalidated.
 *
 * @implements ProcessorInterface<EvalResult, EvalResult>
 */
final class EvalResultRateProcessor implements ProcessorInterface
{
    public const string SETTING = 'evaluation.human_rating_schema';

    /** Errors reported per validation. */
    private const int MAX_ERRORS = 20;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly ClockInterface $clock,
        private readonly ?EvaluationSettingsInterface $settings = null,
    ) {
    }

    /**
     * @throws BadRequestHttpException          on malformed JSON or a body that is no JSON object
     * @throws UnprocessableEntityHttpException when the rating schema is not configured or invalid
     * @throws ValidationException              when the rating does not match the schema
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): EvalResult
    {
        if (!$data instanceof EvalResult) {
            throw new NotFoundHttpException('Not Found');
        }

        $request = $context['request'] ?? null;
        $rating = self::decode($request instanceof Request ? $request->getContent() : '');
        $schema = self::schemaObject($this->settings?->humanRatingSchema());

        try {
            $errors = self::errors($rating, $schema);
        } catch (SchemaException $e) {
            // A schema that loads but fails while validating (e.g. a `$ref`
            // that does not resolve).
            throw new UnprocessableEntityHttpException(\sprintf('The human rating schema of setting "%s" is not a valid JSON Schema: %s', self::SETTING, $e->getMessage()), $e);
        }
        if ([] !== $errors) {
            $violations = new ConstraintViolationList();
            foreach ($errors as [$pointer, $message]) {
                $violations->add(new ConstraintViolation($message, $message, [], $rating, $pointer, null));
            }

            throw new ValidationException($violations);
        }

        /** @var array<string, mixed> $stored the body is a JSON object */
        $stored = json_decode((string) json_encode($rating, \JSON_THROW_ON_ERROR), true, 512, \JSON_THROW_ON_ERROR);

        $data
            ->setHumanRating($stored)
            ->setHumanRatedBy($this->userIdentifier())
            ->setHumanRatedAt($this->clock->now());
        $this->entityManager->flush();

        return $data;
    }

    /**
     * Decodes the body with JSON objects kept as objects (an empty object
     * is not mistaken for a list).
     *
     * @throws BadRequestHttpException on malformed JSON or a body that is no JSON object
     */
    public static function decode(string $body): \stdClass
    {
        try {
            $rating = json_decode(trim($body), false, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new BadRequestHttpException(\sprintf('The request body is not valid JSON: %s', $e->getMessage()), $e);
        }
        if (!$rating instanceof \stdClass) {
            throw new BadRequestHttpException('The request body must be a JSON object (the rating document).');
        }

        return $rating;
    }

    /**
     * Validates a rating against a schema that has already been checked
     * ({@see self::schemaObject()}).
     *
     * @return list<array{0: string, 1: string}> JSON pointer and message per error, empty when valid
     */
    public static function errors(\stdClass $rating, object $schema): array
    {
        $validator = new Validator();
        $validator->setMaxErrors(self::MAX_ERRORS);
        $result = $validator->validate($rating, $schema);
        if ($result->isValid()) {
            return [];
        }

        $formatter = new ErrorFormatter();
        $errors = [];
        /** @var list<ValidationError> $flat */
        $flat = $formatter->formatFlat($result->error(), static fn (ValidationError $error): ValidationError => $error);
        foreach ($flat as $error) {
            if ([] !== $error->subErrors()) {
                continue;
            }

            $path = $error->data()->fullPath();
            $properties = $error->args()['properties'] ?? null;
            if ('additionalProperties' === $error->keyword() && \is_array($properties) && [] !== $properties) {
                // `additionalProperties: false`: one error per property, at
                // the property's own pointer.
                foreach ($properties as $property) {
                    $errors[] = [JsonPointer::pathToString([...$path, $property]), \sprintf('The property "%s" is not allowed.', $property)];
                }

                continue;
            }

            $errors[] = [JsonPointer::pathToString($path), $formatter->formatErrorMessage($error)];
        }

        return $errors;
    }

    /**
     * The configured schema as the JSON value opis validates.
     *
     * @param array<string, mixed>|null $schema the setting's value
     *
     * @throws UnprocessableEntityHttpException when the schema is empty or not a valid JSON Schema
     */
    public static function schemaObject(?array $schema): object
    {
        if (null === $schema || [] === $schema) {
            throw new UnprocessableEntityHttpException(\sprintf('No human rating schema is configured (setting "%s").', self::SETTING));
        }

        $error = AgentConfigurationValidator::schemaError($schema);
        if (null !== $error) {
            throw new UnprocessableEntityHttpException(\sprintf('The human rating schema of setting "%s" is not a valid JSON Schema: %s', self::SETTING, $error));
        }

        $json = AgentConfiguration::toJsonValue($schema);
        \assert(\is_object($json));

        return $json;
    }

    private function userIdentifier(): string
    {
        $token = $this->tokenStorage->getToken();
        $identifier = null !== $token?->getUser() ? $token->getUserIdentifier() : '';
        if ('' === $identifier) {
            throw new AccessDeniedException('Rating an evaluation result requires an authenticated user.');
        }

        return $identifier;
    }
}
