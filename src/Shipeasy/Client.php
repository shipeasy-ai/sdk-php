<?php

declare(strict_types=1);

namespace Shipeasy;

final class Client
{
    private const DEFAULT_BASE_URL = 'https://edge.shipeasy.dev';

    private string $apiKey;
    private string $baseUrl;
    private ?array $flagsBlob = null;
    private ?array $expsBlob = null;
    private ?string $flagsEtag = null;
    private ?string $expsEtag = null;
    private bool $initialized = false;
    private bool $localMode = false;
    private Telemetry $telemetry;

    /** @var array<string, bool> */
    private array $flagOverrides = [];
    /** @var array<string, mixed> */
    private array $configOverrides = [];
    /** @var array<string, array{group: string, params: mixed}> */
    private array $experimentOverrides = [];

    public function __construct(
        string $apiKey,
        ?string $baseUrl = null,
        string $env = 'prod',
        bool $disableTelemetry = false,
        ?string $telemetryUrl = null
    ) {
        $this->apiKey = $apiKey;
        $this->baseUrl = rtrim($baseUrl ?? self::DEFAULT_BASE_URL, '/');
        // Per-evaluation usage telemetry. ON by default; pass
        // disableTelemetry: true to opt out. See Telemetry.php.
        $this->telemetry = new Telemetry(
            $telemetryUrl ?? Telemetry::DEFAULT_TELEMETRY_URL,
            $apiKey,
            'server',
            $env,
            $disableTelemetry
        );
    }

    /**
     * Build a no-network client for tests. Telemetry is disabled, no API key is
     * required, the client is marked initialized so init()/initOnce() never
     * fetch, and track() is a no-op. Seed evaluations with overrideFlag(),
     * overrideConfig(), and overrideExperiment(). Mirrors the cross-SDK test
     * utility contract (Statsig-style local overrides).
     */
    public static function forTesting(): self
    {
        $c = new self('', null, 'prod', true);
        $c->localMode = true;
        $c->initialized = true;
        return $c;
    }

    /**
     * PHP-FPM style: fetch once per request, no background timer. Long-running
     * runtimes (Swoole, RoadRunner, CLI workers) should call this from a
     * scheduled task instead — PHP has no thread-based polling primitive.
     */
    public function initOnce(): void
    {
        if ($this->localMode) return; // test mode never fetches
        if ($this->initialized) return;
        $this->fetchAll();
        $this->initialized = true;
    }

    /** Alias for initOnce(). PHP has no background poll. */
    public function init(): void { $this->initOnce(); }

    public function destroy(): void { /* no-op */ }

    /**
     * Force getFlag($name) to return $value, regardless of the fetched blob.
     * Usable on any client; primarily for tests. Clear with clearOverrides().
     */
    public function overrideFlag(string $name, bool $value): void
    {
        $this->flagOverrides[$name] = $value;
    }

    /** Force getConfig($name) to return $value. */
    public function overrideConfig(string $name, mixed $value): void
    {
        $this->configOverrides[$name] = $value;
    }

    /**
     * Force getExperiment($name) to return an inExperiment result with the given
     * group and params (params take precedence over the call's defaultParams).
     */
    public function overrideExperiment(string $name, string $group, mixed $params): void
    {
        $this->experimentOverrides[$name] = ['group' => $group, 'params' => $params];
    }

    /** Drop all flag/config/experiment overrides. */
    public function clearOverrides(): void
    {
        $this->flagOverrides = [];
        $this->configOverrides = [];
        $this->experimentOverrides = [];
    }

    public function getFlag(string $name, array $user): bool
    {
        if (array_key_exists($name, $this->flagOverrides)) {
            return $this->flagOverrides[$name];
        }
        $this->telemetry->emit('gate', $name);
        return Eval_::evalGate($this->flagsBlob['gates'][$name] ?? null, self::withAnonId($user));
    }

