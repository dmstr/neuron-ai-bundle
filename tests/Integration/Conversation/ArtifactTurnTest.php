<?php

// file generated with AI assistance: Claude Code - 2026-10-16 09:07:36 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Integration\Conversation;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use Dmstr\NeuronAiBundle\Anonymous\SystemActor;
use Dmstr\NeuronAiBundle\Artifact\ArtifactToolkit;
use Dmstr\NeuronAiBundle\Conversation\ConversationTurnService;
use Dmstr\NeuronAiBundle\Conversation\MessageKind;
use Dmstr\NeuronAiBundle\Entity\Agent;
use Dmstr\NeuronAiBundle\Entity\AgentVersion;
use Dmstr\NeuronAiBundle\Entity\Conversation;
use Dmstr\NeuronAiBundle\Entity\Message;
use Dmstr\NeuronAiBundle\Stream\StreamEvent;
use Dmstr\NeuronAiBundle\Tests\Kernel\TestDatabase;
use Dmstr\NeuronAiBundle\Tests\Kernel\TestKernel;
use Dmstr\NeuronAiBundle\Tests\Support\AgentFixtures;
use Dmstr\NeuronAiBundle\Tests\Support\AnonymousFixtures;
use Dmstr\NeuronAiBundle\Tests\Support\ArtifactTestFiles;
use Dmstr\NeuronAiBundle\Tests\Support\StreamTestSupport;
use Doctrine\DBAL\ArrayParameterType;
use NeuronAI\Testing\RequestRecord;
use NeuronAI\Tools\ProviderToolInterface;
use NeuronAI\Tools\ToolInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Artifact tools in turns of agent conversations end to
 * end through the API with the fake provider and the artifact root of the
 * test kernel ({@see TestKernel::artifactRoot()}, created here when missing):
 *
 *  - the owner with `ROLE_ARTIFACT_WRITER` (via `X-Test-Roles`) gets the
 *    five tools and writes into the conversation's working folder, a write
 *    to `../x` is a tool error in record and stream, and the storage root
 *    appears in no tool result, no stream event and no system prompt;
 *  - the owner without the role gets the three read tools;
 *  - the owner finds the file again in a later conversation, a second user
 *    (`X-Test-User`) never sees it;
 *  - no artifact tools for an anonymous conversation, a conversation with
 *    `origin` `eval` or `replay`, a turn under the system actor (the way
 *    evaluation runs and replays turn), the stateless agent chat and the
 *    pinning of a new conversation; the start turn of a new conversation
 *    is a streamed turn of its owner and offers them like any turn.
 *
 * The judge maps versions without an access by construction
 * ({@see \Dmstr\NeuronAiBundle\Agent\VersionTurnMapper::map()}; unit tests
 * in VersionTurnMapperTest). Needs the migrated test database (CI only).
 */
#[Group('database')]
final class ArtifactTurnTest extends ApiTestCase
{
    use StreamTestSupport {
        setUp as private streamSetUp;
        tearDown as private streamTearDown;
    }

    protected static ?bool $alwaysBootKernel = true;

    private const string WRITER = 'test-user';
    private const string SECOND = 'test-artifact-b';

    private const string WRITER_ROLES = 'ROLE_USER,ROLE_ARTIFACT_WRITER';

    private string $root;

    protected function setUp(): void
    {
        TestDatabase::migrate(static::bootKernel());
        $this->streamSetUp();
        $root = TestKernel::artifactRoot();
        if (!is_dir($root)) {
            mkdir($root, 0o750, true);
        }
        $this->root = (string) realpath($root);
        $this->removeUserFolders();
    }

    protected function tearDown(): void
    {
        $this->dbal()->executeStatement('DELETE FROM neuron_artifact WHERE owner_subject IN (:owners)', ['owners' => self::owners()], ['owners' => ArrayParameterType::STRING]);
        $this->removeUserFolders();
        $this->streamTearDown();
        AgentFixtures::cleanUp();
        AnonymousFixtures::cleanUp();
    }

