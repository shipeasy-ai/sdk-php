<?php

declare(strict_types=1);

namespace Shipeasy\Tests\Laravel;

use PHPUnit\Framework\TestCase;
use Shipeasy\Laravel\Installer;

/**
 * Unit tests for the framework-free core of `php artisan shipeasy:install`.
 * No Illuminate required — operates on tmp files.
 */
final class InstallerTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/shipeasy-installer-' . bin2hex(random_bytes(6)) . '.env';
    }

    protected function tearDown(): void
    {
        if (is_file($this->tmp)) {
            unlink($this->tmp);
        }
    }

    public function testCreatesFileAndAppendsMissingKeys(): void
    {
        $this->assertFileDoesNotExist($this->tmp);

        $added = Installer::ensureEnvKeys($this->tmp, ['SHIPEASY_SERVER_KEY', 'SHIPEASY_CLIENT_KEY']);

        $this->assertSame(['SHIPEASY_SERVER_KEY', 'SHIPEASY_CLIENT_KEY'], $added);
        $contents = (string) file_get_contents($this->tmp);
        $this->assertStringContainsString("SHIPEASY_SERVER_KEY=\n", $contents);
        $this->assertStringContainsString("SHIPEASY_CLIENT_KEY=\n", $contents);
    }

    public function testIsIdempotent(): void
    {
        Installer::ensureEnvKeys($this->tmp, ['SHIPEASY_SERVER_KEY']);
        $first = (string) file_get_contents($this->tmp);

        // Second run adds nothing.
        $added = Installer::ensureEnvKeys($this->tmp, ['SHIPEASY_SERVER_KEY']);
        $this->assertSame([], $added);
        $this->assertSame($first, (string) file_get_contents($this->tmp));

        // Re-runs do not multiply the line.
        Installer::ensureEnvKeys($this->tmp, ['SHIPEASY_SERVER_KEY']);
        $this->assertSame(1, substr_count((string) file_get_contents($this->tmp), 'SHIPEASY_SERVER_KEY='));
    }

    public function testDoesNotClobberExistingValue(): void
    {
        file_put_contents($this->tmp, "APP_NAME=Demo\nSHIPEASY_SERVER_KEY=already-set\n");

        $added = Installer::ensureEnvKeys($this->tmp, ['SHIPEASY_SERVER_KEY', 'SHIPEASY_CLIENT_KEY']);

        // Existing key untouched; only the missing one appended.
        $this->assertSame(['SHIPEASY_CLIENT_KEY'], $added);
        $contents = (string) file_get_contents($this->tmp);
        $this->assertStringContainsString('SHIPEASY_SERVER_KEY=already-set', $contents);
        $this->assertSame(1, substr_count($contents, 'SHIPEASY_SERVER_KEY='));
    }

    public function testEnsuresTrailingNewlineBeforeAppending(): void
    {
        // File with no trailing newline.
        file_put_contents($this->tmp, 'APP_NAME=Demo');

        Installer::ensureEnvKeys($this->tmp, ['SHIPEASY_SERVER_KEY']);

        $contents = (string) file_get_contents($this->tmp);
        $this->assertStringContainsString("APP_NAME=Demo\nSHIPEASY_SERVER_KEY=\n", $contents);
    }

    public function testRecognisesKeyWithSurroundingWhitespace(): void
    {
        file_put_contents($this->tmp, "  SHIPEASY_SERVER_KEY = x\n");
        $added = Installer::ensureEnvKeys($this->tmp, ['SHIPEASY_SERVER_KEY']);
        $this->assertSame([], $added);
    }

    public function testNextStepsServerKeyReminderAndBootstrapDirective(): void
    {
        $steps = Installer::nextSteps(false);

        $this->assertStringContainsString('SHIPEASY_SERVER_KEY', $steps);
        $this->assertStringContainsString('@shipeasyBootstrap', $steps);
        $this->assertStringContainsString('https://docs.shipeasy.ai', $steps);
        // Without --i18n, the client key + i18n directive are not mentioned.
        $this->assertStringNotContainsString('SHIPEASY_CLIENT_KEY', $steps);
        $this->assertStringNotContainsString('@shipeasyI18n', $steps);
    }

    public function testNextStepsI18nMentionsClientKeyAndI18nDirective(): void
    {
        $steps = Installer::nextSteps(true);

        $this->assertStringContainsString('SHIPEASY_CLIENT_KEY', $steps);
        $this->assertStringContainsString('@shipeasyI18n', $steps);
        $this->assertStringContainsString('@shipeasyBootstrap', $steps);
    }
}
