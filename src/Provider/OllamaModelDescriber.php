<?php

// file generated with AI assistance: Claude Code - 2026-10-06 17:20:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Provider;

use Dmstr\ApiConfiguration\Entity\ApiConfiguration;
use Dmstr\ApiConfiguration\Security\ConfigSecrets;
use Dmstr\NeuronAiBundle\Provider\Auth\OllamaHttpClientFactory;
use Dmstr\NeuronAiBundle\Provider\Model\ModelCapability;
use Dmstr\NeuronAiBundle\Provider\Model\ModelDescription;
use Dmstr\NeuronAiBundle\Provider\Model\ModelDescriptionList;
use NeuronAI\HttpClient\HttpClientInterface;

/**
 * Describes the models of an `ollama` connection: `GET {url}/tags` for the
 * list (name, digest, `details`), then `POST {url}/show` with
 * `{"model": "<name>"}` per model for `capabilities`, the trained
 * context length (`model_info["<arch>.context_length"]`), the Modelfile
 * `parameters` (parsed into `providerData.parameters`, e.g. the runtime
 * default `num_ctx`) and the scalar `model_info` keys
 * (`providerData.model_info`), plus `system` and the scalar
 * `projector_info` keys when present. `verbose` is never sent, and
 * `template`, `license`, `modelfile` and `tensors` are not kept. One authenticated client serves every request of a call
 * (see {@see OllamaAuthenticatedClientTrait}); a genuine token expiry in
 * the middle is retried once by the authenticating client.
 *
 * Incremental: a model whose digest equals the known one and whose
 * capabilities and parameters are known is returned with
 * `described = false` and `unchanged = true`. A `/tags` entry that
 * already carries `capabilities` and a context length (newer servers) is
 * no reason to skip `/show`, because `/tags` has no parameters; it is the
 * fallback description when `/show` is not called for the model (budget,
 * circuit breaker) or fails. The remaining models are described in this order:
 * new ones, changed ones (other digest), then never-described ones, at most
 * `$maxDescribeCalls` per call; the rest is returned with
 * `described = false` and one warning.
 *
 * Circuit breaker: the `/show` loop stops for the connection on the first
 * `/show` failure that is UNAUTHENTICATED (after the client's retry), a
 * 404 or 405 for a model `/tags` just listed (a gateway blocking the
 * route), or UNREACHABLE without an HTTP status (network down, timeout).
 * That model and the remaining ones come back with `described = false`
 * and one warning; `/tags` worked, so the call itself succeeds. Other
 * `/show` failures (5xx, an unusable answer) mark only that model with an
 * `error`.
 */
final class OllamaModelDescriber extends AbstractProviderHttpClient implements ModelDescriberInterface
{
    use OllamaAuthenticatedClientTrait;

    public const int DEFAULT_MAX_DESCRIBE_CALLS = 200;

    private const int PRIORITY_NEW = 0;
    private const int PRIORITY_CHANGED = 1;
    private const int PRIORITY_UNDESCRIBED = 2;

    /**
     * @param OllamaHttpClientFactory|null $authClients      authenticates connections with an `auth_type` other than `none`; null = such connections are a CONFIGURATION failure
     * @param int                          $maxDescribeCalls `/show` calls per connection and describeModels() call
     */
    public function __construct(
        ConfigSecrets $secrets,
        ?HttpClientInterface $httpClient = null,
        float $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS,
        private readonly ?OllamaHttpClientFactory $authClients = null,
        private readonly int $maxDescribeCalls = self::DEFAULT_MAX_DESCRIBE_CALLS,
    ) {
        parent::__construct($secrets, $httpClient, $timeoutSeconds);
        if ($maxDescribeCalls < 1) {
            throw new \InvalidArgumentException('maxDescribeCalls must be at least 1.');
        }
    }