    public function testOwnerWithTheWriterRoleWritesIntoTheWorkingFolder(): void
    {
        $conversation = $this->ownedConversation($this->artifactVersion('writer'), self::WRITER);
        $id = $conversation->getId()->toRfc4122();
        $path = $id.'/notes.md';

        $before = $this->providerMarks();
        $this->factory()->queueToolCall('write_file', ['file_path' => $path, 'content' => '# Notizen'], 'call-write')->queueAnswer('Gespeichert.');
        $response = $this->turnAs($conversation, 'Leg Notizen an.', self::WRITER_ROLES);

        self::assertSame(201, $response->getStatusCode(), $response->getContent(false));
        $request = $this->requestsSince($before)[0];
        self::assertSame(ArtifactToolkit::TOOL_NAMES, self::toolNames($request));
        $prompt = (string) $request->systemPrompt?->getContent();
        self::assertStringContainsString('<TOOLS-GUIDELINES>', $prompt);
        self::assertStringContainsString($id.'/', $prompt);
        self::assertStringNotContainsString($this->root, $prompt);

        $record = $this->toolRecord($conversation);
        self::assertSame('write_file', $record->getToolName());
        self::assertFalse($record->isToolError());
        self::assertStringNotContainsString($this->root, (string) $record->getToolResult());
        self::assertSame('# Notizen', file_get_contents($this->root.'/'.self::WRITER.'/'.$path));
        self::assertSame(1, (int) $this->dbal()->fetchOne('SELECT COUNT(*) FROM neuron_artifact WHERE owner_subject = :owner AND path = :path', ['owner' => self::WRITER, 'path' => $path]));
    }

    public function testRefusedWriteIsAToolErrorInRecordAndStream(): void
    {
        $conversation = $this->ownedConversation($this->artifactVersion('refused'), self::WRITER);

        $before = $this->providerMarks();
        $this->factory()->queueToolCall('write_file', ['file_path' => '../x', 'content' => 'x'], 'call-out')->queueAnswer('Ging nicht.');
        $events = $this->events($this->stream($conversation, ['content' => 'Schreib nach oben.'], self::headers(self::WRITER_ROLES)));

        $results = array_values(array_filter($events, static fn (array $event): bool => StreamEvent::TOOL_RESULT === $event['event']));
        self::assertCount(1, $results);
        self::assertSame('write_file', $results[0]['data']['name']);
        self::assertTrue($results[0]['data']['error']);
        foreach ($events as $event) {
            self::assertStringNotContainsString($this->root, (string) json_encode($event['data'], \JSON_UNESCAPED_SLASHES), $event['event']);
        }
        foreach ($this->requestsSince($before) as $request) {
            self::assertStringNotContainsString($this->root, (string) $request->systemPrompt?->getContent());
        }

        $record = $this->toolRecord($conversation);
        self::assertTrue($record->isToolError());
        self::assertStringNotContainsString($this->root, (string) $record->getToolResult());
        self::assertFileDoesNotExist($this->root.'/x');
        self::assertFileDoesNotExist($this->root.'/'.self::WRITER.'/x');
    }

    public function testOwnerWithoutTheWriterRoleGetsTheReadToolsOnly(): void
    {
        $conversation = $this->ownedConversation($this->artifactVersion('reader'), self::WRITER);

        $before = $this->providerMarks();
        $this->factory()->queueAnswer('Ich kann nur lesen.');
        $response = $this->turnAs($conversation, 'Was liegt da?', 'ROLE_USER');

        self::assertSame(201, $response->getStatusCode(), $response->getContent(false));
        self::assertSame(['read_file', 'grep_file_content', 'glob_path'], self::toolNames($this->requestsSince($before)[0]));
        self::assertDirectoryExists($this->root.'/'.self::WRITER.'/'.$conversation->getId()->toRfc4122());
    }

