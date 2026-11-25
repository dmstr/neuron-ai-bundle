<?php

// file generated with AI assistance: Claude Code - 2026-10-16 08:40:52 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Artifact;

/**
 * Path rules of the artifact tools, shared by the tool wrapper and the
 * ledger.
 */
final class ArtifactPath
{
    /** Longest path a tool accepts, in bytes. */
    public const int MAX_PATH_BYTES = 1024;

    /** Longest file or folder name a tool accepts, in bytes (common file system limit). */
    public const int MAX_NAME_BYTES = 255;

    private function __construct()
    {
    }

    /** The ledger key of a path: its lower-case spelling. */
    public static function lower(string $path): string
    {
        return mb_strtolower($path, 'UTF-8');
    }

    /**
     * Why a path from the model is refused before any file system call, or
     * null when it may be passed on. The message names no path.
     */
    public static function invalidReason(string $path): ?string
    {
        if (str_contains($path, "\0")) {
            return 'Invalid path: it contains a NUL byte.';
        }

        if (\strlen($path) > self::MAX_PATH_BYTES) {
            return sprintf('Invalid path: it is longer than %d bytes.', self::MAX_PATH_BYTES);
        }

        foreach (explode('/', str_replace('\\', '/', $path)) as $name) {
            if (\strlen($name) > self::MAX_NAME_BYTES) {
                return sprintf('Invalid path: a file or folder name is longer than %d bytes.', self::MAX_NAME_BYTES);
            }
        }

        return null;
    }

    /**
     * The path relative to `$scopeRoot` the tool would act on, resolved
     * like the neuron-ai file tools do it (`.` and `..` lexically, the
     * existing part through realpath(), so symlinks count where they
     * point), or null when it lies outside the scope or cannot be resolved
     * (dangling symlink). `.` stands for the scope root itself.
     *
     * @param string $scopeRoot canonical, existing directory
     */
    public static function relative(string $scopeRoot, string $path): ?string
    {
        $absolute = str_starts_with($path, '/') ? $path : $scopeRoot.'/'.$path;

        $segments = [];
        foreach (explode('/', $absolute) as $segment) {
            if ('' === $segment || '.' === $segment) {
                continue;
            }

            if ('..' === $segment) {
                array_pop($segments);
                continue;
            }

            $segments[] = $segment;
        }

        $existing = '/'.implode('/', $segments);
        $missing = '';
        while (!file_exists($existing) && !is_link($existing)) {
            $parent = \dirname($existing);
            if ($parent === $existing) {
                return null;
            }

            $missing = '/'.basename($existing).$missing;
            $existing = $parent;
        }

        $real = realpath($existing);
        if (false === $real) {
            return null;
        }

        $canonical = rtrim($real, '/').$missing;
        if ($canonical === $scopeRoot) {
            return '.';
        }

        $prefix = rtrim($scopeRoot, '/').'/';

        return str_starts_with($canonical, $prefix) ? substr($canonical, \strlen($prefix)) : null;
    }
}
