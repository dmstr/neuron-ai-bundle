<?php

// file generated with AI assistance: Claude Code - 2026-10-16 08:40:52 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Support;

/**
 * Temporary directories of the artifact tests.
 */
final class ArtifactTestFiles
{
    public static function makeTempDir(): string
    {
        $dir = sys_get_temp_dir().'/artifact-test-'.bin2hex(random_bytes(6));
        mkdir($dir, 0o755, true);

        return (string) realpath($dir);
    }

    /**
     * Removes a directory tree without following symlinks.
     */
    public static function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }

        if (!is_dir($path)) {
            return;
        }

        chmod($path, 0o755);
        foreach (scandir($path) ?: [] as $item) {
            if ('.' !== $item && '..' !== $item) {
                self::remove($path.'/'.$item);
            }
        }

        rmdir($path);
    }
}
