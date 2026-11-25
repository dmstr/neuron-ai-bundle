<?php

// file generated with AI assistance: Claude Code - 2026-10-16 08:40:52 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Artifact;

use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Exceptions\ToolException;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\ToolOutput;
use NeuronAI\Tools\ToolPropertyInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Lock\Exception\ExceptionInterface as LockException;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;

/**
 * Wrapper around one neuron-ai file tool, scoped to the user root of an
 * {@see ArtifactAccess} (decoration, not inheritance: the stock tools stay
 * untouched and every {@see ToolInterface} method the agent loop and the
 * provider mappers use is delegated: name, description, properties, input
 * schema, inputs, call id, run key, approval).
 *
 * Before delegating, the wrapper
 *
 *  - refuses paths with a NUL byte, longer than 1024 bytes or with a name
 *    longer than 255 bytes, and turns an absolute path inside the scope
 *    into a relative one (the stock tool decides everything else: `..`,
 *    absolute paths outside, symlinks pointing outside, dangling symlinks);
 *  - refuses `write_file` and `edit_file` when the access is not writable
 *    (second line of defence: the toolkit does not offer them then);
 *  - for `write_file` and `edit_file`, under the lock `artifact:<owner>`:
 *    checks the file size limit (for `edit_file` the size after the edit),
 *    the case collision with a recorded file, the number of files in the
 *    conversation's working folder for a new file there and the user quota,
 *    then writes and records the file in the ledger. Holding the lock over
 *    check, write and record keeps two conversations of one user from
 *    passing the quota together;
 *  - for `read_file`, refuses a file with more characters than the read
 *    limit, with a hint to `grep_file_content`.
 *
 * Every result, success or error, leaves the wrapper with the scope root
 * replaced by a relative path, so no server path reaches the model, the
 * message record or the stream. Results stay `ToolOutput` objects, so an
 * error is recorded as `toolError`. Failures are always returned as
 * `ToolOutput::error()`, never thrown.
 */
final class ArtifactTool implements ToolInterface
{
    /** How long a write waits for a write of another turn of the same user. */
    public const float DEFAULT_LOCK_WAIT_SECONDS = 5.0;

    /** Lifetime of the lock; a write takes milliseconds, the TTL only bounds a crashed worker. */
    private const float LOCK_TTL_SECONDS = 30.0;

    private const int LOCK_POLL_MICROSECONDS = 50000;

    /** The path input of each tool. */
    private const array PATH_INPUTS = [
        'read_file' => 'file_path',
        'grep_file_content' => 'file_path',
        'glob_path' => 'directory',
        'write_file' => 'file_path',
        'edit_file' => 'file_path',
    ];

    /** Pattern of the length note `read_file` appends to the content. */
    private const string READ_SUFFIX_PATTERN = '/\n\n\[File read successfully: (\d+) characters\]\z/';

    /** UTF-8 needs at most four bytes per character. */
    private const int MAX_BYTES_PER_CHAR = 4;

    private string|ToolOutput|null $result = null;

