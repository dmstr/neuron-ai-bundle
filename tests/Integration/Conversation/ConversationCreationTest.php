<?php

// file generated with AI assistance: Claude Code - 2026-10-05 19:33:07 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Integration\Conversation;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use Dmstr\ApiConfiguration\Entity\ApiConfiguration;
use Dmstr\NeuronAiBundle\Conversation\ProviderSelection;
use Dmstr\NeuronAiBundle\Entity\Conversation;
use Dmstr\NeuronAiBundle\State\ConversationCreateProcessor;
use Dmstr\NeuronAiBundle\Testing\FakeModelLister;
use Dmstr\NeuronAiBundle\Testing\FakeProviderFactory;
use Dmstr\NeuronAiBundle\Testing\ProviderConnectionFixtures;
use Dmstr\NeuronAiBundle\Tests\Kernel\TestDatabase;
use Dmstr\NeuronAiBundle\Tests\Support\AgentFixtures;
use Dmstr\NeuronAiBundle\Tests\Support\ConversationConnections;
use Dmstr\NeuronAiBundle\Tests\Support\ProviderModelFixtureTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * `POST /api/conversations` without an agent: `model` is required (422 at
 * `model`, no default) and is a reference to the model catalogue (IRI, full
 * or partial UUID), which names the provider connection; a model name, an
 * unavailable entry, an entry of an inactive or foreign (`mcp`) connection
 * and one known to lack `completion` answer 422 at `model`; the model is
 * checked live (fake model lister: a model the connection does not offer or
 * an unreachable provider answers 422 at `model`, without the key);
 * `provider` and `connection` in the body answer 422 with and without
 * agent; the representation shows `provider`, the connection's name as
 * `connection`, the entry's IRI as `model` and its name as `modelName`, and
 * the conversation stores the entry, its connection and its name.
 *
 * Conversations are created as {@see AccessTest::USER_A} and removed by
 * {@see AccessTest::cleanUp()}, agents and the fixture connections by
 * {@see AgentFixtures::cleanUp()}; provider and `mcp` connections of
 * this test carry the prefix `test-conv-create-` (their catalogue entries
 * go with them). Needs the migrated test database (CI only).
 */
#[Group('database')]
final class ConversationCreationTest extends ApiTestCase
{
    use ProviderModelFixtureTrait;

    protected static ?bool $alwaysBootKernel = true;

    private const string PREFIX = 'test-conv-create-';

    private Client $client;

    protected function setUp(): void
    {
        TestDatabase::migrate(static::bootKernel());
        AccessTest::cleanUp();
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->lister()->reset();
    }

    protected function tearDown(): void
    {
        $this->lister()->reset();
        AccessTest::cleanUp();
        AgentFixtures::cleanUp();
        static::bootKernel();
        /** @var \Doctrine\DBAL\Connection $dbal */
        $dbal = static::getContainer()->get('doctrine.dbal.default_connection');
        $dbal->executeStatement('DELETE FROM dmstr_api_configuration WHERE name LIKE :prefix', ['prefix' => self::PREFIX.'%']);
        static::ensureKernelShutdown();
        parent::tearDown();
    }

    public function testModelIsRequired(): void
    {
        $response = $this->create(['title' => 'Ohne Modell']);

        self::assertSame(422, $response->getStatusCode(), $response->getContent(false));
        self::assertSame(['model' => ProviderSelection::modelRequired()], self::violations($response));
        self::assertSame(0, AccessTest::conversationCount());
    }

    public function testModelIsResolvedByIriAndUuidAndTheConnectionIsDerived(): void
    {
        $ollama = ConversationConnections::ollama();
        $entry = ConversationConnections::model('ollama');
        $id = $entry->getId()->toRfc4122();
        $references = [
            'iri' => $entry->iri(),
            'uuid' => $id,
            'partial uuid' => substr($id, 0, 13),
        ];

        foreach ($references as $case => $reference) {
            $response = $this->create(['model' => $reference]);

            self::assertSame(201, $response->getStatusCode(), $case.': '.$response->getContent(false));
            $data = $response->toArray();
            self::assertSame('ollama', $data['provider'], $case);
            self::assertSame(ConversationConnections::OLLAMA, $data['connection'], $case);
            self::assertSame($entry->iri(), $data['model'], $case);
            self::assertSame($entry->getName(), $data['modelName'], $case);

            $stored = $this->entityManager()->find(Conversation::class, $data['id']);
            self::assertInstanceOf(Conversation::class, $stored);
            self::assertSame($id, $stored->getProviderModel()?->getId()->toRfc4122(), $case);
            self::assertSame($ollama->getId()->toRfc4122(), $stored->getProviderConnection()?->getId()->toRfc4122(), $case);
            self::assertSame($entry->getName(), $stored->getModel(), $case);
        }

        self::assertContains($ollama->getName(), $this->lister()->getListedConnections(), 'the model was checked live');
    }

