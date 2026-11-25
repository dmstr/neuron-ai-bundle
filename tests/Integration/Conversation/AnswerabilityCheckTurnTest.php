<?php

// file generated with AI assistance: Claude Code - 2026-10-04 13:45:01 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Integration\Conversation;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use Dmstr\NeuronAiBundle\Conversation\Gate\AnswerabilityCheck;
use Dmstr\NeuronAiBundle\Conversation\Gate\GateClose;
use Dmstr\NeuronAiBundle\Conversation\Gate\GateReply;
use Dmstr\NeuronAiBundle\Conversation\MessageKind;
use Dmstr\NeuronAiBundle\Conversation\MessageStatus;
use Dmstr\NeuronAiBundle\Embeddings\EmbeddingModelRegistry;
use Dmstr\NeuronAiBundle\Embeddings\EmbeddingsProviderLocator;
use Dmstr\NeuronAiBundle\Entity\Agent;
use Dmstr\NeuronAiBundle\Entity\AgentVersion;
use Dmstr\NeuronAiBundle\Entity\Collection;
use Dmstr\NeuronAiBundle\Entity\Conversation;
use Dmstr\NeuronAiBundle\Entity\Message;
use Dmstr\NeuronAiBundle\Stream\StreamEvent;
use Dmstr\NeuronAiBundle\Testing\FakeProviderFactory;
use Dmstr\NeuronAiBundle\Tests\Kernel\TestDatabase;
use Dmstr\NeuronAiBundle\Tests\Support\AgentFixtures;
use Dmstr\NeuronAiBundle\Tests\Support\ConversationConnections;
use Dmstr\NeuronAiBundle\Tests\Support\StreamTestSupport;
use Dmstr\NeuronPgvectorStore\PgVectorStore;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\RAG\Document;
use NeuronAI\Testing\RequestRecord;
use PHPUnit\Framework\Attributes\Group;

/**
 * The answerability check on the conversation turns of agent versions end
 * to end:
 *
 * - Refusal: after the retrieval, the check's votes and no answer call;
 *   the record `[user, unanswerable_refusal]` with the close reason of the
 *   same name, the `unanswerableRefusal` text of the conversation's
 *   language with the fallback to the default language, the retrieved
 *   chunks as its sources, the gate tokens as usage and the reason and
 *   votes only in `providerPayload.gates.answerability` (not in the
 *   owner's message list); the stream sends `sources` (the retrieved
 *   chunks), `gate` and `done` with the new close reason.
 * - Majority: three votes split 2:1 refuse and 2:1 pass, each vote a
 *   provider call, the votes list and the summed tokens on the record.
 * - Off: a version without collections makes no gate call; a version
 *   stored without the 10b keys runs exactly as before.
 * - Previous question: the gate call of a later turn carries the previous
 *   user question (a refused turn excluded), the first turn none.
 * - Fail-open: a provider error and an unparsable verdict let the turn
 *   answer, with the failure noted in the payload.
 *
 * Votes are scripted on the shared fake (script mode: the votes come
 * before the answer call of a turn). Every version searches one collection
 * of {@see self::CHUNKS} at retrieval depth 3, so the pool of every turn is
 * the whole collection. Fixture versions are written through the entity
 * manager. Needs the migrated test database (CI only).
 */
#[Group('database')]
final class AnswerabilityCheckTurnTest extends ApiTestCase
{
    use StreamTestSupport {
        setUp as private streamSetUp;
        tearDown as private streamTearDown;
    }

    protected static ?bool $alwaysBootKernel = true;

    public const string REFUSAL_DE = 'Dazu finde ich nichts in den Quellen.';
    public const string REFUSAL_EN = 'I found nothing on this in the sources.';
    public const string REASON = 'The context does not cover tyre pressure.';

    /** The chunks of the collection of every fixture version. */
    public const array CHUNKS = ['Die Spindel muss alle 500 Stunden gewartet werden.', 'Der Hauptschalter sitzt links.'];

