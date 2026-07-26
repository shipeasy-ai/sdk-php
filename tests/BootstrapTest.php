<?php

declare(strict_types=1);

namespace Shipeasy\Tests;

use PHPUnit\Framework\TestCase;
use Shipeasy\Engine;

final class BootstrapTest extends TestCase
{
    private function client(): Engine
    {
        return Engine::fromSnapshot(
            [
                'gates' => [
                    'new_ui' => ['enabled' => true, 'salt' => 's', 'rolloutPct' => 10000],
                    'off_gate' => ['enabled' => false, 'salt' => 's', 'rolloutPct' => 10000],
                ],
                'configs' => ['theme' => ['value' => ['color' => 'blue']]],
            ],
            ['experiments' => [], 'universes' => []]
        );
    }

    public function testEvaluateBuildsPayload(): void
    {
        $p = $this->client()->evaluate(['user_id' => 'u1']);
        $flags = (array) $p['flags'];
        $this->assertTrue($flags['new_ui']);
        $this->assertFalse($flags['off_gate']);
        $this->assertSame(['color' => 'blue'], (array) ((array) $p['configs'])['theme']);
        $this->assertSame([], (array) $p['killswitches']);
    }

    public function testBootstrapScriptTagAttrs(): void
    {
        $tag = $this->client()->bootstrapScriptTag(['user_id' => 'u1'], ['anonId' => 'anon-1']);
        $this->assertStringContainsString('src="https://cdn.shipeasy.ai/sdk/runtime.js"', $tag);
        $this->assertStringContainsString('data-se-bootstrap', $tag);
        $this->assertStringContainsString('data-anon-id="anon-1"', $tag);
        $this->assertStringContainsString('data-i18n-profile="en:prod"', $tag);
        // No key of any kind.
        $this->assertStringNotContainsString('data-key', $tag);

        // data-flags decodes back to valid JSON with the evaluated flag.
        preg_match('/data-flags="([^"]*)"/', $tag, $m);
        $flags = json_decode(html_entity_decode($m[1], ENT_QUOTES), true);
        $this->assertTrue($flags['new_ui']);
    }

    public function testBootstrapScriptTagOmitsAnonWhenUnset(): void
    {
        $tag = $this->client()->bootstrapScriptTag(['user_id' => 'u1']);
        $this->assertStringNotContainsString('data-anon-id', $tag);
    }

    public function testBootstrapScriptTagCarriesIdentifiedUser(): void
    {
        $tag = $this->client()->bootstrapScriptTag(
            ['user_id' => 'u1', 'email' => 'u1@example.com', 'anonymous_id' => 'anon-1'],
            ['anonId' => 'anon-1']
        );
        // anonymous_id still rides its own attribute.
        $this->assertStringContainsString('data-anon-id="anon-1"', $tag);

        // data-user carries the identified traits minus anonymous_id.
        preg_match('/data-user="([^"]*)"/', $tag, $m);
        $this->assertNotEmpty($m, 'expected a data-user attribute');
        $decoded = html_entity_decode($m[1], ENT_QUOTES);
        $this->assertSame('{"user_id":"u1","email":"u1@example.com"}', $decoded);
        $user = json_decode($decoded, true);
        $this->assertSame('u1', $user['user_id']);
        $this->assertSame('u1@example.com', $user['email']);
        $this->assertArrayNotHasKey('anonymous_id', $user);
    }

    public function testBootstrapScriptTagOmitsUserWhenAnonymous(): void
    {
        // Only an anonymous_id => nothing identified => no PII on the tag.
        $anon = $this->client()->bootstrapScriptTag(['anonymous_id' => 'anon-1'], ['anonId' => 'anon-1']);
        $this->assertStringNotContainsString('data-user', $anon);

        // Empty user => no data-user either.
        $empty = $this->client()->bootstrapScriptTag([], ['anonId' => 'anon-1']);
        $this->assertStringNotContainsString('data-user', $empty);
    }

