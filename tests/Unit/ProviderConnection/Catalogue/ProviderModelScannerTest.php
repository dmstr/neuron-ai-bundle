<?php

// file generated with AI assistance: Claude Code - 2026-10-06 18:00:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\ProviderConnection\Catalogue;

use Dmstr\ApiConfiguration\Entity\ApiConfiguration;
use Dmstr\NeuronAiBundle\Provider\EndpointFingerprint;
use Dmstr\NeuronAiBundle\Provider\Model\ModelDescription;
use Dmstr\NeuronAiBundle\Provider\Model\ModelDescriptionList;
use Dmstr\NeuronAiBundle\Provider\ModelDescriberInterface;
use Dmstr\NeuronAiBundle\Provider\ProviderModelScanner;
use Dmstr\NeuronAiBundle\Provider\ScanInProgressException;
use Dmstr\NeuronAiBundle\Testing\FakeModelDescriber;
use Dmstr\NeuronAiBundle\Tests\Support\InMemoryProviderCatalogueTrait;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

/**
 * Kernel-free checks of the model catalogue scan and sync against the fake
 * describer and an in-memory lock store: create, update, remove and
 * unchanged; skipped, failing and wrong-typed connections; rows that were
 * not described keep their capabilities; the per-connection lock.
 */
final class ProviderModelScannerTest extends TestCase
{
    use InMemoryProviderCatalogueTrait;

    private FakeModelDescriber $describer;

    private LockFactory $locks;

    protected function setUp(): void
    {
        $this->describer = new FakeModelDescriber();
        $this->locks = new LockFactory(new InMemoryStore());
    }

    public function testScanCreatesUpdatesMarksUnavailableAndCountsUnchanged(): void
    {
        $remote = $this->addOllama('remote');
        $this->addModel($remote, 'llama3.1:8b', ['completion'], 'sha-old', 8192);
        $this->addModel($remote, 'qwen2.5:7b', ['completion', 'tools'], 'sha-qwen', 32768);
        $this->addModel($remote, 'gone:latest', ['completion'], 'sha-gone');
        $this->describer->describe(
            'remote',
            ['name' => 'llama3.1:8b', 'family' => 'llama', 'parameterSize' => '8.0B', 'digest' => 'sha-new', 'capabilities' => ['tools', 'completion'], 'maxContextLength' => 131072, 'supportsSampling' => true],
            ['name' => 'qwen2.5:7b', 'digest' => 'sha-qwen', 'capabilities' => ['completion', 'tools'], 'maxContextLength' => 32768],
            ['name' => 'nomic-embed-text:latest', 'capabilities' => ['embedding'], 'maxContextLength' => 2048],
        );

        $summary = $this->scanner()->scanAll();

        self::assertSame(['created' => 1, 'updated' => 1, 'unavailable' => 1, 'unchanged' => 1, 'scanned' => 1, 'skipped' => 0, 'failed' => 0], array_diff_key($summary, ['connections' => true]));
        $rows = $this->modelsByName($remote);
        self::assertSame(['gone:latest', 'llama3.1:8b', 'nomic-embed-text:latest', 'qwen2.5:7b'], array_keys($rows));
        self::assertFalse($rows['gone:latest']->isAvailable(), 'a model no longer offered keeps its row');
        self::assertSame(ProviderModelScanner::NOT_OFFERED, $rows['gone:latest']->getLastError());
        self::assertTrue($rows['qwen2.5:7b']->isAvailable());

        $llama = $rows['llama3.1:8b'];
        self::assertSame(['completion', 'tools'], $llama->getCapabilities());
        self::assertSame(131072, $llama->getMaxContextLength());
        self::assertSame('sha-new', $llama->getDigest());
        self::assertSame('llama', $llama->getFamily());
        self::assertTrue($llama->getSupportsSampling());
        self::assertNull($llama->getLastError());
        self::assertNotEquals(new \DateTimeImmutable('2026-01-01T00:00:00+00:00'), $llama->getSyncedAt());
        self::assertSame(EndpointFingerprint::of($remote), $llama->getEndpointFingerprint());
        self::assertSame(['embedding'], $rows['nomic-embed-text:latest']->getCapabilities());
        self::assertGreaterThan(0, $this->flushes);
    }