    private const string QUESTION = 'Wie hoch ist der Reifendruck?';
    private const string ANSWER = 'Die Spindel wird alle 500 Stunden gewartet.';

    /** Usage of one scripted vote and of the record of a one-vote refusal. */
    private const array GATE_USAGE = ['input_tokens' => 80, 'output_tokens' => 12, 'cached_input_tokens' => 0, 'reasoning_tokens' => 0, 'gates' => ['answerability' => ['input_tokens' => 80, 'output_tokens' => 12]]];

    protected function setUp(): void
    {
        TestDatabase::migrate(static::bootKernel());
        $this->streamSetUp();
        $this->usesStore = true;
    }

    protected function tearDown(): void
    {
        foreach ($this->conversationIds as $id) {
            $this->dbal()->executeStatement('DELETE FROM dmstr_log_entries WHERE object_class = :class AND object_id = :id', ['class' => Conversation::class, 'id' => $id]);
        }
        $this->streamTearDown();
        AgentFixtures::cleanUp();
    }

    /**
     * Candidate keys of a version with the check on at $votes votes: languages
     * de and en (default de), the refusal text in both; $overrides win.
     * Shared with the agent-chat and evaluation tests of the check.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    public static function checkConfiguration(array $overrides = [], int $votes = 1): array
    {
        return $overrides + [
            'languages' => ['de', 'en'],
            'defaultLanguage' => 'de',
            'answerabilityCheck' => true,
            'answerabilityVotes' => $votes,
            'templates' => ['unanswerableRefusal' => ['de' => self::REFUSAL_DE, 'en' => self::REFUSAL_EN]],
        ];
    }

    /**
     * One vote as the next scripted answer of $factory, with 80 input and
     * 12 output tokens.
     */
    public static function queueVote(FakeProviderFactory $factory, bool $answerable, string $reason = self::REASON): void
    {
        $factory->getChatProvider()->addResponses(
            new AssistantMessage((string) json_encode(['answerable' => $answerable, 'reason' => $reason]))->setUsage(new Usage(80, 12)),
        );
    }

    /**
     * Embeds and stores $contents as chunks of $collection (model
     * `nomic-embed-text`) on the current container; the caller removes
     * them.
     *
     * @param list<string> $contents
     */
    public static function addChunks(Collection $collection, array $contents): void
    {
        $container = static::getContainer();
        $documents = [];
        foreach ($contents as $number => $content) {
            $document = (new Document($content))
                ->setMetadata([
                    'collection_id' => (string) $collection->getId(),
                    'source_url' => 'https://example.org/answerability/'.$number,
                    'chunk_number' => $number,
                ]);
            $documents[] = $document;
        }

        /** @var EmbeddingModelRegistry $registry */
        $registry = $container->get(EmbeddingModelRegistry::class);
        /** @var EmbeddingsProviderLocator $locator */
        $locator = $container->get(EmbeddingsProviderLocator::class);
        /** @var PgVectorStore $store */
        $store = $container->get(PgVectorStore::class);
        $store->forModel('nomic-embed-text', $registry->get('nomic-embed-text')->dimension)
            ->addDocuments($locator->for(ConversationConnections::ollama(), 'nomic-embed-text')->embedDocuments($documents));
    }

    /**
     * The contents of serialized sources, sorted (the pool order follows
     * the fake vectors).
     *
     * @param list<array<string, mixed>> $sources
     *
     * @return list<string>
     */
    public static function sourceContents(array $sources): array
    {
        $contents = array_map(static fn (array $source): string => (string) $source['content'], $sources);
        sort($contents);

        return $contents;
    }

