<?php

// file generated with AI assistance: Claude Code - 2026-10-05 19:33:07 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Support;

use Dmstr\ApiConfiguration\Entity\ApiConfiguration;
use Dmstr\NeuronAiBundle\Entity\Refs\ProviderModel;
use Dmstr\NeuronAiBundle\Testing\FakeProviderFactory;

/**
 * Provider connections of the database-bound conversation, chat, replay,
 * stream and evaluation tests: the two fixture connections of
 * {@see AgentFixtures::providerConnections()}
 * ({@see AgentFixtures::OLLAMA_CONNECTION},
 * {@see AgentFixtures::ANTHROPIC_CONNECTION}), so conversations without
 * an agent and agent versions published through the API run on the same
 * connections. Both are made active again on every call (a test that
 * deactivates a connection uses a connection of its own); they keep their
 * prefix, so {@see AgentFixtures::cleanUp()} removes them, together
 * with every conversation that still runs on one of them.
 *
 * {@see model()} gives the model catalogue entry of a fixture connection,
 * {@see modelId()} its full UUID as agent versions store it and
 * {@see modelIri()} its IRI as `model` of a request body
 * (`POST /api/chat`, `POST /api/conversations` without an agent, the
 * anonymous release).
 *
 * {@see requested()} builds the record {@see FakeProviderFactory::getRequestedProviders()}
 * keeps for a provider built from one of them.
 */
final class ConversationConnections
{
    use ProviderModelFixtureTrait;

    public const string ANTHROPIC = AgentFixtures::ANTHROPIC_CONNECTION;
    public const string OLLAMA = AgentFixtures::OLLAMA_CONNECTION;

    private function __construct()
    {
    }

    /**
     * The active `anthropic` fixture connection (created when missing).
     */
    public static function anthropic(): ApiConfiguration
    {
        return self::active('anthropic');
    }

    /**
     * The active `ollama` fixture connection (created when missing).
     */
    public static function ollama(): ApiConfiguration
    {
        return self::active('ollama');
    }

    /**
     * The full id of a fixture connection, as an agent version stores it.
     *
     * @param 'anthropic'|'ollama' $type
     */
    public static function id(string $type = 'anthropic'): string
    {
        return self::active($type)->getId()->toRfc4122();
    }

    /**
     * The full id of the model catalogue entry of a fixture connection
     * (made active) for $model, as an agent version stores it: created
     * when missing and made usable ({@see ProviderModelFixtureTrait::catalogueModel()}).
     *
     * @param 'anthropic'|'ollama' $type
     * @param string|null          $model null = the fake's model of the type
     */
    public static function modelId(string $type = 'anthropic', ?string $model = null): string
    {
        return self::model($type, $model)->getId()->toRfc4122();
    }

    /**
     * The model catalogue entry of a fixture connection (made active) for
     * $model, created when missing and made usable
     * ({@see ProviderModelFixtureTrait::catalogueModel()}).
     *
     * @param 'anthropic'|'ollama' $type
     * @param string|null          $model null = the fake's model of the type
     */
    public static function model(string $type = 'anthropic', ?string $model = null): ProviderModel
    {
        return self::catalogueModel(AgentFixtures::entityManager(), self::active($type), $model ?? self::fakeModel($type));
    }

    /**
     * The IRI of {@see model()}, as `model` of a request body.
     *
     * @param 'anthropic'|'ollama' $type
     * @param string|null          $model null = the fake's model of the type
     */
    public static function modelIri(string $type = 'anthropic', ?string $model = null): string
    {
        return self::model($type, $model)->iri();
    }

    /**
     * $configuration of an agent version written directly through the
     * entity manager, with `model` (the id of the catalogue entry of
     * {@see FakeProviderFactory::ANTHROPIC_MODEL} on the `anthropic` fixture
     * connection, {@see modelId()}) filled where it is missing or null (as
     * in {@see \Dmstr\NeuronAiBundle\Entity\Agent::candidateDefaults()}).
     *
     * @param array<string, mixed> $configuration
     *
     * @return array<string, mixed>
     */
    public static function withConnection(array $configuration): array
    {
        $configuration['model'] ??= self::modelId();

        return $configuration;
    }

    /**
     * The record of a chat provider built from the fixture connection of
     * $type: `provider`, `connection`, `model`, `temperature`, `maxTokens`.
     *
     * @param 'anthropic'|'ollama' $type
     * @param string|null          $model null = the fake's model of the type
     *
     * @return array{provider: string, connection: string, model: string, temperature: ?float, maxTokens: ?int}
     */
    public static function requested(string $type = 'anthropic', ?string $model = null, ?float $temperature = null, ?int $maxTokens = null): array
    {
        return [
            'provider' => $type,
            'connection' => 'anthropic' === $type ? self::ANTHROPIC : self::OLLAMA,
            'model' => $model ?? self::fakeModel($type),
            'temperature' => $temperature,
            'maxTokens' => $maxTokens,
        ];
    }

    /**
     * @param 'anthropic'|'ollama' $type
     */
    private static function fakeModel(string $type): string
    {
        return 'anthropic' === $type ? FakeProviderFactory::ANTHROPIC_MODEL : FakeProviderFactory::OLLAMA_MODEL;
    }

    /**
     * @param 'anthropic'|'ollama' $type
     */
    private static function active(string $type): ApiConfiguration
    {
        $connection = AgentFixtures::providerConnections()[$type];
        if (!$connection->isActive()) {
            $connection->setActive(true);
            AgentFixtures::entityManager()->flush();
        }

        return $connection;
    }
}