    public function testBootstrapScriptTagKeepsEmptyStringTrait(): void
    {
        // Cross-SDK contract: only null is dropped — an empty-string trait is
        // kept, so a PHP backend emits the same data-user as the TS reference.
        $tag = $this->client()->bootstrapScriptTag(
            ['user_id' => 'u1', 'email' => ''],
            ['anonId' => 'anon-1']
        );
        preg_match('/data-user="([^"]*)"/', $tag, $m);
        $user = json_decode(html_entity_decode($m[1], ENT_QUOTES), true);
        $this->assertSame('u1', $user['user_id']);
        $this->assertArrayHasKey('email', $user);
        $this->assertSame('', $user['email']);
    }

    public function testI18nScriptTag(): void
    {
        $tag = $this->client()->i18nScriptTag('client_pub', 'fr:prod');
        $this->assertStringContainsString('src="https://cdn.shipeasy.ai/sdk/i18n/loader.js"', $tag);
        $this->assertStringContainsString('data-key="client_pub"', $tag);
        $this->assertStringContainsString('data-profile="fr:prod"', $tag);
    }

    // --- every argument is optional: the tags read what configure() set ------

    /** A test-mode engine carrying the SSR tag defaults, installed globally. */
    private function configured(): Engine
    {
        return Engine::configureForTesting([
            'clientKey' => 'sdk_client_cfg',
            'projectId' => 'proj_cfg',
            'profile' => 'fr:prod',
            'cdnBaseUrl' => 'https://cdn.example.test',
        ]);
    }

    public function testI18nScriptTagDefaultsFromConfigure(): void
    {
        $tag = $this->configured()->i18nScriptTag();
        $this->assertStringContainsString('src="https://cdn.example.test/sdk/i18n/loader.js"', $tag);
        $this->assertStringContainsString('data-key="sdk_client_cfg"', $tag);
        $this->assertStringContainsString('data-profile="fr:prod"', $tag);
    }

    public function testBootstrapScriptTagNeedsNoUser(): void
    {
        $tag = $this->configured()->bootstrapScriptTag();
        $this->assertStringContainsString('src="https://cdn.example.test/sdk/runtime.js"', $tag);
        $this->assertStringContainsString('data-i18n-profile="fr:prod"', $tag);
        $this->assertStringNotContainsString('data-user', $tag);
    }

    public function testDevtoolsScriptTagDefaultsFromConfigure(): void
    {
        $tag = $this->configured()->devtoolsScriptTag();
        $this->assertStringContainsString('src="https://cdn.example.test/se-devtools.js"', $tag);
        $this->assertStringContainsString('data-project-id="proj_cfg"', $tag);
        $this->assertStringContainsString('data-client-api-key="sdk_client_cfg"', $tag);
        $this->assertStringContainsString('defer', $tag);
    }

    public function testExplicitArgumentsStillWin(): void
    {
        $engine = $this->configured();
        $i18n = $engine->i18nScriptTag('other_key', 'de:prod');
        $this->assertStringContainsString('data-key="other_key"', $i18n);
        $this->assertStringContainsString('data-profile="de:prod"', $i18n);

        $boot = $engine->bootstrapScriptTag(['user_id' => 'u1'], ['i18nProfile' => 'de:prod']);
        $this->assertStringContainsString('data-i18n-profile="de:prod"', $boot);

        $dev = $engine->devtoolsScriptTag('proj_other', ['clientKey' => 'other_key', 'defer' => false]);
        $this->assertStringContainsString('data-project-id="proj_other"', $dev);
        $this->assertStringContainsString('data-client-api-key="other_key"', $dev);
        $this->assertStringNotContainsString('defer', $dev);
    }

    public function testDevtoolsTagStillRendersWhenUnconfigured(): void
    {
        // A missing project id / client key renders anyway (the browser bundle
        // reports what it needs) — it must never throw into a template.
        $tag = $this->client()->devtoolsScriptTag();
        $this->assertStringContainsString('src="https://cdn.shipeasy.ai/se-devtools.js"', $tag);
        $this->assertStringContainsString('data-project-id=""', $tag);
    }

    public function testPackageLevelTagsTakeNoArguments(): void
    {
        $this->configured();
        $this->assertStringContainsString('data-key="sdk_client_cfg"', \Shipeasy\i18nScriptTag());
        $this->assertStringContainsString('data-se-bootstrap', \Shipeasy\bootstrapScriptTag());
        $this->assertStringContainsString('data-project-id="proj_cfg"', \Shipeasy\devtoolsScriptTag());
    }
}
