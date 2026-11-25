<?php

// file generated with AI assistance: Claude Code - 2026-10-05 19:33:07 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Conversation;

use ApiPlatform\Validator\Exception\ValidationException;
use Dmstr\NeuronAiBundle\Entity\Refs\ProviderModel;
use Dmstr\NeuronAiBundle\Provider\Model\ModelCapability;
use Dmstr\NeuronAiBundle\Provider\ModelAvailabilityCheck;
use Dmstr\NeuronAiBundle\Provider\ModelUnavailableException;
use Dmstr\NeuronAiBundle\Provider\ProviderModelResolver;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * The model a request chooses, at the entry points without an agent
 * version: `POST /chat`
 * ({@see \Dmstr\NeuronAiBundle\Chat\ChatService::chat()}),
 * `POST /conversations` without `agent`
 * ({@see \Dmstr\NeuronAiBundle\State\ConversationCreateProcessor}) and the
 * anonymous creation without a released agent
 * ({@see \Dmstr\NeuronAiBundle\Anonymous\AnonymousConversationCreator}).
 *
 * `model` is required, there is no default. It is a reference (IRI
 * `/api/refs/provider_models/<uuid>`, full or partial UUID) to an entry of
 * the model catalogue ({@see ProviderModel}), resolved by
 * {@see ProviderModelResolver} with the capability `completion`: available,
 * on an active `ollama` or `anthropic` connection, not known to lack the
 * capability. The entry names its connection, so there is no `connection`
 * of its own. The model is then checked live by
 * {@see ModelAvailabilityCheck} on the entry's connection and name (the
 * only live check of a request; turns never check again). Every problem is
 * a violation at `model`, thrown as an API Platform
 * {@see ValidationException} (422) before any provider call of the turn.
 * Violation texts never contain a credential.
 *
 * The violations carry no root on purpose: API Platform runs the
 * `propertyPath` through the serializer's name converter for the root's
 * class, and on {@see \Dmstr\NeuronAiBundle\Entity\Conversation} the
 * PHP property `model` is serialized as `modelName`, so a conversation as
 * root would report the API field `model` as `modelName`.
 */
final class ProviderSelection
{
    /** The field every violation is reported at. */
    public const string FIELD = 'model';

    public function __construct(
        private readonly ProviderModelResolver $models,
        private readonly ModelAvailabilityCheck $modelCheck,
    ) {
    }

    /**
     * The message of a missing `model`.
     */
    public static function modelRequired(): string
    {
        return ProviderModelResolver::requiredMessage(ModelCapability::COMPLETION);
    }

    /**
     * @param string|null $model the model reference: IRI or full or partial UUID of a catalogue entry
     *
     * @return ProviderModel the resolved entry; its connection is `getApiConfiguration()`, its name the model the provider is called with
     *
     * @throws ValidationException with the violation at `model`
     */
    public function select(?string $model): ProviderModel
    {
        $entry = $this->models->resolveField($model, ModelCapability::COMPLETION, self::FIELD);

        try {
            $this->modelCheck->assertAvailable($entry->getApiConfiguration(), $entry->getName());
        } catch (ModelUnavailableException $e) {
            throw new ValidationException(new ConstraintViolationList([new ConstraintViolation($e->getMessage(), $e->getMessage(), [], null, self::FIELD, $model)]));
        }

        return $entry;
    }
}
