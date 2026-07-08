<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap.
 *
 * Loads the Composer autoloader, then forces the internal self-monitoring
 * ingest key back to the inert placeholder for the ENTIRE test run. The
 * published package bakes a REAL public client key into
 * {@see \Shipeasy\InternalReport}, so without this a test that constructs a
 * reporting-enabled (non-test-mode) Engine and trips an internal fail-safe
 * guard would fire a real POST to Shipeasy's production /collect and pollute
 * our own Errors dashboard from CI.
 *
 * Holding the key at the placeholder makes keyConfigured() false, so
 * InternalReport::report() short-circuits before any network for every test.
 * The internal-report unit tests set their own fake key + injected sender per
 * test, so they are unaffected.
 */

require __DIR__ . '/../vendor/autoload.php';

\Shipeasy\InternalReport::setIngestKeyForTest(\Shipeasy\InternalReport::PLACEHOLDER_KEY);