    public function testStoredVersionWithoutTheKeysRunsNoCheck(): void
    {
        $configuration = $this->versionConfiguration('legacy', []);
        unset($configuration['answerabilityCheck'], $configuration['answerabilityVotes']);
        $legacy = $this->storeVersion('legacy', $configuration);

        foreach (['sync', 'stream'] as $mode) {
            $conversation = $this->agentConversation($legacy);
            $this->queueAnswer();
            $marks = $this->providerMarks();

            if ('sync' === $mode) {
                $data = $this->turn($conversation, ['content' => self::QUESTION])->toArray();
                self::assertResponseStatusCodeSame(201);
                self::assertSame([MessageKind::TEXT, self::ANSWER], [$data['kind'], $data['content']], $mode);
                self::assertSame(self::sortedChunks(), self::sourceContents($data['sources']), $mode);
            } else {
                $events = $this->events($this->stream($conversation, ['content' => self::QUESTION]));
                self::assertNotContains(StreamEvent::GATE, self::typesOf($events), $mode);
                self::assertSame(['input_tokens' => 50, 'output_tokens' => 10, 'cached_input_tokens' => 0, 'reasoning_tokens' => 0], $events[array_key_last($events)]['data']['usage'], $mode);
            }

            self::assertCount(1, $this->requestsSince($marks), $mode.': only the answer call');
            $records = $this->records($conversation);
            self::assertStringNotContainsString('gates', (string) json_encode(array_map(static fn (Message $m): array => [$m->getUsage(), $m->getProviderPayload()], $records)), $mode);
        }
    }

    public function testCheckRefusesTheSyncTurnAfterRetrievalWithoutSources(): void
    {
        $version = $this->publishedVersion('sync');

        foreach (['de' => self::REFUSAL_DE, 'en' => self::REFUSAL_EN] as $language => $text) {
            $conversation = $this->agentConversation($version, $language);
            self::queueVote($this->factory(), false);
            $marks = $this->providerMarks();

            $response = $this->turn($conversation, ['content' => self::QUESTION]);

            self::assertSame(201, $response->getStatusCode(), $response->getContent(false));
            $data = $response->toArray();
            self::assertSame([MessageKind::UNANSWERABLE_REFUSAL, $text, GateClose::REASON_UNANSWERABLE, MessageStatus::COMPLETE], [$data['kind'], $data['content'], $data['closeReason'], $data['status']], $language);
            self::assertSame([], $data['sources'], $language);
            self::assertSame(self::GATE_USAGE, $data['usage'], $language);
            self::assertArrayNotHasKey('providerPayload', $data, $language);
            self::assertStringNotContainsString(self::REASON, $response->getContent(), $language);

            // The retrieval ran, then only the vote: no answer call.
            self::assertNotSame([], $this->factory()->getEmbeddingsRecorder()->getRecordedTexts(), $language);
            self::assertSame($marks[0] + 1, \count($this->factory()->getRequestedProviders()), $language.': one provider built');
            self::assertGateCall($this->onlyRequestSince($marks), self::QUESTION, null, $language);

            $records = $this->records($conversation);
            self::assertSame([[Message::SPEAKER_USER, MessageKind::TEXT], [Message::SPEAKER_ASSISTANT, MessageKind::UNANSWERABLE_REFUSAL]], array_map(static fn (Message $m): array => [$m->getRole(), $m->getKind()], $records), $language);
            $refusal = $records[1];
            self::assertSame([1, GateClose::REASON_UNANSWERABLE, null], [$refusal->getStep(), $refusal->getCloseReason(), $refusal->getFrame()], $language);
            self::assertSame([], $refusal->getSources(), $language);
            self::assertSame([
                'step' => 1,
                'part' => 'gate',
                'gate' => 'answerability',
                'gates' => ['answerability' => [
                    'verdict' => 'refuse',
                    'reason' => self::REASON,
                    'votes' => [['verdict' => 'refuse', 'reason' => self::REASON]],
                    'usage' => ['input_tokens' => 80, 'output_tokens' => 12],
                ]],
            ], $refusal->getProviderPayload(), $language);
            self::assertSame([1, 92], [$this->reload($conversation)->getTurnCount(), $this->reload($conversation)->getTokensUsed()], $language);

            // The owner's message list shows the refusal, never the reason.
            $read = $this->client->request('GET', '/api/conversations/'.$conversation->getId().'/messages', ['headers' => ['Accept' => 'application/ld+json']]);
            self::assertSame(200, $read->getStatusCode(), $language);
            $members = $read->toArray()['hydra:member'];
            self::assertContains($text, array_column($members, 'content'), $language);
            foreach ($members as $member) {
                self::assertArrayNotHasKey('providerPayload', $member, $language);
            }
            self::assertStringNotContainsString(self::REASON, (string) json_encode($members), $language);
        }

        // A language without its own text: the default-language text.
        $germanOnly = $this->publishedVersion('fallback', ['templates' => ['unanswerableRefusal' => ['de' => self::REFUSAL_DE]]]);
        self::queueVote($this->factory(), false);
        $data = $this->turn($this->agentConversation($germanOnly, 'en'), ['content' => self::QUESTION])->toArray();
        self::assertResponseStatusCodeSame(201);
        self::assertSame([MessageKind::UNANSWERABLE_REFUSAL, self::REFUSAL_DE], [$data['kind'], $data['content']]);
    }

