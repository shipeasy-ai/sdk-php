<?php

declare(strict_types=1);

namespace Shipeasy\Admin;

use GuzzleHttp\Client as GuzzleClient;
use Shipeasy\Admin\Generated\Configuration;
use Shipeasy\Admin\Generated\Api\FlagsApi;
use Shipeasy\Admin\Generated\Api\KillswitchApi;
use Shipeasy\Admin\Generated\Api\OpsApi;

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
 * This is the LEAN admin surface: the vendored spec is the dedicated server-SDK
 * contract (marketplace/openapi/spec/openapi-sdk.yaml in the monorepo), seven
 * operations across three capabilities — file a public ticket ({@see ops()}),
 * toggle a kill switch ({@see killswitch()}), manage a flag's whitelist
 * ({@see flags()}). The rest of the admin API (experiments, metrics, events,
 * configs, i18n, projects, …) is intentionally NOT here; reach it through the
 * Shipeasy CLI or MCP, which consume the complete spec.
 *
 * Two of the seven operations — `ops()->createPublicBug()` and
 * `ops()->createPublicFeatureRequest()` — are the PUBLIC ticket intake. They live
 * on the Shipeasy edge worker and authenticate with a CLIENT key (`X-SDK-Key`),
 * not the admin key, so pass `$clientKey` if you want to call them. The
 * generated client routes them to the edge host on its own.
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
 *     $admin->ops()->createPublicBug(['title' => 'Checkout 500s on Safari']);
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
     * @param string      $host      Admin API base URL. Defaults to `https://shipeasy.ai`
     *                               (the spec's production server); use
     *                               `http://localhost:3000` for local dev. The public
     *                               intake ignores it — it carries its own server list.
     * @param string|null $clientKey Client SDK key (`sdk_client_…`) carrying the
     *                               `tickets:public_create` scope, sent as `X-SDK-Key`
     *                               on the two public ticket operations. Optional.
     */
    public function __construct(
        string $apiKey,
        ?string $projectId = null,
        string $host = 'https://shipeasy.ai',
        ?string $clientKey = null
    ) {
        $this->config = (new Configuration())
            ->setAccessToken($apiKey)
            ->setHost($host);
        if ($clientKey !== null && $clientKey !== '') {
            $this->config->setApiKey('clientSdkKey', $clientKey);
        }

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

    public function flags(): FlagsApi
    {
        return $this->api(FlagsApi::class);
    }

    public function killswitch(): KillswitchApi
    {
        return $this->api(KillswitchApi::class);
    }

    public function ops(): OpsApi
    {
        return $this->api(OpsApi::class);
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