    public function describeModels(ApiConfiguration $connection, array $known = []): ModelDescriptionList
    {
        $context = $this->ollamaContext($connection);
        $warnings = [];

        /** @var array<string, array<mixed>> $entries */
        $entries = [];
        foreach ($this->tagEntries($connection, $context) as $entry) {
            $name = self::tagName($entry);
            if (null === $name) {
                continue;
            }
            if (isset($entries[$name])) {
                $warnings[] = \sprintf('Model "%s" is listed more than once; the first entry is used.', $name);
                continue;
            }
            \assert(\is_array($entry));
            $entries[$name] = $entry;
        }

        /** @var array<string, ModelDescription> $descriptions */
        $descriptions = [];
        /** @var array<int, list<string>> $queues */
        $queues = [self::PRIORITY_NEW => [], self::PRIORITY_CHANGED => [], self::PRIORITY_UNDESCRIBED => []];
        foreach ($entries as $name => $entry) {
            $digest = self::stringOrNull($entry['digest'] ?? null);
            $stored = $known[$name] ?? null;
            if (null !== $stored && null !== $digest && $digest === $stored['digest'] && $stored['capabilitiesKnown'] && ($stored['parametersKnown'] ?? true)) {
                $descriptions[$name] = self::listed($name, $entry, unchanged: true);
                continue;
            }

            $queues[match (true) {
                null === $stored => self::PRIORITY_NEW,
                $digest !== $stored['digest'] => self::PRIORITY_CHANGED,
                default => self::PRIORITY_UNDESCRIBED,
            }][] = $name;
        }

        $pending = array_merge(...$queues);
        $calls = 0;
        $overBudget = 0;
        $tripped = false;
        foreach ($pending as $name) {
            $entry = $entries[$name];
            if ($tripped) {
                $descriptions[$name] = self::fromTags($name, $entry) ?? self::listed($name, $entry);
                continue;
            }
            if ($calls >= $this->maxDescribeCalls) {
                $descriptions[$name] = self::fromTags($name, $entry) ?? self::listed($name, $entry);
                ++$overBudget;
                continue;
            }

            ++$calls;
            try {
                $descriptions[$name] = self::fromShow($name, $entry, $this->show($connection, $context, $name));
            } catch (ModelListingException $e) {
                $breaker = self::breakerWarning($e);
                if (null !== $breaker) {
                    $tripped = true;
                    $warnings[] = $breaker;
                    $descriptions[$name] = self::fromTags($name, $entry) ?? self::listed($name, $entry);
                    continue;
                }
                $descriptions[$name] = self::fromTags($name, $entry) ?? self::listed($name, $entry, $e->getMessage());
            }
        }

        if ($overBudget > 0) {
            $warnings[] = \sprintf('%d models not described: describe budget of %d reached', $overBudget, $this->maxDescribeCalls);
        }

        // In the order of /tags.
        $models = [];
        foreach (array_keys($entries) as $name) {
            $models[] = $descriptions[$name];
        }

        return new ModelDescriptionList($models, $warnings);
    }

    public function describeModel(ApiConfiguration $connection, string $name): ?ModelDescription
    {
        $context = $this->ollamaContext($connection);

        $match = null;
        foreach ($this->tagEntries($connection, $context) as $entry) {
            $listed = self::tagName($entry);
            if (null === $listed || !ModelName::equivalent($listed, $name)) {
                continue;
            }
            \assert(\is_array($entry));
            $match = [$listed, $entry];
            if ($listed === $name) {
                break;
            }
        }
        if (null === $match) {
            return null;
        }

        [$listed, $entry] = $match;
        try {
            return self::fromShow($listed, $entry, $this->show($connection, $context, $listed));
        } catch (ModelListingException $e) {
            return self::listed($listed, $entry, $e->getMessage());
        }
    }

