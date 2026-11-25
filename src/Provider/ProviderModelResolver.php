<?php

// file generated with AI assistance: Claude Code - 2026-10-08 15:38:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Provider;

use ApiPlatform\Metadata\Exception\ExceptionInterface as ApiPlatformException;
use ApiPlatform\Metadata\IriConverterInterface;
use ApiPlatform\Validator\Exception\ValidationException;
use Dmstr\ApiPlatformUtils\Service\UuidResolver;
use Dmstr\NeuronAiBundle\Entity\Refs\ProviderModel;
use Dmstr\NeuronAiBundle\Provider\Model\ModelCapability;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * Resolves a model reference to an entry of the model catalogue
 * ({@see ProviderModel}); the provider connection is the entry's
 * `apiConfiguration`. Modelled on
 * {@see Connection\ProviderConnectionResolver}.
 *
 * A reference is one of:
 *  - an IRI (`/api/refs/provider_models/<uuid>`), via IriConverterInterface;
 *  - a full or partial UUID (at least 8 hex digits), via UuidResolver.
 *
 * Model names are no references: a name is unique per connection only.
 *
 * The entry must be usable for $capability (one of
 * {@see ModelCapability::ALL}, e.g. `completion` for chat, `embedding` for
 * collections):
 *  - it is offered by its connection (`available` true);
 *  - its connection is active;
 *  - its connection type serves the capability (`embedding` only from
 *    {@see ProviderType::EMBEDDING_TYPES}, every other capability from
 *    {@see ProviderType::CHAT_TYPES});
 *  - the catalogue does not know it to lack the capability. Unknown
 *    capabilities (never described, or a stale entry) pass, as in
 *    {@see ModelCapabilityCheck}: the live {@see ModelAvailabilityCheck},
 *    which callers keep running with `getApiConfiguration()` and
 *    `getName()`, still guarantees that the model is offered.
 *
 * Every failure is a {@see ProviderModelReferenceException} (HTTP 422);
 * {@see resolveField()} turns it into a violation at the caller's field.
 * Callers store the entry's id (`getId()->toRfc4122()`) or reference the
 * entity.
 */
final class ProviderModelResolver
{
    private const string UUID_LIKE = '/^[0-9a-f]{8}[0-9a-f-]{0,28}$/i';

    public function __construct(
        private readonly IriConverterInterface $iriConverter,
        private readonly UuidResolver $uuidResolver,
    ) {
    }

    /**
     * The message of a missing model reference for $capability.
     */
    public static function requiredMessage(string $capability): string
    {
        return \sprintf('A model is required: IRI or UUID of an available entry of the model catalogue (%s) with the capability "%s".', ProviderModel::COLLECTION_PATH, $capability);
    }

    /**
     * @param string $capability the capability the model must serve, one of {@see ModelCapability::ALL}
     *
     * @throws ProviderModelReferenceException
     */
    public function resolve(?string $reference, string $capability): ProviderModel
    {
        ModelCapability::assertKnown($capability);

        $reference = null === $reference ? '' : trim($reference);
        if ('' === $reference) {
            throw new ProviderModelReferenceException(self::requiredMessage($capability), ProviderModelReferenceException::EMPTY, $reference);
        }

        return $this->assertUsable($this->find($reference), $reference, $capability);
    }

    /**
     * {@see resolve()} for one field of a request: a failure is thrown as
     * an API Platform {@see ValidationException} (422) with the violation
     * at $field.
     *
     * @param mixed $root the validated object (the violation root), e.g. the request body
     *
     * @throws ValidationException
     */
    public function resolveField(?string $reference, string $capability, string $field, mixed $root = null): ProviderModel
    {
        try {
            return $this->resolve($reference, $capability);
        } catch (ProviderModelReferenceException $e) {
            throw new ValidationException(new ConstraintViolationList([$e->violationAt($field, $root)]));
        }
    }

    /**
     * The checks of {@see resolve()} for an entry the caller already holds
     * (set by code, e.g. a fixture or a stored relation), without a lookup.
     *
     * @param string $capability one of {@see ModelCapability::ALL}
     *
     * @throws ProviderModelReferenceException
     */
    public function check(ProviderModel $model, string $capability): ProviderModel
    {
        ModelCapability::assertKnown($capability);

        return $this->assertUsable($model, $model->iri(), $capability);
    }

    private function find(string $reference): ProviderModel
    {
        if (str_starts_with($reference, '/')) {
            try {
                $resource = $this->iriConverter->getResourceFromIri($reference);
            } catch (ApiPlatformException) {
                throw self::unknown($reference);
            }

            return $resource instanceof ProviderModel ? $resource : throw self::unknown($reference);
        }

        if (1 !== preg_match(self::UUID_LIKE, $reference)) {
            throw self::unknown($reference);
        }

        try {
            $row = $this->uuidResolver->findByPartialUuid(ProviderModel::class, $reference);
        } catch (\RuntimeException) {
            throw new ProviderModelReferenceException(\sprintf('Model reference "%s" is ambiguous; give more digits of the UUID.', $reference), ProviderModelReferenceException::AMBIGUOUS, $reference);
        }

        return $row instanceof ProviderModel ? $row : throw self::unknown($reference);
    }

    private function assertUsable(ProviderModel $row, string $reference, string $capability): ProviderModel
    {
        $connection = $row->getApiConfiguration();
        $label = \sprintf('Model "%s" of connection "%s"', $row->getName(), ProviderType::nameOf($connection));

        if (!$row->isAvailable()) {
            throw new ProviderModelReferenceException($label.' is no longer offered by the connection; choose another model or scan the catalogue again.', ProviderModelReferenceException::UNAVAILABLE, $reference);
        }
        if (!$connection->isActive()) {
            throw new ProviderModelReferenceException($label.': the connection is inactive.', ProviderModelReferenceException::INACTIVE, $reference);
        }

        $types = ModelCapability::EMBEDDING === $capability ? ProviderType::EMBEDDING_TYPES : ProviderType::CHAT_TYPES;
        $type = $connection->getConfigJson()['type'] ?? null;
        if (!\in_array($type, $types, true)) {
            throw new ProviderModelReferenceException(\sprintf('%s: a connection of type "%s" cannot serve "%s" (expected: %s).', $label, \is_string($type) ? $type : '', $capability, implode(', ', $types)), ProviderModelReferenceException::MISSING_CAPABILITY, $reference);
        }

        $verdict = ModelCapabilityCheck::verdictOf($row, [$capability]);
        if ($verdict->isMissing()) {
            throw new ProviderModelReferenceException(\sprintf('%s lacks the capability "%s" (known: %s).', $label, $capability, [] === $verdict->known ? 'none' : implode(', ', $verdict->known)), ProviderModelReferenceException::MISSING_CAPABILITY, $reference);
        }

        return $row;
    }

    private static function unknown(string $reference): ProviderModelReferenceException
    {
        return new ProviderModelReferenceException(\sprintf('Unknown model "%s": expected the IRI or UUID of an entry of the model catalogue (%s).', $reference, ProviderModel::COLLECTION_PATH), ProviderModelReferenceException::UNKNOWN, $reference);
    }
}