    public function testAModelOfferedAgainGetsItsRowBack(): void
    {
        $remote = $this->addOllama('remote');
        $stored = $this->addModel($remote, 'qwen2.5:7b', ['completion', 'tools'], 'sha-qwen', 32768);
        $id = $stored->getId();

        $this->describer->describe('remote', ['name' => 'llama3.1:8b', 'capabilities' => ['completion']]);
        $first = $this->scanner()->scanAll();
        self::assertSame(1, $first['unavailable']);
        self::assertFalse($stored->isAvailable());

        // A second scan without the model counts nothing again.
        $second = $this->scanner()->scanAll();
        self::assertSame(0, $second['unavailable']);

        $this->describer->describe('remote', ['name' => 'qwen2.5:7b', 'digest' => 'sha-qwen', 'capabilities' => ['completion', 'tools'], 'maxContextLength' => 32768]);
        $third = $this->scanner()->scanAll();

        self::assertSame(0, $third['created']);
        self::assertSame(1, $third['updated'], 'the availability change counts as an update even with an unchanged digest');
        self::assertTrue($stored->isAvailable());
        self::assertNull($stored->getLastError());
        self::assertSame($id, $this->modelsByName($remote)['qwen2.5:7b']->getId());
    }

    public function testReorderedCapabilitiesAndProviderDataAreUnchanged(): void
    {
        $remote = $this->addOllama('remote');
        $this->addModel($remote, 'qwen3:14b', ['completion', 'thinking', 'tools'], 'sha', 40960)
            ->setProviderData(['format' => 'gguf', 'families' => ['qwen3']])
            ->setSupportsSampling(true);
        $this->describer->describe('remote', ['name' => 'qwen3:14b', 'digest' => 'sha', 'capabilities' => ['tools', 'thinking', 'completion'], 'maxContextLength' => 40960, 'supportsSampling' => true, 'providerData' => ['families' => ['qwen3'], 'format' => 'gguf']]);

        $summary = $this->scanner()->scanAll();

        self::assertSame(1, $summary['unchanged']);
        self::assertSame(0, $summary['updated']);
    }

    public function testModelNotDescribedKeepsCapabilitiesSyncTimeAndFingerprint(): void
    {
        $remote = $this->addOllama('remote');
        $stored = $this->addModel($remote, 'qwen2.5:14b', ['completion', 'tools'], 'sha-1', 32768)
            ->setProviderData(['parameter_count' => 14_800_000_000]);
        $syncedAt = $stored->getSyncedAt();
        $this->describer->describe('remote', new ModelDescription('qwen2.5:14b', family: 'qwen2', digest: 'sha-2', supportsSampling: true, providerData: ['size' => 9_000_000_000], described: false, error: 'HTTP 500 from POST /show'));

        $this->scanner()->scanAll();

        self::assertSame(['completion', 'tools'], $stored->getCapabilities());
        self::assertSame(32768, $stored->getMaxContextLength());
        self::assertSame($syncedAt, $stored->getSyncedAt());
        self::assertSame('sha-2', $stored->getDigest());
        self::assertSame('qwen2', $stored->getFamily());
        self::assertSame('HTTP 500 from POST /show', $stored->getLastError());
        self::assertSame(['parameter_count' => 14_800_000_000, 'size' => 9_000_000_000], $stored->getProviderData());
    }

    public function testUnchangedDigestClearsAnObsoleteLastError(): void
    {
        $remote = $this->addOllama('remote');
        $skipped = $this->addModel($remote, 'llama3.1:8b', ['completion', 'tools'], 'sha')->setLastError('Ollama endpoint unreachable');
        $failing = $this->addModel($remote, 'qwen2.5:14b', ['completion', 'tools'], 'sha-q')->setLastError('Ollama endpoint unreachable');
        $this->describer->describe(
            'remote',
            new ModelDescription('llama3.1:8b', digest: 'sha', supportsSampling: true, described: false, unchanged: true),
            new ModelDescription('qwen2.5:14b', digest: 'sha-q', supportsSampling: true, described: false),
        );

        $this->scanner()->scanAll();

        self::assertNull($skipped->getLastError(), 'digest skip: the stored description is current');
        self::assertSame(['completion', 'tools'], $skipped->getCapabilities());
        self::assertSame('Ollama endpoint unreachable', $failing->getLastError(), 'budget or breaker skip: the error stays');
    }