    public function __construct(
        private ToolInterface $inner,
        private readonly ArtifactAccess $access,
        private readonly ArtifactLimitsInterface $limits,
        private readonly ArtifactLedgerInterface $ledger,
        private readonly LockFactory $lockFactory,
        private readonly float $lockWaitSeconds = self::DEFAULT_LOCK_WAIT_SECONDS,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * The agent loop binds every call onto a clone of the registered tool;
     * the wrapped tool holds the inputs, so it is cloned with it.
     */
    public function __clone()
    {
        $this->inner = clone $this->inner;
    }

    public function execute(): void
    {
        $this->result = $this->sanitize($this->run());
    }

    private function run(): string|ToolOutput
    {
        $name = $this->inner->getName();
        $isWrite = \in_array($name, ArtifactToolkit::WRITE_TOOL_NAMES, true);

        if ($isWrite && !$this->access->writable) {
            return ToolOutput::error(sprintf('%s is not available: artifacts are read-only in this conversation.', $name));
        }

        $path = $this->preparePath($name);
        if ($path instanceof ToolOutput) {
            return $path;
        }

        if ($isWrite && null !== $path) {
            return $this->write($name, $path);
        }

        if ('read_file' === $name && null !== $path) {
            $refusal = $this->checkReadSize($path);
            if (null !== $refusal) {
                return $refusal;
            }
        }

        $this->inner->execute();
        $result = $this->inner->getResult();

        return 'read_file' === $name ? $this->limitRead($result, (string) $path) : $result;
    }

    /**
     * Validates the path input and rewrites an absolute path inside the
     * scope to a relative one. Null when the tool has no usable path input
     * (the stock tool reports a missing or mistyped input itself).
     */
    private function preparePath(string $name): string|ToolOutput|null
    {
        $key = self::PATH_INPUTS[$name] ?? null;
        $value = null === $key ? null : $this->inner->getInput($key);
        if (!\is_string($value)) {
            return null;
        }

        $reason = ArtifactPath::invalidReason($value);
        if (null !== $reason) {
            return ToolOutput::error($reason);
        }

        $relative = $this->relativize($value);
        if ($relative !== $value) {
            $inputs = $this->inner->getInputs();
            $inputs[$key] = $relative;
            $this->inner->setInputs($inputs);
        }

        return $relative;
    }

    private function relativize(string $path): string
    {
        $root = rtrim($this->access->scopeRoot, '/');
        if ($path === $root || $path === $root.'/') {
            return '.';
        }

        return str_starts_with($path, $root.'/') ? substr($path, \strlen($root) + 1) : $path;
    }

    /**
     * `write_file` or `edit_file` under the owner's lock: limits, write,
     * ledger.
     */
    private function write(string $name, string $path): string|ToolOutput
    {
        $relative = ArtifactPath::relative($this->access->scopeRoot, $path);
        if (null === $relative || '.' === $relative) {
            // Outside the scope or the root itself: the stock tool refuses.
            $this->inner->execute();

            return $this->inner->getResult();
        }

        $lock = $this->lockFactory->createLock('artifact:'.$this->access->ownerSubject, self::LOCK_TTL_SECONDS);
        if (!$this->acquire($lock)) {
            return ToolOutput::error('Another write to your artifacts is in progress. Try again in a moment.');
        }

        try {
            try {
                $refusal = $this->checkWriteLimits($name, $relative);
            } catch (\Throwable $exception) {
                $this->logger->error('Artifact ledger read failed: {message}', ['message' => $exception->getMessage(), 'exception' => $exception]);

                return ToolOutput::error('The artifact storage is temporarily unavailable. Try again later.');
            }

            if (null !== $refusal) {
                return $refusal;
            }

            $this->inner->execute();
            $result = $this->inner->getResult();

            if (!$result instanceof ToolOutput || !$result->isError()) {
                $this->record($path);
            }

            return $result;
        } finally {
            $lock->release();
        }
    }

    private function acquire(LockInterface $lock): bool
    {
        $deadline = microtime(true) + $this->lockWaitSeconds;

        try {
            while (true) {
                if ($lock->acquire(false)) {
                    return true;
                }

                if (microtime(true) >= $deadline) {
                    return false;
                }

                usleep(self::LOCK_POLL_MICROSECONDS);
            }
        } catch (LockException $exception) {
            $this->logger->error('Artifact lock failed: {message}', ['message' => $exception->getMessage(), 'exception' => $exception]);

            return false;
        }
    }

    /**
     * The refusal of a write that would break a limit, or null. A write the
     * stock tool will refuse anyway (missing input, `edit_file` without
     * exactly one match) passes unchecked, so the model gets the stock
     * message.
     */
    private function checkWriteLimits(string $name, string $relative): ?ToolOutput
    {
        $size = $this->sizeAfterWrite($name, $relative);
        if (null === $size) {
            return null;
        }

        $maxFileSize = $this->limits->maxFileSizeBytes();
        if ($size > $maxFileSize) {
            return ToolOutput::error(sprintf("'%s' would be %d bytes, more than the limit of %d bytes per file.", $relative, $size, $maxFileSize));
        }

        $owner = $this->access->ownerSubject;
        $entry = $this->ledger->find($owner, ArtifactPath::lower($relative));

        if (null !== $entry && $entry->path !== $relative) {
            return ToolOutput::error(sprintf("'%s' cannot be written: the name differs only in case from %s. Use that name.", $relative, $entry->path));
        }

        if (null === $entry && $this->inConversationFolder($relative)) {
            $maxFiles = $this->limits->maxFilesPerConversation();
            if ($this->ledger->countInFolder($owner, $this->access->conversationId) >= $maxFiles) {
                return ToolOutput::error(sprintf('The working folder of this conversation already holds %d files, the limit. Edit or overwrite an existing file instead.', $maxFiles));
            }
        }

        $maxTotal = $this->limits->maxTotalSizeBytesPerUser();
        $total = $this->ledger->totalSize($owner) - ($entry->sizeBytes ?? 0) + $size;
        if ($total > $maxTotal) {
            return ToolOutput::error(sprintf('Your artifacts would take %d bytes, more than your quota of %d bytes. Shorten or overwrite an existing file instead.', $total, $maxTotal));
        }

        return null;
    }

    /**
     * Size of the file after the write, or null when the stock tool will
     * refuse the call.
     */
    private function sizeAfterWrite(string $name, string $relative): ?int
    {
        if ('write_file' === $name) {
            $content = $this->inner->getInput('content');

            return \is_string($content) ? \strlen($content) : null;
        }

        $search = $this->inner->getInput('search');
        $replace = $this->inner->getInput('replace');
        $file = $this->access->scopeRoot.'/'.$relative;
        if (!\is_string($search) || '' === $search || !\is_string($replace) || !is_file($file) || !is_readable($file)) {
            return null;
        }

        $current = @file_get_contents($file);
        if (false === $current || 1 !== substr_count($current, $search)) {
            return null;
        }

        return \strlen($current) - \strlen($search) + \strlen($replace);
    }

    private function inConversationFolder(string $relative): bool
    {
        return str_starts_with(ArtifactPath::lower($relative), ArtifactPath::lower($this->access->conversationId).'/');
    }

    /**
     * Records a written file in the ledger, under its canonical path. The
     * file is written at this point, so a ledger failure is logged and the
     * success kept (the quota then lags by this file until its next write).
     */
    private function record(string $path): void
    {
        $relative = ArtifactPath::relative($this->access->scopeRoot, $path);
        if (null === $relative || '.' === $relative) {
            return;
        }

        $file = $this->access->scopeRoot.'/'.$relative;
        clearstatcache(true, $file);
        if (!is_file($file)) {
            return;
        }

        try {
            $this->ledger->record($this->access->ownerSubject, $relative, (int) filesize($file), $this->access->conversationId);
        } catch (\Throwable $exception) {
            $this->logger->error('Artifact ledger write failed for a file of conversation {conversation}: {message}', [
                'conversation' => $this->access->conversationId,
                'message' => $exception->getMessage(),
                'exception' => $exception,
            ]);
        }
    }

    /**
     * Refuses a file that cannot fit the read limit before reading it: more
     * bytes than four times the limit means more characters than the limit.
     */
    private function checkReadSize(string $path): ?ToolOutput
    {
        $relative = ArtifactPath::relative($this->access->scopeRoot, $path);
        if (null === $relative) {
            return null;
        }

        $file = $this->access->scopeRoot.'/'.$relative;
        $max = $this->limits->maxReadChars();
        if (is_file($file) && (int) filesize($file) > $max * self::MAX_BYTES_PER_CHAR) {
            return $this->readRefusal($path, null, $max);
        }

        return null;
    }

    private function limitRead(string|ToolOutput $result, string $path): string|ToolOutput
    {
        if ($result instanceof ToolOutput) {
            return $result;
        }

        $chars = 1 === preg_match(self::READ_SUFFIX_PATTERN, $result, $match) ? (int) $match[1] : mb_strlen($result);
        $max = $this->limits->maxReadChars();

        return $chars > $max ? $this->readRefusal($path, $chars, $max) : $result;
    }

    private function readRefusal(string $path, ?int $chars, int $max): ToolOutput
    {
        return ToolOutput::error(sprintf(
            "'%s' is too large to read: %s the read limit of %d characters. Use grep_file_content to search it instead.",
            $path,
            null === $chars ? 'it exceeds' : sprintf('%d characters exceed', $chars),
            $max,
        ));
    }

    /**
     * Replaces the scope root by a relative path in every text of the
     * result, plain and JSON-escaped (array results of the stock tools are
     * JSON strings): `<root>/x` becomes `x`, a bare `<root>` becomes `.`.
     * Only whole path segments match, so a sibling such as `<root>2` the
     * model named itself stays as written. The stock hint to `parse_file`,
     * which is not offered, is dropped.
     */
    private function sanitize(string|ToolOutput $result): string|ToolOutput
    {
        if (\is_string($result)) {
            return $this->sanitizeText($result);
        }

        $blocks = [];
        foreach ($result->getBlocks() as $block) {
            if ($block instanceof TextContent) {
                $block = clone $block;
                $block->content = $this->sanitizeText($block->content);
            }

            $blocks[] = $block;
        }

        return new ToolOutput($blocks, $result->isError());
    }

    private function sanitizeText(string $text): string
    {
        $root = rtrim($this->access->scopeRoot, '/');
        $json = substr((string) json_encode($root, \JSON_UNESCAPED_UNICODE), 1, -1);
        $jsonEscaped = substr((string) json_encode($root), 1, -1);

        foreach (array_unique([[$root, '/'], [$json, '\\/'], [$jsonEscaped, '\\/']], \SORT_REGULAR) as [$needle, $separator]) {
            $text = (string) preg_replace_callback(
                '#'.preg_quote($needle, '#').'(?:('.preg_quote($separator, '#').')|(?![A-Za-z0-9._-]))#',
                static fn (array $match): string => isset($match[1]) && '' !== $match[1] ? '' : '.',
                $text,
            );
        }

        return str_replace(' Use parse_file for PDF or HTML documents.', '', $text);
    }

    public function getName(): string
    {
        return $this->inner->getName();
    }

    public function setName(string $name): ToolInterface
    {
        $this->inner->setName($name);

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->inner->getDescription();
    }

    public function setDescription(?string $description): ToolInterface
    {
        $this->inner->setDescription($description);

        return $this;
    }

    public function addProperty(ToolPropertyInterface $property): ToolInterface
    {
        $this->inner->addProperty($property);

        return $this;
    }

    public function getProperties(): array
    {
        return $this->inner->getProperties();
    }

    public function getRequiredProperties(): array
    {
        return $this->inner->getRequiredProperties();
    }

    public function getInputSchema(): array
    {
        return $this->inner->getInputSchema();
    }

    public function getParameters(): array
    {
        return $this->inner->getParameters();
    }

    public function getInputs(): array
    {
        return $this->inner->getInputs();
    }

    public function getInput(string $key): mixed
    {
        return $this->inner->getInput($key);
    }

    public function setInputs(array $inputs): ToolInterface
    {
        $this->inner->setInputs($inputs);

        return $this;
    }

    public function getCallId(): ?string
    {
        return $this->inner->getCallId();
    }

    public function setCallId(string $callId): ToolInterface
    {
        $this->inner->setCallId($callId);

        return $this;
    }

    public function hasResult(): bool
    {
        return null !== $this->result;
    }

    public function getResult(): string|ToolOutput
    {
        if (null === $this->result) {
            throw new ToolException(sprintf('Tool %s has no result: it was never executed.', $this->getName()));
        }

        return $this->result;
    }

    public function setResult(mixed $result): ToolInterface
    {
        if (!$result instanceof ToolOutput) {
            $result = \is_array($result) ? json_encode($result, \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_THROW_ON_ERROR) : (string) $result;
        }

        $this->result = $this->sanitize($result);

        return $this;
    }

    public function getMaxRuns(): ?int
    {
        return $this->inner->getMaxRuns();
    }

    public function setMaxRuns(int $tries): ToolInterface
    {
        $this->inner->setMaxRuns($tries);

        return $this;
    }

    public function visible(bool $visible): ToolInterface
    {
        $this->inner->visible($visible);

        return $this;
    }

    public function isVisible(): bool
    {
        return $this->inner->isVisible();
    }

    public function getRunKey(): string
    {
        return $this->inner->getRunKey();
    }

    public function requiresApproval(): bool|string
    {
        return $this->inner->requiresApproval();
    }

    /**
     * The wrapped tool's serialization with the wrapper's (sanitised)
     * result.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        $data = $this->inner->jsonSerialize();
        $data['result'] = $this->result instanceof ToolOutput ? $this->result->jsonSerialize() : $this->result;

        return $data;
    }
}
