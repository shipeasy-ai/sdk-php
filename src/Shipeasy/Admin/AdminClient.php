<?php

declare(strict_types=1);

namespace Shipeasy\Admin;

use GuzzleHttp\Client as GuzzleClient;
use Shipeasy\Admin\Generated\Configuration;
use Shipeasy\Admin\Generated\Api\AlertRulesApi;
use Shipeasy\Admin\Generated\Api\AttributesApi;
use Shipeasy\Admin\Generated\Api\ConfigsApi;
use Shipeasy\Admin\Generated\Api\EventsApi;
use Shipeasy\Admin\Generated\Api\ExperimentsApi;
use Shipeasy\Admin\Generated\Api\GatesApi;
use Shipeasy\Admin\Generated\Api\I18nApi;
use Shipeasy\Admin\Generated\Api\KillswitchesApi;
use Shipeasy\Admin\Generated\Api\MetricsApi;
use Shipeasy\Admin\Generated\Api\OpsApi;
use Shipeasy\Admin\Generated\Api\ProjectsApi;
use Shipeasy\Admin\Generated\Api\UniversesApi;

/**
 * The {@see AdminClient} entry point for the OPTIONAL Admin API client.
 *
 * This is the only hand-written file in `Shipeasy\Admin` — everything under
 * `Shipeasy\Admin\Generated` is produced by `scripts/gen_admin.sh` from the
 * vendored OpenAPI spec and must not be edited by hand. `AdminClient` is a thin
 * auth/scoping wrapper over the generated api classes; it does NOT add name->id
 * resolution or percent->basis-point conversion (that facade lives in the
 * Shipeasy CLI/MCP). The surface here is the raw, 1:1-with-the-spec REST API.
 *
 * Requires `guzzlehttp/guzzle` — declared in composer `suggest`, NOT a hard
 * dependency of the base SDK. Install it to use the admin client:
 *
 *     composer require guzzlehttp/guzzle
 *
 * Usage:
 *
 *     $admin = new \Shipeasy\Admin\AdminClient(
 *         getenv('SHIPEASY_ADMIN_KEY'),
 *         getenv('SHIPEASY_PROJECT_ID'),
 *     );
 *     $gates = $admin->gates()->listGates();
 */
final class AdminClient
{
    private Configuration $config;
    private GuzzleClient $http;

    /** @var array<string, object> lazily-constructed api instances */
    private array $cache = [];

    /**
     * @param string      $apiKey    Admin SDK key, sent as `Authorization: Bearer <apiKey>`.
     * @param string|null $projectId Optional project id sent as the `X-Project-Id`
     *                               header on every request (the per-request scoping the
     *                               API expects). Operations also accept an explicit
     *                               `$x_project_id` argument to override per call.
     * @param string      $host      API base URL. Defaults to `https://shipeasy.ai`
     *                               (the spec's production server); use
     *                               `http://localhost:3000` for local dev.
     */
    public function __construct(string $apiKey, ?string $projectId = null, string $host = 'https://shipeasy.ai')
    {
        $this->config = (new Configuration())
            ->setAccessToken($apiKey)
            ->setHost($host);

        $headers = [];
        if ($projectId !== null && $projectId !== '') {
            $headers['X-Project-Id'] = $projectId;
        }
        $this->http = new GuzzleClient(['headers' => $headers]);
    }

    /** The underlying generated {@see Configuration} (advanced/escape hatch). */
    public function configuration(): Configuration
    {
        return $this->config;
    }

    public function gates(): GatesApi
    {
        return $this->api(GatesApi::class);
    }

    public function configs(): ConfigsApi
    {
        return $this->api(ConfigsApi::class);
    }

    public function killswitches(): KillswitchesApi
    {
        return $this->api(KillswitchesApi::class);
    }

    public function experiments(): ExperimentsApi
    {
        return $this->api(ExperimentsApi::class);
    }

    public function universes(): UniversesApi
    {
        return $this->api(UniversesApi::class);
    }

    public function metrics(): MetricsApi
    {
        return $this->api(MetricsApi::class);
    }

    public function events(): EventsApi
    {
        return $this->api(EventsApi::class);
    }

    public function alertRules(): AlertRulesApi
    {
        return $this->api(AlertRulesApi::class);
    }

    public function attributes(): AttributesApi
    {
        return $this->api(AttributesApi::class);
    }

    public function projects(): ProjectsApi
    {
        return $this->api(ProjectsApi::class);
    }

    public function ops(): OpsApi
    {
        return $this->api(OpsApi::class);
    }

    public function i18n(): I18nApi
    {
        return $this->api(I18nApi::class);
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    private function api(string $class): object
    {
        if (!isset($this->cache[$class])) {
            /** @var T */
            $this->cache[$class] = new $class($this->http, $this->config);
        }

        /** @var T */
        return $this->cache[$class];
    }
}
