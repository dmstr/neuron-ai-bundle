<?php

// file generated with AI assistance: Claude Code - 2026-10-16 08:40:52 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Artifact;

/**
 * Paths of the artifact storage: `<root>/<ownerSubject>` is the user root
 * (the scope of the file tools), `<root>/<ownerSubject>/<conversationId>`
 * the working folder of one conversation.
 *
 * The owner subject becomes a directory name, so it is checked against
 * {@see SUBJECT_PATTERN} (and `.`/`..` are refused) before any path is
 * built; the conversation id must be a lower-case RFC 4122 UUID, the form
 * the entities print, so one conversation never gets two folders on a
 * case-insensitive share.
 */
final class ArtifactScope
{
    public const string SUBJECT_PATTERN = '/^[A-Za-z0-9._-]{1,128}$/';

    private const string UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    /** Directory mode of user roots and working folders. */
    private const int DIRECTORY_MODE = 0o750;

    private readonly string $root;

    /**
     * @param string $root the storage root (bundle configuration `artifacts.root`)
     */
    public function __construct(string $root)
    {
        $this->root = '/' === $root ? $root : rtrim($root, '/');
    }

    public static function isValidSubject(string $subject): bool
    {
        return 1 === preg_match(self::SUBJECT_PATTERN, $subject) && '.' !== $subject && '..' !== $subject;
    }

    public static function isValidConversationId(string $conversationId): bool
    {
        return 1 === preg_match(self::UUID_PATTERN, $conversationId);
    }

    /**
     * Creates `<root>/<ownerSubject>/<conversationId>` when missing and
     * returns the canonical user root. A user root or working folder that is
     * a symlink, or that resolves outside the storage root, is refused.
     *
     * @throws ArtifactScopeException
     */
    public function ensure(string $ownerSubject, string $conversationId): string
    {
        if (!self::isValidSubject($ownerSubject)) {
            throw new ArtifactScopeException('The owner subject is not usable as an artifact folder name.');
        }

        if (!self::isValidConversationId($conversationId)) {
            throw new ArtifactScopeException('The conversation id is not a lower-case RFC 4122 UUID.');
        }

        $root = realpath($this->root);
        if (false === $root || !is_dir($root)) {
            throw new ArtifactScopeException(sprintf('The artifact storage root "%s" does not exist.', $this->root));
        }

        // realpath('/') keeps its slash; every other root comes back without one
        $base = rtrim($root, \DIRECTORY_SEPARATOR);
        $userRoot = $base.\DIRECTORY_SEPARATOR.$ownerSubject;
        $folder = $userRoot.\DIRECTORY_SEPARATOR.$conversationId;

        if (is_link($userRoot) || is_link($folder)) {
            throw new ArtifactScopeException(sprintf('The artifact folder "%s" is a symlink.', $folder));
        }

        if (!is_dir($folder)) {
            if (!is_writable(file_exists($userRoot) ? $userRoot : $root)) {
                throw new ArtifactScopeException(sprintf('The artifact storage root "%s" is not writable.', $this->root));
            }

            // Failures are reported by the check below; a concurrent turn
            // creating the same folder is no error.
            if (!@mkdir($folder, self::DIRECTORY_MODE, true) && !is_dir($folder)) {
                throw new ArtifactScopeException(sprintf('The artifact folder "%s" could not be created.', $folder));
            }
        }

        $canonical = realpath($userRoot);
        if (false === $canonical || $canonical !== $userRoot) {
            throw new ArtifactScopeException(sprintf('The artifact folder "%s" resolves outside the storage root.', $userRoot));
        }

        return $canonical;
    }

    public function root(): string
    {
        return $this->root;
    }
}