    public function testCheckRefusesTheStreamedTurnWithoutSourcesGateAndDone(): void
    {
        $conversation = $this->agentConversation($this->publishedVersion('stream'), 'en');
        self::queueVote($this->factory(), false);
        $marks = $this->providerMarks();

        $sent = $this->events($this->stream($conversation, ['content' => self::QUESTION]));

        self::assertSame([StreamEvent::SOURCES, StreamEvent::GATE, StreamEvent::DONE], self::typesOf($sent));
        self::assertIncreasingIds($sent);
        self::assertGateCall($this->onlyRequestSince($marks), self::QUESTION, null, 'stream');
        [$sources, $gate, $done] = $sent;
        $records = $this->records($conversation);
        $final = $records[array_key_last($records)];
        $messageId = (string) $final->getId();
        self::assertSame($messageId, $sources['data']['messageId']);
        self::assertSame([], $sources['data']['sources'], 'a refusal shows no sources');
        self::assertSame([], $final->getSources(), 'the record has no sources either');
        self::assertSame(['messageId' => $messageId, 'gate' => 'answerability', 'language' => 'en', 'message' => self::REFUSAL_EN], $gate['data']);
        self::assertSame([
            'messageId' => $messageId,
            'status' => MessageStatus::COMPLETE,
            'provider' => 'anthropic',
            'model' => FakeProviderFactory::ANTHROPIC_MODEL,
            'connection' => ConversationConnections::ANTHROPIC,
            'usage' => self::GATE_USAGE,
            'closeReason' => GateClose::REASON_UNANSWERABLE,
        ], $done['data']);
        self::assertStringNotContainsString(self::REASON, (string) json_encode($sent));
        self::assertSame([MessageKind::UNANSWERABLE_REFUSAL, self::REFUSAL_EN, GateClose::REASON_UNANSWERABLE], [$final->getKind(), $final->getContent(), $final->getCloseReason()]);
        self::assertSame(92, $this->reload($conversation)->getTokensUsed());
    }