    /**
     * Default anonymous_id to the request's __se_anon_id (resolved by
     * Identity::ensure()) when the caller passed no explicit unit. A
     * caller-supplied user_id/anonymous_id always wins; a no-op if ensure()
     * never ran.
     */
    private static function withAnonId(array $user): array
    {
        $hasUnit = (isset($user['user_id']) && $user['user_id'] !== '')
            || (isset($user['anonymous_id']) && $user['anonymous_id'] !== '');
        if (!$hasUnit && ($anon = Identity::current()) !== null) {
            $user['anonymous_id'] = $anon;
        }
        return $user;
    }

    public function getConfig(string $name): mixed
    {
        if (array_key_exists($name, $this->configOverrides)) {
            return $this->configOverrides[$name];
        }
        $this->telemetry->emit('config', $name);
        return $this->flagsBlob['configs'][$name]['value'] ?? null;
    }

    public function getExperiment(string $name, array $user, mixed $defaultParams): ExperimentResult
    {
        if (isset($this->experimentOverrides[$name])) {
            $o = $this->experimentOverrides[$name];
            return new ExperimentResult(true, $o['group'], $o['params']);
        }
        $this->telemetry->emit('experiment', $name);
        $exp = $this->expsBlob['experiments'][$name] ?? null;
        $r = Eval_::evalExperiment($exp, $this->flagsBlob, $this->expsBlob, self::withAnonId($user));
        if ($r->params === null) {
            return new ExperimentResult($r->inExperiment, $r->group, $defaultParams);
        }
        return $r;
    }

    public function track(string $userId, string $eventName, array $properties = []): void
    {
        if ($this->localMode) return; // test mode never sends events
        $event = [
            'type' => 'metric',
            'event_name' => $eventName,
            'user_id' => $userId,
            'ts' => (int) (microtime(true) * 1000),
        ];
        if (!empty($properties)) $event['properties'] = $properties;
        $body = json_encode(['events' => [$event]], JSON_UNESCAPED_UNICODE);
        $this->postNonBlocking('/collect', $body);
    }

    private function fetchAll(): void
    {
        [$flagsStatus, $flagsHeaders, $flagsBody] = $this->httpGet('/sdk/flags', $this->flagsEtag);
        if ($flagsStatus === 200) {
            if (!empty($flagsHeaders['etag'])) $this->flagsEtag = $flagsHeaders['etag'];
            $this->flagsBlob = json_decode($flagsBody, true);
        } elseif ($flagsStatus !== 304) {
            throw new \RuntimeException("/sdk/flags: $flagsStatus");
        }

        [$expsStatus, $expsHeaders, $expsBody] = $this->httpGet('/sdk/experiments', $this->expsEtag);
        if ($expsStatus === 200) {
            if (!empty($expsHeaders['etag'])) $this->expsEtag = $expsHeaders['etag'];
            $this->expsBlob = json_decode($expsBody, true);
        } elseif ($expsStatus !== 304) {
            throw new \RuntimeException("/sdk/experiments: $expsStatus");
        }
    }

    private function httpGet(string $path, ?string $etag): array
    {
        $ch = curl_init($this->baseUrl . $path);
        $headers = ['X-SDK-Key: ' . $this->apiKey];
        if ($etag !== null) $headers[] = 'If-None-Match: ' . $etag;
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
        ]);
        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException("GET $path: $err");
        }
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $headerStr = substr($raw, 0, $headerSize);
        $body = substr($raw, $headerSize);
        curl_close($ch);

        $parsed = [];
        foreach (preg_split('/\r?\n/', $headerStr) as $line) {
            if (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $parsed[strtolower(trim($k))] = trim($v);
            }
        }
        return [$status, $parsed, $body];
    }

    private function postNonBlocking(string $path, string $body): void
    {
        $ch = curl_init($this->baseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'X-SDK-Key: ' . $this->apiKey,
                'Content-Type: text/plain',
            ],
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT => 5,
        ]);
        @curl_exec($ch);
        curl_close($ch);
    }
}
