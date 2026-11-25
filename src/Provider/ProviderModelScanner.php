<?php

// file generated with AI assistance: Claude Code - 2026-10-06 18:00:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Provider;

use Dmstr\ApiConfiguration\Entity\ApiConfiguration;
use Dmstr\NeuronAiBundle\Entity\Refs\ProviderModel;
use Dmstr\NeuronAiBundle\Provider\Model\ModelDescription;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;

/**
 * Mirrors the models of provider connections (`ollama`, `anthropic`) into
 * the catalogue ({@see ProviderModel}, table `neuron_provider_model`).
 *
 * Scan (per connection): the models come from
 * {@see ModelDescriberInterface::describeModels()} with the stored digests,
 * so an Ollama model whose digest is unchanged is not described again.
 * Models missing in the catalogue are created, changed rows updated, rows
 * the connection no longer offers marked `available = false` with
 * `lastError` {@see NOT_OFFERED}; rows are never deleted, so a reference to
 * a catalogue entry stays valid. A model that is offered again gets its
 * row back (same id) with `available = true`. A described row gets
 * `syncedAt = now`, `lastError = null` and the connection's current
 * endpoint fingerprint; a row that was not described this time
 * (`described = false`: digest unchanged, describe budget reached, `/show`
 * failed or blocked) keeps capabilities, context length, output limit,
 * sampling support, `syncedAt` and fingerprint, gets the listing fields
 * refreshed and `lastError` when the description carries an error.
 * Inactive connections are skipped without I/O. When the connection
 * cannot be described, its rows are kept and get `lastError`; the failure
 * is reported in the summary and does not stop the other connections.
 *
 * Concurrency: scan and sync of one connection hold the non-blocking lock
 * `neuron.provider_model.<connection uuid>` for the whole describe and
 * write, and load the stored rows only after acquiring it. A held lock
 * reports the connection as `skipped` (scan) or throws
 * {@see ScanInProgressException} (sync). Two overlapping scans would
 * otherwise both insert the same new model and violate the unique key.
 * The TTL covers the worst case of `max_describe_calls` describes at the
 * HTTP timeout each.
 *
 * Sync (one row) forces a describe of that model: described =
 * refreshed and available; not described (a `/show` failure) = stored
 * values kept and `lastError` set; not offered = `available = false` and
 * `lastError` set; a connection failure = `lastError` set. Sync never
 * creates rows.
 *
 * Scan summary (JSON-serialisable, also stored as the job result):
 *
 *     [
 *         'created' => int, 'updated' => int, 'unavailable' => int, 'unchanged' => int,
 *         'scanned' => int, 'skipped' => int, 'failed' => int,
 *         'connections' => list<ConnectionSummary>,
 *     ]
 *
 * ConnectionSummary: `id`, `name`, `status` (`ok`, `skipped` or `error`),
 * `created`, `updated`, `unavailable` (rows newly marked as no longer
 * offered), `unchanged`, `error` (message or null)
 * and `warnings` (list of messages: duplicate or overlong names, describe
 * budget, `/show` blocked, scan already running).
 */
final class ProviderModelScanner
{
    public const string STATUS_OK = 'ok';
    public const string STATUS_SKIPPED = 'skipped';
    public const string STATUS_ERROR = 'error';

    public const string LOCK_PREFIX = 'neuron.provider_model.';