    public function testThreeVotesDecideByStrictMajority(): void
    {
        $version = $this->publishedVersion('votes', [], 3);

        // 2:1 refuse, the first refusing reason wins; every vote is a call.
        $conversation = $this->agentConversation($version);
        self::queueVote($this->factory(), true, 'Covered.');
        self::queueVote($this->factory(), false, 'Not covered.');
        self::queueVote($this->factory(), false, 'Only the spindle.');
        $marks = $this->providerMarks();

        $data = $this->turn($conversation, ['content' => self::QUESTION])->toArray();

        self::assertResponseStatusCodeSame(201);
        self::assertSame([MessageKind::UNANSWERABLE_REFUSAL, self::REFUSAL_DE], [$data['kind'], $data['content']]);
        self::assertSame(['input_tokens' => 240, 'output_tokens' => 36], array_intersect_key($data['usage'], ['input_tokens' => 0, 'output_tokens' => 0]));
        $requests = $this->requestsSince($marks);
        self::assertCount(3, $requests);
        foreach ($requests as $request) {
            self::assertGateCall($request, self::QUESTION, null, 'refuse');
        }
        $records = $this->records($conversation);
        $payload = $records[array_key_last($records)]->getProviderPayload()['gates']['answerability'] ?? [];
        self::assertSame(['refuse', 'Not covered.'], [$payload['verdict'] ?? null, $payload['reason'] ?? null]);
        self::assertSame(['pass', 'refuse', 'refuse'], array_column($payload['votes'] ?? [], 'verdict'));

        // 2:1 pass: the turn answers, the final record carries the votes.
        $conversation = $this->agentConversation($version);
        self::queueVote($this->factory(), false, 'Not covered.');
        self::queueVote($this->factory(), true, 'Covered.');
        self::queueVote($this->factory(), true, 'Covered too.');
        $this->queueAnswer();
        $marks = $this->providerMarks();

        $data = $this->turn($conversation, ['content' => 'Wann wird die Spindel gewartet?'])->toArray();

        self::assertResponseStatusCodeSame(201);
        self::assertSame([MessageKind::TEXT, self::ANSWER, null], [$data['kind'], $data['content'], $data['closeReason']]);
        self::assertSame(['input_tokens' => 290, 'output_tokens' => 46, 'cached_input_tokens' => 0, 'reasoning_tokens' => 0, 'gates' => ['answerability' => ['input_tokens' => 240, 'output_tokens' => 36]]], $data['usage']);
        self::assertCount(4, $this->requestsSince($marks), 'three votes and the answer');
        $records = $this->records($conversation);
        $payload = $records[array_key_last($records)]->getProviderPayload()['gates']['answerability'] ?? [];
        self::assertSame(['pass', 'Covered.'], [$payload['verdict'] ?? null, $payload['reason'] ?? null]);
        self::assertSame(['refuse', 'pass', 'pass'], array_column($payload['votes'] ?? [], 'verdict'));
        self::assertSame(336, $this->reload($conversation)->getTokensUsed());
    }

    public function testVersionWithoutCollectionsMakesNoGateCall(): void
    {
        $version = $this->storeVersion('no-collections', self::checkConfiguration() + Agent::candidateDefaults());
        self::assertSame([], $version->getConfiguration()['collections']);
        $conversation = $this->agentConversation($version);
        $this->queueAnswer();
        $marks = $this->providerMarks();

        $data = $this->turn($conversation, ['content' => self::QUESTION])->toArray();

        self::assertResponseStatusCodeSame(201);
        self::assertSame([MessageKind::TEXT, self::ANSWER], [$data['kind'], $data['content']]);
        self::assertSame(['input_tokens' => 50, 'output_tokens' => 10, 'cached_input_tokens' => 0, 'reasoning_tokens' => 0], $data['usage']);
        self::assertStringNotContainsString(AnswerabilityCheck::MARKER, (string) json_encode(self::messageContents($this->onlyRequestSince($marks))));
        $records = $this->records($conversation);
        self::assertArrayNotHasKey('gates', $records[array_key_last($records)]->getProviderPayload() ?? []);
    }