    public function testNewModelThatWasNotDescribedHasUnknownCapabilities(): void
    {
        $remote = $this->addOllama('remote');
        $this->describer->describe('remote', new ModelDescription('mistral:latest', digest: 'sha', supportsSampling: true, described: false));

        $this->scanner()->scanAll();

        $row = $this->modelsByName($remote)['mistral:latest'];
        self::assertNull($row->getCapabilities());
        self::assertNull($row->getSyncedAt());
        self::assertNull($row->getLastError());
        self::assertTrue($row->getSupportsSampling());
    }

    public function testKnownDigestsAreHandedToTheDescriberAndStaleRowsCountAsUnknown(): void
    {
        $remote = $this->addOllama('remote');
        $this->addModel($remote, 'llama3.1:8b', ['completion', 'tools'], 'sha-llama')->setProviderData(['parameters' => []]);
        $this->addModel($remote, 'nuextract:latest', null, 'sha-nu')->setProviderData(['parameters' => ['num_ctx' => 4096]]);
        $this->addModel($remote, 'qwen3:14b', ['completion'], 'sha-qwen');
        $stale = $this->addModel($remote, 'mistral:latest', ['completion', 'tools'], 'sha-mistral')->setEndpointFingerprint(str_repeat('0', 64));

        $received = null;
        $describer = $this->createMock(ModelDescriberInterface::class);
        $describer->expects(self::once())->method('describeModels')->willReturnCallback(static function (ApiConfiguration $connection, array $known) use (&$received): ModelDescriptionList {
            $received = $known;

            return new ModelDescriptionList([new ModelDescription('llama3.1:8b', digest: 'sha-llama', described: false)]);
        });

        $this->scanner($describer)->scanAll();

        self::assertSame([
            'llama3.1:8b' => ['digest' => 'sha-llama', 'capabilitiesKnown' => true, 'parametersKnown' => true],
            'mistral:latest' => ['digest' => 'sha-mistral', 'capabilitiesKnown' => false, 'parametersKnown' => false],
            'nuextract:latest' => ['digest' => 'sha-nu', 'capabilitiesKnown' => false, 'parametersKnown' => true],
            // Described before the Modelfile parameters were kept.
            'qwen3:14b' => ['digest' => 'sha-qwen', 'capabilitiesKnown' => true, 'parametersKnown' => false],
        ], $received);
        self::assertTrue($stale->isStale());
    }

    public function testDescribedRowGetsTheCurrentFingerprint(): void
    {
        $remote = $this->addOllama('remote');
        $stored = $this->addModel($remote, 'llama3.1:8b', ['completion'], 'sha')->setEndpointFingerprint(str_repeat('0', 64));
        self::assertTrue($stored->isStale());
        $this->describer->describe('remote', ['name' => 'llama3.1:8b', 'digest' => 'sha', 'capabilities' => ['completion', 'tools']]);

        $this->scanner()->scanAll();

        self::assertFalse($stored->isStale());
        self::assertSame(['completion', 'tools'], $stored->getCapabilities());
    }

    public function testInactiveConnectionIsSkippedWithoutDescribe(): void
    {
        $this->addOllama('old', active: false);

        $summary = $this->scanner()->scanAll();

        self::assertSame(1, $summary['skipped']);
        self::assertSame('skipped', $summary['connections'][0]['status']);
        self::assertSame([], $this->describer->getDescribedConnections());
    }