    public const string NOT_OFFERED = 'Model is no longer offered by the connection';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ModelDescriberInterface $describer,
        private readonly LockFactory $lockFactory,
        private readonly int $maxDescribeCalls = OllamaModelDescriber::DEFAULT_MAX_DESCRIBE_CALLS,
    ) {
    }

    /**
     * Scans every provider connection; inactive ones appear as `skipped`.
     *
     * @return array<string, mixed> scan summary, see the class docblock
     */
    public function scanAll(): array
    {
        $connections = $this->entityManager->getRepository(ApiConfiguration::class)
            ->findBy(['type' => ProviderModel::CONNECTION_TYPES], ['name' => 'ASC']);

        return $this->scan($connections);
    }

    /**
     * Scans the given connections (e.g. one resolved by ProviderConnectionResolver).
     *
     * @param iterable<ApiConfiguration> $connections
     *
     * @return array<string, mixed> scan summary, see the class docblock
     */
    public function scan(iterable $connections): array
    {
        $summary = [
            'created' => 0,
            'updated' => 0,
            'unavailable' => 0,
            'unchanged' => 0,
            'scanned' => 0,
            'skipped' => 0,
            'failed' => 0,
            'connections' => [],
        ];

        foreach ($connections as $connection) {
            $result = $this->scanConnection($connection);
            $summary['connections'][] = $result;

            match ($result['status']) {
                self::STATUS_SKIPPED => ++$summary['skipped'],
                self::STATUS_ERROR => ++$summary['failed'],
                default => ++$summary['scanned'],
            };
            foreach (['created', 'updated', 'unavailable', 'unchanged'] as $counter) {
                $summary[$counter] += $result[$counter];
            }
        }

        return $summary;
    }

    /**
     * Scans one connection and flushes its changes.
     *
     * @return array<string, mixed> connection summary, see the class docblock
     */
    public function scanConnection(ApiConfiguration $connection): array
    {
        $result = [
            'id' => (string) $connection->getId(),
            'name' => ProviderType::nameOf($connection),
            'status' => self::STATUS_OK,
            'created' => 0,
            'updated' => 0,
            'unavailable' => 0,
            'unchanged' => 0,
            'error' => null,
            'warnings' => [],
        ];

        if (!ProviderType::isProvider($connection)) {
            $result['status'] = self::STATUS_ERROR;
            $result['error'] = \sprintf('Connection "%s" is not a provider connection.', $result['name']);

            return $result;
        }

        if (!$connection->isActive()) {
            $result['status'] = self::STATUS_SKIPPED;

            return $result;
        }

        $lock = $this->lock($connection);
        if (!$lock->acquire()) {
            $result['status'] = self::STATUS_SKIPPED;
            $result['warnings'][] = 'another scan of this connection is running';

            return $result;
        }

        try {
            return $this->scanLocked($connection, $result);
        } finally {
            $lock->release();
        }
    }

    /**
     * Describes one row afresh and flushes it.
     *
     * @return bool true when the model was described and the row refreshed;
     *              false when `lastError` was set instead
     *
     * @throws ScanInProgressException when a scan or sync of the connection holds the lock (nothing written)
     */
    public function syncOne(ProviderModel $model): bool
    {
        $connection = $model->getApiConfiguration();

        if (!$connection->isActive()) {
            return $this->fail($model, \sprintf('Provider connection "%s" is inactive.', ProviderType::nameOf($connection)));
        }

        $lock = $this->lock($connection);
        if (!$lock->acquire()) {
            throw new ScanInProgressException(\sprintf('Another scan of provider connection "%s" is running; try again later.', ProviderType::nameOf($connection)));
        }

        try {
            try {
                $description = $this->describer->describeModel($connection, $model->getName());
            } catch (\Exception $e) {
                return $this->fail($model, self::errorMessage($e));
            }

            if (null === $description) {
                $model->setAvailable(false);

                return $this->fail($model, self::NOT_OFFERED);
            }

            $this->apply($model, $description, new \DateTimeImmutable(), EndpointFingerprint::of($connection));
            $this->entityManager->flush();

            return $description->described;
        } finally {
            $lock->release();
        }
    }

    /**
     * @param array<string, mixed> $result
     *
     * @return array<string, mixed>
     */
    private function scanLocked(ApiConfiguration $connection, array $result): array
    {
        $fingerprint = EndpointFingerprint::of($connection);

        $existing = [];
        $known = [];
        foreach ($this->modelsOf($connection) as $model) {
            $existing[$model->getName()] = $model;
            $known[$model->getName()] = [
                'digest' => $model->getDigest(),
                // A row described from another endpoint is described again.
                'capabilitiesKnown' => null !== $model->getCapabilities() && $model->getEndpointFingerprint() === $fingerprint,
                // A row from before the Modelfile parameters were kept, or
                // described from /tags alone, is described again.
                'parametersKnown' => ProviderType::OLLAMA !== ProviderType::of($connection) || null !== $model->modelParameters(),
            ];
        }

        try {
            $list = $this->describer->describeModels($connection, $known);
        } catch (\Exception $e) {
            $message = self::errorMessage($e);
            foreach ($existing as $model) {
                $model->setLastError($message);
            }
            $this->entityManager->flush();

            $result['status'] = self::STATUS_ERROR;
            $result['error'] = $message;

            return $result;
        }

        $now = new \DateTimeImmutable();
        $seen = [];
        foreach ($list->models as $description) {
            $name = $description->name;
            if ('' === $name) {
                continue;
            }
            if (isset($seen[$name])) {
                $result['warnings'][] = \sprintf('Model "%s" is listed more than once; the first description is used.', $name);
                continue;
            }
            if (mb_strlen($name) > ProviderModel::MAX_NAME_LENGTH) {
                $result['warnings'][] = \sprintf('Model name "%s…" exceeds %d characters and is ignored.', mb_substr($name, 0, 40), ProviderModel::MAX_NAME_LENGTH);
                continue;
            }
            $seen[$name] = true;

            $model = $existing[$name] ?? null;
            if (null === $model) {
                $model = new ProviderModel($connection, $name);
                $this->apply($model, $description, $now, $fingerprint);
                $this->entityManager->persist($model);
                ++$result['created'];
            } elseif ($this->apply($model, $description, $now, $fingerprint)) {
                ++$result['updated'];
            } else {
                ++$result['unchanged'];
            }
        }

        foreach ($existing as $name => $model) {
            if (!isset($seen[$name]) && $model->isAvailable()) {
                $model->setAvailable(false)->setLastError(self::NOT_OFFERED);
                ++$result['unavailable'];
            }
        }

        foreach ($list->warnings as $warning) {
            $result['warnings'][] = $warning;
        }

        $this->entityManager->flush();

        return $result;
    }

    /**
     * Copies a description onto a row (rules in the class docblock).
     *
     * @return bool whether a described field or the availability changed
     *              (not counting `syncedAt` and `lastError`)
     */
    private function apply(ProviderModel $model, ModelDescription $description, \DateTimeImmutable $now, string $fingerprint): bool
    {
        $before = self::snapshot($model);

        // Offered again: the row comes back under its old id.
        $model->setAvailable(true);
        if (self::NOT_OFFERED === $model->getLastError()) {
            $model->setLastError(null);
        }

        $model->setDisplayName($description->displayName)
            ->setFamily($description->family)
            ->setParameterSize($description->parameterSize)
            ->setQuantization($description->quantization)
            ->setDigest($description->digest);

        if ($description->described) {
            $model->setCapabilities($description->capabilities)
                ->setMaxContextLength($description->maxContextLength)
                ->setMaxOutputTokens($description->maxOutputTokens)
                ->setSupportsSampling($description->supportsSampling)
                ->setProviderData($description->providerData)
                ->setEndpointFingerprint($fingerprint)
                ->setSyncedAt($now)
                ->setLastError(null);
        } else {
            // Listing extras are fresh, described extras (parameter count,
            // embedding length) stay until the next describe.
            $model->setProviderData(array_merge($model->getProviderData() ?? [], $description->providerData));
            if (null === $model->getSupportsSampling()) {
                $model->setSupportsSampling($description->supportsSampling);
            }
            if (null !== $description->error) {
                $model->setLastError($description->error);
            } elseif ($description->unchanged) {
                // The digest is unchanged and the listing worked: the stored
                // description is current, an error of an earlier scan (e.g.
                // during an outage) is obsolete.
                $model->setLastError(null);
            }
        }

        return self::canonical($before) !== self::canonical(self::snapshot($model));
    }

    /**
     * @return array<string, mixed>
     */
    private static function snapshot(ProviderModel $model): array
    {
        return [
            'displayName' => $model->getDisplayName(),
            'family' => $model->getFamily(),
            'parameterSize' => $model->getParameterSize(),
            'quantization' => $model->getQuantization(),
            'digest' => $model->getDigest(),
            'capabilities' => $model->getCapabilities(),
            'maxContextLength' => $model->getMaxContextLength(),
            'maxOutputTokens' => $model->getMaxOutputTokens(),
            'supportsSampling' => $model->getSupportsSampling(),
            'providerData' => $model->getProviderData(),
            'endpointFingerprint' => $model->getEndpointFingerprint(),
            'available' => $model->isAvailable(),
        ];
    }

    /**
     * Key-order independent representation of a JSON value, so a provider
     * that reorders object keys does not count as a change.
     */
    private static function canonical(mixed $value): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value, \SORT_STRING);
        }

        return array_map(self::canonical(...), $value);
    }

    /**
     * @return list<ProviderModel>
     */
    private function modelsOf(ApiConfiguration $connection): array
    {
        return array_values($this->entityManager->getRepository(ProviderModel::class)
            ->findBy(['apiConfiguration' => $connection], ['name' => 'ASC']));
    }

    private function lock(ApiConfiguration $connection): LockInterface
    {
        $ttl = $this->maxDescribeCalls * AbstractProviderHttpClient::DEFAULT_TIMEOUT_SECONDS + 60.0;

        return $this->lockFactory->createLock(self::LOCK_PREFIX.$connection->getId(), $ttl, false);
    }

    private function fail(ProviderModel $model, string $message): bool
    {
        $model->setLastError($message);
        $this->entityManager->flush();

        return false;
    }

    private static function errorMessage(\Exception $e): string
    {
        $message = trim($e->getMessage());

        return '' !== $message ? $message : $e::class;
    }
}

// - revised 2026-10-07
// - revised 2026-10-08
