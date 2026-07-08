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

/**
 * Declare the test suite production-equivalent for EGRESS.
 *
 * As of the environment-derived egress defaults, network + telemetry default OFF
 * outside production, and the suite runs in a non-production env. The existing
 * tests exercise the real network code paths (track / exposure / see / fetch)
 * through Engine subclasses that capture the outbound payload, so they must run
 * with egress ON. Force SHIPEASY_ENV=production process-wide here (mirrors
 * sdk-ts's src/__tests__/setup.ts). The dedicated env tests in EnvTest override
 * this locally to assert the dev/prod branching.
 */
putenv('SHIPEASY_ENV=production');
$_ENV['SHIPEASY_ENV'] = 'production';
$_SERVER['SHIPEASY_ENV'] = 'production';