    public function testTheOwnerFindsTheFileLaterAndASecondUserNever(): void
    {
        $version = $this->artifactVersion('later');
        $first = $this->ownedConversation($version, self::WRITER);
        $path = $first->getId()->toRfc4122().'/report.md';
        $this->factory()->queueToolCall('write_file', ['file_path' => $path, 'content' => 'Bericht A'], 'call-a')->queueAnswer('Gespeichert.');
        self::assertSame(201, $this->turnAs($first, 'Schreib den Bericht.', self::WRITER_ROLES)->getStatusCode());

        // A later conversation of the same user reads it.
        $later = $this->ownedConversation($version, self::WRITER);
        $this->factory()->queueToolCall('read_file', ['file_path' => $path], 'call-read')->queueAnswer('Gelesen.');
        self::assertSame(201, $this->turnAs($later, 'Lies den Bericht.', self::WRITER_ROLES)->getStatusCode());
        $read = $this->toolRecord($later);
        self::assertFalse($read->isToolError());
        self::assertStringContainsString('Bericht A', (string) $read->getToolResult());

        // A second user lists nothing of it and cannot read it.
        $foreign = $this->ownedConversation($version, self::SECOND);
        $this->factory()->queueToolCall('glob_path', ['directory' => '.', 'pattern' => '**/*'], 'call-glob')
            ->queueToolCall('read_file', ['file_path' => $path], 'call-foreign')
            ->queueAnswer('Nichts da.');
        self::assertSame(201, $this->turnAs($foreign, 'Was gibt es?', self::WRITER_ROLES, self::SECOND)->getStatusCode());
        $records = $this->toolRecords($foreign);
        self::assertSame(['glob_path', 'read_file'], array_map(static fn (Message $m): ?string => $m->getToolName(), $records));
        self::assertStringNotContainsString('report.md', (string) $records[0]->getToolResult());
        self::assertTrue($records[1]->isToolError());
        self::assertStringNotContainsString('Bericht A', (string) $records[1]->getToolResult());
        self::assertDirectoryDoesNotExist($this->root.'/'.self::SECOND.'/'.$first->getId()->toRfc4122());
    }

    /**
     * Evaluation and replay conversations carry an `origin`; even their
     * owner gets no artifact tools, and no folder is created.
     */
    public function testConversationsWithAnOriginGetNoArtifactTools(): void
    {
        foreach ([Conversation::ORIGIN_EVAL, Conversation::ORIGIN_REPLAY] as $origin) {
            $conversation = $this->ownedConversation($this->artifactVersion('origin-'.$origin), self::WRITER);
            $conversation->setOrigin($origin);
            $this->entityManager()->flush();

            $before = $this->providerMarks();
            $this->factory()->queueAnswer('Ohne Dateien.');
            $response = $this->turnAs($conversation, 'Frage', self::WRITER_ROLES);

            self::assertSame(201, $response->getStatusCode(), $origin.': '.$response->getContent(false));
            self::assertSame([], self::artifactNames($this->requestsSince($before)[0]), $origin);
            self::assertDirectoryDoesNotExist($this->root.'/'.self::WRITER.'/'.$conversation->getId()->toRfc4122());
        }
    }

    /**
     * Evaluation runs and replays turn under the system actor
     * (BatchRunner, ConversationReplayRunner); its token is not the owner.
     */
    public function testATurnUnderTheSystemActorGetsNoArtifactTools(): void
    {
        $conversation = $this->ownedConversation($this->artifactVersion('system'), self::WRITER);
        /** @var ConversationTurnService $turns */
        $turns = static::getContainer()->get(ConversationTurnService::class);
        /** @var TokenStorageInterface $tokens */
        $tokens = static::getContainer()->get('security.token_storage');

        $before = $this->providerMarks();
        $this->factory()->queueAnswer('Ohne Dateien.');
        new SystemActor($tokens)->run('system:neuron:eval', static fn (): Message => $turns->turn($conversation, 'Frage'));

        self::assertSame([], self::artifactNames($this->requestsSince($before)[0]));
        self::assertDirectoryDoesNotExist($this->root.'/'.self::WRITER.'/'.$conversation->getId()->toRfc4122());
    }

    public function testAnonymousConversationGetsNoArtifactTools(): void
    {
        [$id, $token] = $this->anonymousAgentConversation('artifact-anon', 'Ablagebot', ['artifactTools' => true]);

        $before = $this->providerMarks();
        $this->factory()->queueAnswer('Ohne Dateien.');
        $response = $this->anonymousTurn($token, ['content' => 'Frage']);

        self::assertSame(201, $response->getStatusCode(), $response->getContent(false));
        self::assertSame([], self::artifactNames($this->requestsSince($before)[0]));
        self::assertDirectoryDoesNotExist($this->root.'/'.Conversation::ANONYMOUS_OWNER_PREFIX.$id);
    }

    /**
     * The stateless agent chat has no conversation and no working folder.
     */
    public function testStatelessAgentChatGetsNoArtifactTools(): void
    {
        $version = $this->artifactVersion('chat');

        $before = $this->providerMarks();
        $this->factory()->queueAnswer('Ohne Dateien.');
        $response = AgentFixtures::send($this->client, 'POST', AgentFixtures::AGENTS.'/'.$version->getAgent()->getId().'/chat', self::WRITER_ROLES, [
            'messages' => [['role' => 'user', 'content' => 'Frage']],
        ]);

        self::assertSame(200, $response->getStatusCode(), $response->getContent(false));
        self::assertSame([], self::artifactNames($this->requestsSince($before)[0]));
    }

