<?php

namespace Tests\Feature;

use Shipeasy\Client;
use Tests\TestCase;

use function Shipeasy\configureForTesting;

/**
 * Feature test for the single-page entity guide ('/').
 *
 * It MOCKS every value Shipeasy returns with the SDK's testing API
 * (`Shipeasy\configureForTesting()` — no network, no API key), fetches the page
 * IN-PROCESS with Laravel's HTTP test client, and asserts the rendered HTML
 * contains each mocked value.
 *
 * NOTE: the example app is intentionally NOT wired to the SDK yet — the route
 * renders hardcoded PLACEHOLDERS. So the per-value `assertSee()` assertions are
 * EXPECTED to FAIL until the route is rewired to read through the bound Client.
 * Everything else (Laravel boots, the route is hit in-process, HTML comes back,
 * the mock is in effect) is correct and should pass.
 */
class GuidePageTest extends TestCase
{
    /**
     * Mocked values. Entity KEYS match routes/web.php, but the VALUES are
     * DISTINCTIVE sentinels deliberately chosen NOT to match the strings the
     * route currently hardcodes as placeholders. That keeps the page
     * assertions honest: they pass only once the route reads through the bound
     * Client, so today (route not wired) they FAIL on value mismatch.
     */
    private const FLAG_KEY = 'new_checkout';

    private const CONFIG_KEY = 'billing_copy';
    private const CONFIG_VALUE = [
        'headline' => 'Welcome aboard 🚀',
        'cta'      => 'Start free trial',
    ];

    private const EXPERIMENT_KEY = 'checkout_button';
    private const EXPERIMENT_GROUP = 'treatment';
    private const EXPERIMENT_PARAMS = [
        'color' => '#0ea5e9',
        'label' => 'Checkout now',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // Seed deterministic values up front — no network, no API key.
        //   flags       => ['name' => bool]
        //   configs     => ['name' => value]
        //   experiments => ['name' => [group, params]]
        // configureForTesting() REPLACES any prior config, so each test gets a
        // clean blob. Because the page is rendered in the same process by
        // $this->get('/'), this mock applies to the route's SDK reads.
        configureForTesting([
            'flags' => [
                self::FLAG_KEY => true,
            ],
            'configs' => [
                self::CONFIG_KEY => self::CONFIG_VALUE,
            ],
            'experiments' => [
                self::EXPERIMENT_KEY => [self::EXPERIMENT_GROUP, self::EXPERIMENT_PARAMS],
            ],
        ]);
    }

    public function test_testing_mock_is_in_effect_for_the_bound_client(): void
    {
        // Sanity check on the SDK side: reads through the bound Client return the
        // mocked values. This proves configureForTesting() is wired correctly and
        // is independent of whether the example route is using the SDK yet.
        $client = new Client(['user_id' => 'u_123']);

        $this->assertTrue(
            $client->getFlag(self::FLAG_KEY),
            'Mocked feature flag should read true through the bound Client.',
        );

        $this->assertSame(
            self::CONFIG_VALUE,
            $client->getConfig(self::CONFIG_KEY),
            'Mocked dynamic config should read back through the bound Client.',
        );

        $experiment = $client->getExperiment(
            self::EXPERIMENT_KEY,
            ['color' => '#888', 'label' => 'Buy'], // defaultParams
        );

        $this->assertTrue($experiment->inExperiment);
        $this->assertSame(self::EXPERIMENT_GROUP, $experiment->group);
        $this->assertSame(self::EXPERIMENT_PARAMS, $experiment->params);
    }

    public function test_guide_page_loads_in_process(): void
    {
        // The page boots and renders standalone (no DB, no network).
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('Feature Flag', escape: false);
        $response->assertSee('Dynamic Config', escape: false);
        $response->assertSee('A/B Experiment', escape: false);
    }

    public function test_guide_page_renders_the_mocked_feature_flag(): void
    {
        $response = $this->get('/');
        $response->assertOk();

        // The flag key + its mocked-true value should appear on the page once
        // the route reads getFlag('new_checkout') through the SDK.
        $response->assertSee(self::FLAG_KEY, escape: false);
        $response->assertSee('true', escape: false);
    }

    public function test_guide_page_renders_the_mocked_dynamic_config(): void
    {
        $response = $this->get('/');
        $response->assertOk();

        $response->assertSee(self::CONFIG_KEY, escape: false);
        // Mocked sentinel config values — NOT the route's current placeholders,
        // so these FAIL until the route reads getConfig('billing_copy').
        // escape:false because the headline contains a multibyte emoji.
        $response->assertSee(self::CONFIG_VALUE['headline'], escape: false);
        $response->assertSee(self::CONFIG_VALUE['cta'], escape: false);
    }

    public function test_guide_page_renders_the_mocked_experiment(): void
    {
        $response = $this->get('/');
        $response->assertOk();

        $response->assertSee(self::EXPERIMENT_KEY, escape: false);
        $response->assertSee(self::EXPERIMENT_GROUP, escape: false);
        $response->assertSee(self::EXPERIMENT_PARAMS['color'], escape: false);
        $response->assertSee(self::EXPERIMENT_PARAMS['label'], escape: false);
    }
}