    public function testScanAllCoversBothProviderTypesButNotMcp(): void
    {
        $this->addOllama('remote');
        $this->addAnthropic('claude');
        $this->addMcp('docs');
        $this->describer->describe('claude', ['name' => 'claude-opus-5-5', 'capabilities' => ['completion', 'tools'], 'maxOutputTokens' => 128000, 'supportsSampling' => false]);

        $summary = $this->scanner()->scanAll();

        self::assertSame(['claude', 'remote'], array_column($summary['connections'], 'name'));
        self::assertSame(['claude', 'remote'], $this->describer->getDescribedConnections());
        $claude = $this->modelsByName($this->connections[1])['claude-opus-5-5'];
        self::assertFalse($claude->getSupportsSampling());
        self::assertSame(128000, $claude->getMaxOutputTokens());
    }

    public function testWrongConnectionTypeIsAnError(): void
    {
        $docs = $this->addMcp('docs');

        $summary = $this->scanner()->scan([$docs]);

        self::assertSame(1, $summary['failed']);
        self::assertSame('Connection "docs" is not a provider connection.', $summary['connections'][0]['error']);
        self::assertSame([], $this->describer->getDescribedConnections());
    }

    public function testConnectionFailureKeepsRowsAndSetsLastError(): void
    {
        $remote = $this->addOllama('remote');
        $other = $this->addOllama('other', 'http://other.fake.test:11434/api');
        $stored = $this->addModel($remote, 'llama3.1:8b', ['completion', 'tools'], 'sha');
        $this->describer->unreachable('remote');
        $this->describer->describe('other', ['name' => 'qwen3:14b', 'capabilities' => ['completion']]);

        $summary = $this->scanner()->scanAll();

        self::assertSame(1, $summary['failed']);
        self::assertSame(1, $summary['scanned']);
        self::assertSame(['llama3.1:8b'], array_keys($this->modelsByName($remote)));
        self::assertSame(['completion', 'tools'], $stored->getCapabilities());
        self::assertStringContainsString('unreachable', (string) $stored->getLastError());
        self::assertSame(['qwen3:14b'], array_keys($this->modelsByName($other)));
    }

    public function testOverlongNameIsAWarning(): void
    {
        $remote = $this->addOllama('remote');
        $this->describer->describe('remote', ['name' => str_repeat('x', 191)], ['name' => 'ok:latest']);

        $summary = $this->scanner()->scanAll();

        self::assertSame(['ok:latest'], array_keys($this->modelsByName($remote)));
        self::assertStringContainsString('exceeds 190 characters', $summary['connections'][0]['warnings'][0]);
    }

    public function testDescriberWarningsReachTheSummary(): void
    {
        $this->addOllama('remote');
        $describer = $this->createStub(ModelDescriberInterface::class);
        $describer->method('describeModels')->willReturn(new ModelDescriptionList([], ['/show not permitted by the endpoint (HTTP 403); capabilities not refreshed']));

        $summary = $this->scanner($describer)->scanAll();

        self::assertSame(['/show not permitted by the endpoint (HTTP 403); capabilities not refreshed'], $summary['connections'][0]['warnings']);
        self::assertSame('ok', $summary['connections'][0]['status']);
    }

    public function testHeldLockSkipsTheConnectionAndWritesNothing(): void
    {
        $remote = $this->addOllama('remote');
        $stored = $this->addModel($remote, 'gone:latest', ['completion']);
        $this->describer->describe('remote', ['name' => 'llama3.1:8b']);
        $held = $this->locks->createLock(ProviderModelScanner::LOCK_PREFIX.$remote->getId());
        self::assertTrue($held->acquire());

        $summary = $this->scanner()->scanAll();

        self::assertSame(1, $summary['skipped']);
        self::assertSame(['another scan of this connection is running'], $summary['connections'][0]['warnings']);
        self::assertSame([], $this->describer->getDescribedConnections());
        self::assertSame(['gone:latest'], array_keys($this->modelsByName($remote)));
        self::assertNull($stored->getLastError());
    }

    public function testLockIsReleasedAfterTheScan(): void
    {
        $remote = $this->addOllama('remote');

        $this->scanner()->scanAll();

        self::assertTrue($this->locks->createLock(ProviderModelScanner::LOCK_PREFIX.$remote->getId())->acquire());
    }