    /**
     * Creating a conversation pins the version without artifact tools (the
     * pinning maps it without an access). The start turn is the owner's
     * first streamed turn, decided in the creating request with the
     * owner's token like every turn, so it offers the tools.
     */
    public function testPinningBuildsNoArtifactToolsAndTheStartTurnIsAnOrdinaryTurn(): void
    {
        $version = $this->artifactVersion('start', ['startMessage' => ['de' => 'Hallo.']]);

        $before = $this->providerMarks();
        $this->factory()->queueAnswer('Willkommen.');
        $response = $this->client->request('POST', '/api/conversations', [
            'headers' => ['Content-Type' => 'application/ld+json', 'Accept' => 'application/ld+json'] + self::headers(self::WRITER_ROLES),
            'json' => ['agent' => $version->getAgent()->getSlug()],
        ]);

        self::assertSame(201, $response->getStatusCode(), $response->getContent(false));
        $id = (string) $response->toArray()['id'];
        $this->conversationIds[] = $id;
        $requests = $this->requestsSince($before);
        self::assertCount(1, $requests, 'only the start turn calls the provider; the pinning does not');
        self::assertSame(ArtifactToolkit::TOOL_NAMES, self::artifactNames($requests[0]));
        self::assertDirectoryExists($this->root.'/'.self::WRITER.'/'.$id);
    }

    // --- Helpers -------------------------------------------------------------

    /**
     * An enabled agent whose current version has `artifactTools` and no
     * MCP tools, stored through the entity manager.
     *
     * @param array<string, mixed> $keys
     */
    private function artifactVersion(string $slug, array $keys = []): AgentVersion
    {
        return $this->storeAgentVersion('artifact-'.$slug, $keys + [
            'artifactTools' => true,
            'instructions' => ['de' => 'Du bist ein Ablageassistent.'],
        ] + Agent::candidateDefaults(), 'Ablagebot');
    }

    /**
     * A conversation of $owner pinned to $version.
     */
    private function ownedConversation(AgentVersion $version, string $owner): Conversation
    {
        $conversation = $this->pinnedConversation($version)->setOwnerSubject($owner);
        $this->entityManager()->flush();

        return $conversation;
    }

    /**
     * `POST .../messages` as $user (default: the identifier the roles
     * derive, `test-user` without ROLE_ADMIN) with $roles.
     */
    private function turnAs(Conversation $conversation, string $content, string $roles, ?string $user = null): ResponseInterface
    {
        $response = $this->client->request('POST', '/api/conversations/'.$conversation->getId().'/messages', [
            'headers' => ['Content-Type' => 'application/ld+json', 'Accept' => 'application/ld+json'] + self::headers($roles, $user),
            'json' => ['content' => $content],
        ]);
        $response->getStatusCode();

        return $response;
    }

    /**
     * @return array<string, string>
     */
    private static function headers(string $roles, ?string $user = null): array
    {
        return ['X-Test-Roles' => $roles] + (null === $user ? [] : ['X-Test-User' => $user]);
    }

    /**
     * @return list<Message> the tool-call records of the conversation
     */
    private function toolRecords(Conversation $conversation): array
    {
        return array_values(array_filter($this->records($conversation), static fn (Message $m): bool => MessageKind::TOOL_CALL === $m->getKind()));
    }

    private function toolRecord(Conversation $conversation): Message
    {
        $records = $this->toolRecords($conversation);
        self::assertCount(1, $records);

        return $records[0];
    }

    /**
     * @return list<string>
     */
    private static function toolNames(RequestRecord $record): array
    {
        return array_values(array_map(
            static fn (ToolInterface|ProviderToolInterface $tool): string => $tool->getName(),
            $record->tools,
        ));
    }

    /**
     * @return list<string> the offered tool names that are artifact tools or excluded file tools
     */
    private static function artifactNames(RequestRecord $record): array
    {
        return array_values(array_intersect(self::toolNames($record), [...ArtifactToolkit::TOOL_NAMES, 'bash', 'delete_file', 'parse_file']));
    }

    /**
     * @return list<string>
     */
    private static function owners(): array
    {
        return [self::WRITER, self::SECOND];
    }

    private function removeUserFolders(): void
    {
        foreach (self::owners() as $owner) {
            ArtifactTestFiles::remove($this->root.'/'.$owner);
        }
    }
}
