<?php

// file generated with AI assistance: Claude Code - 2026-11-25 23:30:00 UTC

declare(strict_types=1);

/*
 * PHPUnit bootstrap of the bundle's tests.
 *
 * Without symfony/runtime, FrameworkBundle::boot() registers Symfony's
 * ErrorHandler whenever a kernel boots. While a test runs, PHPUnit's error
 * handler is in charge and the registration steps aside. A kernel booted
 * while no handler is registered at all (outside a test method, e.g. in
 * setUpBeforeClass()) makes Symfony's handler the root handler for the rest
 * of the process: PHPUnit then forwards every PHP warning and notice to it,
 * and in debug mode it throws them as ErrorException, so the outcome of a
 * test depends on the order of the run. A host application with
 * symfony/runtime never registers that handler.
 *
 * This root handler hands every error back to PHP (return false), so a
 * kernel boot never finds an empty handler stack and Symfony's handler is
 * never installed; while a test runs, PHPUnit's handler sits on top of it
 * and records the issues as usual.
 */

require dirname(__DIR__, 2).'/vendor/autoload.php';

set_error_handler(static fn (): bool => false);