    public function testGateCallCarriesThePreviousUserQuestionWithoutRefusedTurns(): void
    {
        $conversation = $this->agentConversation($this->publishedVersion('previous'));

        // Turn 1 passes and is answered; its gate call has no previous question.
        self::queueVote($this->factory(), true, 'Covered.');
        $this->queueAnswer();
        $marks = $this->providerMarks();
        $this->turn($conversation, ['content' => 'Welche Wartung braucht die Spindel?']);
        self::assertResponseStatusCodeSame(201);
        self::assertGateCall($this->requestsSince($marks)[0], 'Welche Wartung braucht die Spindel?', null, 'turn 1');

        // Turn 2 is refused (streamed).
        self::queueVote($this->factory(), false);
        $this->events($this->stream($conversation, ['content' => self::QUESTION]));

        // Turn 3: the question alone is refused, so it is asked again with the previous question, which is
        // turn 1's (the refused turn is skipped).
        self::queueVote($this->factory(), false);
        self::queueVote($this->factory(), true, 'Covered.');
        $this->queueAnswer();
        $marks = $this->providerMarks();
        $this->turn($conversation, ['content' => 'Und wie oft?']);
        self::assertResponseStatusCodeSame(201);

        $requests = $this->requestsSince($marks);
        self::assertCount(3, $requests);
        self::assertGateCall($requests[0], 'Und wie oft?', null, 'turn 3, alone');
        self::assertGateCall($requests[1], 'Und wie oft?', 'Welche Wartung braucht die Spindel?', 'turn 3, with the previous question');
        self::assertSame(['Welche Wartung braucht die Spindel?', self::ANSWER, 'Und wie oft?'], self::messageContents($requests[2]));
    }

    public function testCheckFailuresFailOpenWithANoteInThePayload(): void
    {
        $version = $this->publishedVersion('fail-open');

        foreach (['provider' => 'sync', 'unparsable' => 'stream'] as $case => $route) {
            $conversation = $this->agentConversation($version);
            if ('provider' === $case) {
                $this->factory()->queueFailure('Overloaded');
            } else {
                $this->factory()->getChatProvider()->addResponses(new AssistantMessage('Die Quellen reichen wohl.')->setUsage(new Usage(20, 3)));
            }
            $this->queueAnswer();
            $marks = $this->providerMarks();

            if ('sync' === $route) {
                $response = $this->turn($conversation, ['content' => 'Wann wird gewartet?']);
                self::assertSame(201, $response->getStatusCode(), $response->getContent(false));
            } else {
                $events = $this->events($this->stream($conversation, ['content' => 'Wann wird gewartet?']));
                self::assertSame(StreamEvent::DONE, $events[array_key_last($events)]['event'], $case);
                self::assertNotContains(StreamEvent::GATE, self::typesOf($events), $case);
            }

            $records = $this->records($conversation);
            $final = $records[array_key_last($records)];
            self::assertSame([MessageKind::TEXT, MessageStatus::COMPLETE, self::ANSWER, null], [$final->getKind(), $final->getStatus(), $final->getContent(), $final->getCloseReason()], $case);
            $note = $final->getProviderPayload()['gates']['answerability'] ?? [];
            self::assertSame('pass', $note['verdict'] ?? null, $case);
            $failure = 'provider' === $case ? GateReply::FAILURE_PROVIDER.': Overloaded' : GateReply::FAILURE_UNPARSABLE;
            self::assertStringStartsWith($failure, (string) ($note['failure'] ?? ''), $case);
            self::assertCount(1, $note['votes'] ?? [], $case);
            self::assertSame(['pass', null, $note['failure'] ?? null], [$note['votes'][0]['verdict'] ?? null, $note['votes'][0]['reason'] ?? null, $note['votes'][0]['failure'] ?? null], $case);
            // A failed provider call is not recorded: only the answer call
            // after a provider error, the vote and the answer call else.
            self::assertCount('provider' === $case ? 1 : 2, $this->requestsSince($marks), $case);
            if ('unparsable' === $case) {
                self::assertSame(['input_tokens' => 70, 'output_tokens' => 13, 'cached_input_tokens' => 0, 'reasoning_tokens' => 0, 'gates' => ['answerability' => ['input_tokens' => 20, 'output_tokens' => 3]]], $final->getUsage());
            }
        }
    }

