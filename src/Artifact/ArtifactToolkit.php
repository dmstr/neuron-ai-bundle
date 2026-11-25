<?php

// file generated with AI assistance: Claude Code - 2026-10-16 08:40:52 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Artifact;

use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\Toolkits\FileSystem\BashTool;
use NeuronAI\Tools\Toolkits\FileSystem\DeleteFileTool;
use NeuronAI\Tools\Toolkits\FileSystem\FileSystemToolkit;
use NeuronAI\Tools\Toolkits\FileSystem\ParseFileTool;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Lock\LockFactory;

/**
 * The neuron-ai file toolkit scoped to the user root of one turn's
 * {@see ArtifactAccess}, with a fixed tool set and every tool wrapped in an
 * {@see ArtifactTool}.
 *
 * - Offered: `read_file`, `grep_file_content`, `glob_path`, and with a
 *   writable access `write_file` and `edit_file`. `bash` (unconfined
 *   command), `delete_file` (no undo) and `parse_file` (dependencies missing)
 *   are never offered. The exclusion goes by class name, the only form the
 *   toolkit understands.
 * - {@see guidelines()} replaces the stock text, which names the absolute
 *   scope path and the excluded tools.
 * - The set is final: {@see tools()} ignores `exclude()`, `only()`,
 *   `with()` and `add()`, so nothing unwrapped can join it.
 */
final class ArtifactToolkit extends FileSystemToolkit
{
    public const array TOOL_NAMES = ['read_file', 'grep_file_content', 'glob_path', 'write_file', 'edit_file'];

    public const array WRITE_TOOL_NAMES = ['write_file', 'edit_file'];

    /** Stock tools that are never offered. */
    public const array EXCLUDED_TOOLS = [BashTool::class, DeleteFileTool::class, ParseFileTool::class];

    public function __construct(
        private readonly ArtifactAccess $access,
        private readonly ArtifactLimitsInterface $limits,
        private readonly ArtifactLedgerInterface $ledger,
        private readonly LockFactory $lockFactory,
        private readonly float $lockWaitSeconds = ArtifactTool::DEFAULT_LOCK_WAIT_SECONDS,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        parent::__construct($access->scopeRoot);
    }

    /**
     * @return list<ToolInterface> the wrapped tools in the order of {@see TOOL_NAMES}; without a writable access only the three read tools
     */
    public function provide(): array
    {
        $tools = [];
        foreach (parent::provide() as $tool) {
            if (\in_array($tool::class, self::EXCLUDED_TOOLS, true)) {
                continue;
            }

            if (!$this->access->writable && \in_array($tool->getName(), self::WRITE_TOOL_NAMES, true)) {
                continue;
            }

            $tools[$tool->getName()] = new ArtifactTool($tool, $this->access, $this->limits, $this->ledger, $this->lockFactory, $this->lockWaitSeconds, $this->logger);
        }

        $ordered = [];
        foreach (self::TOOL_NAMES as $name) {
            if (isset($tools[$name])) {
                $ordered[] = $tools[$name];
            }
        }

        return $ordered;
    }

    /**
     * @return list<ToolInterface>
     */
    public function tools(): array
    {
        return $this->provide();
    }

    /**
     * Model-facing instructions without any server path: paths are
     * relative to the user's artifact folder, the conversation writes into
     * its working folder `<conversationId>/`, listing is `glob_path`, and
     * the limits.
     */
    public function guidelines(): ?string
    {
        $folder = $this->access->conversationId.'/';
        $lines = [
            'These tools work on the user\'s artifact files, which persist across conversations. Every path is relative to the user\'s artifact folder; absolute paths and paths leaving it are refused.',
            sprintf('The working folder of this conversation is `%s`. Earlier conversations have their own folders next to it.', $folder),
            "List files with glob_path(directory: '.', pattern: '**/*'), or pattern '*' for one level only.",
            sprintf('read_file refuses files of more than %d characters; search those with grep_file_content (a PCRE pattern with delimiters, e.g. /term/i).', $this->limits->maxReadChars()),
        ];

        if ($this->access->writable) {
            $lines[] = sprintf('Write new files into `%s` unless the user names an existing artifact. write_file creates or overwrites a whole file; edit_file replaces one exact text passage that occurs exactly once. Files cannot be deleted.', $folder);
            $lines[] = sprintf(
                'Limits: %d bytes per file, %d files in the working folder, %d bytes for all artifact files of the user.',
                $this->limits->maxFileSizeBytes(),
                $this->limits->maxFilesPerConversation(),
                $this->limits->maxTotalSizeBytesPerUser(),
            );
        } else {
            $lines[] = 'Artifacts are read-only in this conversation: files cannot be created or changed.';
        }

        $lines[] = sprintf('Available file tools: %s.', implode(', ', array_map(static fn (ToolInterface $tool): string => $tool->getName(), $this->provide())));

        return implode(' ', $lines);
    }
}