    public function testNamesUnusableEntriesAndForeignConnectionsAreRejectedAtModel(): void
    {
        $inactive = ProviderConnectionFixtures::ollama(self::PREFIX.'inactive', active: false);
        $mcp = new ApiConfiguration()
            ->setName(self::PREFIX.'mcp')
            ->setConfigJson(['mcp' => true, 'type' => 'mcp', 'endpoint_type' => 'rest', 'url' => 'https://mcp.example.org/mcp', 'transport' => 'streamable-http', 'auth_type' => 'none'])
            ->setActive(true);
        ProviderConnectionFixtures::persist($this->entityManager(), $inactive, $mcp);

        $ollama = ConversationConnections::ollama();
        $gone = self::catalogueModel($this->entityManager(), $ollama, self::PREFIX.'gone')->setAvailable(false);
        $embedder = self::catalogueModel($this->entityManager(), $ollama, self::PREFIX.'embedder', ['embedding']);
        $sleepy = self::catalogueModel($this->entityManager(), $inactive, 'llama3.2:latest');
        $foreign = self::catalogueModel($this->entityManager(), $mcp, 'search');
        $this->entityManager()->flush();

        $cases = [
            'model name' => [FakeProviderFactory::OLLAMA_MODEL, 'Unknown model'],
            'unavailable' => [$gone->iri(), 'is no longer offered by the connection'],
            'inactive connection' => [$sleepy->iri(), 'the connection is inactive'],
            'mcp connection' => [$foreign->iri(), 'cannot serve "completion"'],
            'no completion' => [$embedder->iri(), 'lacks the capability "completion"'],
        ];
        foreach ($cases as $case => [$reference, $text]) {
            $response = $this->create(['model' => $reference]);

            self::assertSame(422, $response->getStatusCode(), $case.': '.$response->getContent(false));
            $violations = self::violations($response);
            self::assertSame(['model'], array_keys($violations), $case);
            self::assertStringContainsString($text, $violations['model'], $case);
        }

        self::assertSame(0, AccessTest::conversationCount());
    }

    public function testModelIsCheckedLiveAgainstTheConnection(): void
    {
        $model = ConversationConnections::modelIri();

        $this->lister()->offer(['claude-other'], ConversationConnections::ANTHROPIC);
        $response = $this->create(['model' => $model]);
        self::assertSame(422, $response->getStatusCode(), $response->getContent(false));
        self::assertSame(['model' => \sprintf('Model "%s" is not offered by connection "%s" (offered: claude-other).', FakeProviderFactory::ANTHROPIC_MODEL, ConversationConnections::ANTHROPIC)], self::violations($response));

        $this->lister()->unauthenticated(ConversationConnections::ANTHROPIC);
        $response = $this->create(['model' => $model]);
        self::assertSame(422, $response->getStatusCode(), $response->getContent(false));
        $violations = self::violations($response);
        self::assertSame(['model'], array_keys($violations));
        self::assertStringContainsString('could not be verified (provider unreachable: ', $violations['model']);
        self::assertStringNotContainsString(ProviderConnectionFixtures::PLACEHOLDER_API_KEY, $response->getContent(false));

        self::assertSame(0, AccessTest::conversationCount());
    }

    public function testProviderAndConnectionInTheBodyAreRejectedWithAndWithoutAgent(): void
    {
        // The agent helpers boot kernels of their own: publish first, then
        // take a fresh client.
        $id = AgentFixtures::createAgent('conv-create-provider');
        AgentFixtures::publish($id);
        $this->client = static::createClient();
        $this->client->disableReboot();
        $model = ConversationConnections::modelIri();

        foreach ([
            '"provider" is read-only' => 'provider',
            ConversationCreateProcessor::CONNECTION_REMOVED => 'connection',
        ] as $text => $key) {
            $plain = $this->create(['model' => $model, $key => 'value']);
            self::assertSame(422, $plain->getStatusCode(), $plain->getContent(false));
            self::assertStringContainsString($text, (string) ($plain->toArray(false)['detail'] ?? ''), $key);

            $agent = $this->create(['agent' => AgentFixtures::PREFIX.'conv-create-provider', $key => 'value']);
            self::assertSame(422, $agent->getStatusCode(), $agent->getContent(false));
            self::assertStringContainsString($text, (string) ($agent->toArray(false)['detail'] ?? ''), $key);
        }

        self::assertSame(0, AccessTest::conversationCount());
    }

    /**
     * @param array<string, mixed> $body
     */
    private function create(array $body): ResponseInterface
    {
        $response = AccessTest::send($this->client, 'POST', '/api/conversations', AccessTest::USER_A, $body);
        $response->getStatusCode();

        return $response;
    }

    /**
     * propertyPath => message of a 422 validation response.
     *
     * @return array<string, string>
     */
    private static function violations(ResponseInterface $response): array
    {
        $violations = [];
        foreach ($response->toArray(false)['violations'] ?? [] as $violation) {
            $violations[(string) $violation['propertyPath']] = (string) $violation['message'];
        }

        return $violations;
    }

    private function lister(): FakeModelLister
    {
        $lister = static::getContainer()->get(FakeModelLister::class);
        self::assertInstanceOf(FakeModelLister::class, $lister);

        return $lister;
    }

    private function entityManager(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');

        return $entityManager;
    }
}