    /**
     * @param array{url: string, secrets: list<string>, client: HttpClientInterface} $context
     *
     * @return array<mixed>
     *
     * @throws ModelListingException
     */
    private function show(ApiConfiguration $connection, #[\SensitiveParameter] array $context, string $name): array
    {
        return $this->requestJson($connection, 'POST', $context['url'].'/show', [], ['model' => $name], $context['secrets'], $context['client']);
    }

    /**
     * The warning of a `/show` failure that stops the loop, null for a
     * per-model failure.
     */
    private static function breakerWarning(ModelListingException $e): ?string
    {
        return match (true) {
            ModelListingException::UNAUTHENTICATED === $e->reason => \sprintf('/show not permitted by the endpoint (HTTP %s); capabilities not refreshed', $e->statusCode ?? 'n/a'),
            ModelListingException::PROTOCOL === $e->reason && \in_array($e->statusCode, [404, 405], true) => \sprintf('/show not permitted by the endpoint (HTTP %d); capabilities not refreshed', $e->statusCode),
            ModelListingException::UNREACHABLE === $e->reason && null === $e->statusCode => '/show aborted: endpoint unreachable',
            default => null,
        };
    }

    /**
     * A description from `/tags` alone, without parameters, when the entry
     * carries `capabilities` and a context length (newer servers); else
     * null. Only the fallback for a model `/show` did not describe.
     *
     * @param array<mixed> $entry
     */
    private static function fromTags(string $name, array $entry): ?ModelDescription
    {
        $details = \is_array($entry['details'] ?? null) ? $entry['details'] : [];
        $capabilities = ModelCapability::normalize($entry['capabilities'] ?? null);
        $contextLength = self::positiveInt($details['context_length'] ?? null);
        if (null === $capabilities || null === $contextLength) {
            return null;
        }

        return self::description($name, $entry, $capabilities, $contextLength, []);
    }

    /**
     * @param array<mixed> $entry the `/tags` entry
     * @param array<mixed> $show  the `/show` answer
     */
    private static function fromShow(string $name, array $entry, array $show): ModelDescription
    {
        $info = \is_array($show['model_info'] ?? null) ? $show['model_info'] : [];
        $arch = self::stringOrNull($info['general.architecture'] ?? null);
        $details = \is_array($show['details'] ?? null) ? $show['details'] : [];
        $tagDetails = \is_array($entry['details'] ?? null) ? $entry['details'] : [];

        $contextLength = (null !== $arch ? self::positiveInt($info[$arch.'.context_length'] ?? null) : null)
            ?? self::positiveInt($details['context_length'] ?? null)
            ?? self::positiveInt($tagDetails['context_length'] ?? null);

        // /tags carries the listing fields; /show fills them when /tags has none.
        $entry['details'] = $tagDetails + $details;

        $extra = array_filter([
            'parameter_count' => self::positiveInt($info['general.parameter_count'] ?? null),
            'embedding_length' => null !== $arch ? self::positiveInt($info[$arch.'.embedding_length'] ?? null) : null,
            'remote_model' => self::stringOrNull($show['remote_model'] ?? null),
            'remote_host' => self::stringOrNull($show['remote_host'] ?? null),
            // The Modelfile SYSTEM prompt, which precedes the agent instructions.
            'system' => self::stringOrNull($show['system'] ?? null),
            'projector_info' => \is_array($show['projector_info'] ?? null) ? array_filter($show['projector_info'], static fn (mixed $value): bool => \is_scalar($value)) ?: null : null,
        ], static fn (mixed $value): bool => null !== $value) + [
            // Always set after a /show, [] for a Modelfile without
            // parameters: the catalogue tells "none" from "not described".
            'parameters' => self::parseParameters($show['parameters'] ?? null),
            'model_info' => array_filter($info, static fn (mixed $value): bool => \is_scalar($value)),
        ];

        return self::description($name, $entry, ModelCapability::normalize($show['capabilities'] ?? null), $contextLength, $extra);
    }

    /**
     * The Modelfile parameters of `/show` (`"num_ctx 8192\nstop \"<|im_end|>\"…"`,
     * one `key value` per line) as a map: numbers as int or float, `true`
     * and `false` as bool, quoted values unquoted; `stop` and every key
     * that occurs more than once become a list.
     *
     * @return array<string, mixed>
     */
    public static function parseParameters(mixed $parameters): array
    {
        if (!\is_string($parameters)) {
            return [];
        }

        $values = [];
        foreach (preg_split('/\R/', $parameters) ?: [] as $line) {
            if (1 !== preg_match('/^\s*(\S+)\s+(.*?)\s*$/', $line, $match)) {
                continue;
            }
            [, $key, $raw] = $match;
            $value = self::parameterValue($raw);
            if ('stop' === $key || \array_key_exists($key, $values)) {
                $values[$key] = [...(array) ($values[$key] ?? []), $value];
            } else {
                $values[$key] = $value;
            }
        }

        return $values;
    }

    private static function parameterValue(string $raw): int|float|bool|string
    {
        if (\strlen($raw) >= 2 && '"' === $raw[0] && '"' === $raw[-1]) {
            $decoded = json_decode($raw);

            return \is_string($decoded) ? $decoded : substr($raw, 1, -1);
        }

        return match (true) {
            1 === preg_match('/^-?\d+$/', $raw) => (int) $raw,
            is_numeric($raw) => (float) $raw,
            'true' === $raw => true,
            'false' === $raw => false,
            default => $raw,
        };
    }

    /**
     * The listing fields only: `described = false`, with an optional error;
     * `unchanged` for the digest skip.
     *
     * @param array<mixed> $entry
     */
    private static function listed(string $name, array $entry, ?string $error = null, bool $unchanged = false): ModelDescription
    {
        $base = self::description($name, $entry, null, null, []);

        return new ModelDescription(
            name: $base->name,
            family: $base->family,
            parameterSize: $base->parameterSize,
            quantization: $base->quantization,
            digest: $base->digest,
            supportsSampling: true,
            providerData: $base->providerData,
            described: false,
            error: $error,
            unchanged: $unchanged,
        );
    }

    /**
     * @param array<mixed>         $entry
     * @param list<string>|null    $capabilities
     * @param array<string, mixed> $extra        providerData from `/show`
     */
    private static function description(string $name, array $entry, ?array $capabilities, ?int $contextLength, array $extra): ModelDescription
    {
        $details = \is_array($entry['details'] ?? null) ? $entry['details'] : [];

        $providerData = array_filter([
            'families' => \is_array($details['families'] ?? null) ? array_values(array_filter($details['families'], 'is_string')) : null,
            'format' => self::stringOrNull($details['format'] ?? null),
            'size' => \is_int($entry['size'] ?? null) ? $entry['size'] : null,
            'modified_at' => self::stringOrNull($entry['modified_at'] ?? null),
        ], static fn (mixed $value): bool => null !== $value) + $extra;

        return new ModelDescription(
            name: $name,
            family: self::stringOrNull($details['family'] ?? null),
            parameterSize: self::stringOrNull($details['parameter_size'] ?? null),
            quantization: self::stringOrNull($details['quantization_level'] ?? null),
            digest: self::stringOrNull($entry['digest'] ?? null),
            capabilities: $capabilities,
            maxContextLength: $contextLength,
            // Ollama passes temperature, top_p and top_k to every model.
            supportsSampling: true,
            providerData: $providerData,
        );
    }
}

// - revised 2026-10-07