    // --- Helpers -------------------------------------------------------------

    /**
     * $request is a vote of the check on $question: its system prompt and
     * one user message (no history) with the previous question (or none),
     * every chunk and the marked question last.
     */
    private static function assertGateCall(RequestRecord $request, string $question, ?string $previous, string $message): void
    {
        self::assertSame(AnswerabilityCheck::SYSTEM_PROMPT, $request->systemPrompt?->getContent(), $message);
        $contents = self::messageContents($request);
        self::assertCount(1, $contents, $message.': no history');
        [$content] = $contents;
        self::assertStringEndsWith(AnswerabilityCheck::MARKER.' '.$question, $content, $message);
        foreach (self::CHUNKS as $chunk) {
            self::assertStringContainsString($chunk, $content, $message);
        }
        null === $previous
            ? self::assertStringNotContainsString('Previous user question:', $content, $message)
            : self::assertStringStartsWith('Previous user question: '.$previous."\n", $content, $message);
    }

    /**
     * @return list<string>
     */
    private static function sortedChunks(): array
    {
        $chunks = self::CHUNKS;
        sort($chunks);

        return $chunks;
    }

    /**
     * {@see self::ANSWER} with 50 input and 10 output tokens as the next
     * scripted answer.
     */
    private function queueAnswer(): void
    {
        $this->factory()->getChatProvider()->addResponses(new AssistantMessage(self::ANSWER)->setUsage(new Usage(50, 10)));
    }

    /**
     * The configuration of a fixture version: the candidate defaults with a new
     * collection of {@see self::CHUNKS} at retrieval depth 3, plus $keys.
     *
     * @param array<string, mixed> $keys
     *
     * @return array<string, mixed>
     */
    private function versionConfiguration(string $slug, array $keys): array
    {
        $collection = AgentFixtures::collection('answerability-turn-'.$slug);
        self::addChunks($collection, self::CHUNKS);

        return $keys + ['collections' => [$collection->getId()->toRfc4122()], 'retrievalDepth' => 3] + Agent::candidateDefaults();
    }

    /**
     * An enabled agent `Prüfer` with the check on at $votes votes
     * ({@see self::checkConfiguration()} with $overrides) and a collection
     * as its current version 1.
     *
     * @param array<string, mixed> $overrides
     */
    private function publishedVersion(string $slug, array $overrides = [], int $votes = 1): AgentVersion
    {
        return $this->storeVersion($slug, $this->versionConfiguration($slug, self::checkConfiguration($overrides, $votes)));
    }

    /**
     * An enabled agent with $configuration stored as is as its current
     * version 1, written through the entity manager.
     *
     * @param array<string, mixed> $configuration
     */
    private function storeVersion(string $slug, array $configuration): AgentVersion
    {
        $agent = new Agent()->setName('Prüfer')->setSlug(AgentFixtures::PREFIX.'answerability-turn-'.$slug)->setEnabled(true);
        $this->entityManager()->persist($agent);
        $this->entityManager()->flush();
        $version = new AgentVersion($agent, 1, ConversationConnections::withConnection($configuration), 'test-admin');
        $this->entityManager()->persist($version);
        $agent->setCurrentVersion($version);
        $this->entityManager()->flush();

        return $version;
    }

    /**
     * A conversation of `test-admin` pinned to $version (looked up again,
     * it may be detached).
     */
    private function agentConversation(AgentVersion $version, string $language = 'de'): Conversation
    {
        $managed = $this->entityManager()->find(AgentVersion::class, $version->getId());
        self::assertInstanceOf(AgentVersion::class, $managed);

        $conversation = $this->conversation(collections: $managed->getConfiguration()['collections'])
            ->setAgentVersion($managed)
            ->setLanguage($language)
            ->setVariables([]);
        $this->entityManager()->flush();

        return $conversation;
    }
}