    public function testSyncRefreshesTheRowWithoutCreatingOthers(): void
    {
        $remote = $this->addOllama('remote');
        $stored = $this->addModel($remote, 'llama3.1:8b', ['completion'], 'sha')->setLastError('old');
        $this->describer->describe('remote', ['name' => 'llama3.1:8b', 'digest' => 'sha', 'capabilities' => ['completion', 'tools'], 'maxContextLength' => 131072], ['name' => 'other:latest']);

        self::assertTrue($this->scanner()->syncOne($stored));

        self::assertSame(['completion', 'tools'], $stored->getCapabilities());
        self::assertSame(131072, $stored->getMaxContextLength());
        self::assertNull($stored->getLastError());
        self::assertSame(['llama3.1:8b'], array_keys($this->modelsByName($remote)));
    }

    public function testSyncOfAFailedDescribeKeepsValuesAndSetsLastError(): void
    {
        $remote = $this->addOllama('remote');
        $stored = $this->addModel($remote, 'llama3.1:8b', ['completion', 'tools'], 'sha');
        $syncedAt = $stored->getSyncedAt();
        $this->describer->describe('remote', ['name' => 'llama3.1:8b', 'capabilities' => ['completion']])->failModel('remote', 'llama3.1:8b');

        self::assertFalse($this->scanner()->syncOne($stored));

        self::assertSame(['completion', 'tools'], $stored->getCapabilities());
        self::assertSame($syncedAt, $stored->getSyncedAt());
        self::assertStringContainsString('could not describe model "llama3.1:8b"', (string) $stored->getLastError());
    }

    public function testSyncOfAModelNoLongerOfferedMarksItUnavailable(): void
    {
        $remote = $this->addOllama('remote');
        $stored = $this->addModel($remote, 'gone:latest', ['completion']);

        self::assertFalse($this->scanner()->syncOne($stored));

        self::assertSame(ProviderModelScanner::NOT_OFFERED, $stored->getLastError());
        self::assertFalse($stored->isAvailable());
        self::assertSame(['gone:latest'], array_keys($this->modelsByName($remote)));
    }

    public function testSyncOfAFailingConnectionSetsTheMessage(): void
    {
        $remote = $this->addOllama('remote');
        $stored = $this->addModel($remote, 'llama3.1:8b', ['completion']);
        $this->describer->unauthenticated('remote');

        self::assertFalse($this->scanner()->syncOne($stored));

        self::assertStringContainsString('rejected the credentials', (string) $stored->getLastError());
    }

    public function testSyncOfAnInactiveConnectionSetsLastErrorWithoutDescribe(): void
    {
        $old = $this->addOllama('old', active: false);
        $stored = $this->addModel($old, 'llama3.1:8b', ['completion']);

        self::assertFalse($this->scanner()->syncOne($stored));

        self::assertSame('Provider connection "old" is inactive.', $stored->getLastError());
        self::assertSame([], $this->describer->getDescribedConnections());
    }

    public function testSyncWhileTheLockIsHeldThrowsAndWritesNothing(): void
    {
        $remote = $this->addOllama('remote');
        $stored = $this->addModel($remote, 'llama3.1:8b', ['completion']);
        $held = $this->locks->createLock(ProviderModelScanner::LOCK_PREFIX.$remote->getId());
        self::assertTrue($held->acquire());

        try {
            $this->scanner()->syncOne($stored);
            self::fail('Expected a ScanInProgressException.');
        } catch (ScanInProgressException $e) {
            self::assertStringContainsString('Another scan of provider connection "remote" is running', $e->getMessage());
        }

        self::assertNull($stored->getLastError());
        self::assertSame([], $this->describer->getDescribedConnections());
    }

    private function scanner(?ModelDescriberInterface $describer = null): ProviderModelScanner
    {
        return new ProviderModelScanner($this->entityManager(), $describer ?? $this->describer, $this->locks, 200);
    }
}

// - revised 2026-10-07
// - revised 2026-10-08
