<?php

declare(strict_types=1);

namespace Shipeasy;

class Client
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

    /**
     * Attribute names usable for targeting but never persisted in analytics
     * (LD/Statsig `privateAttributes`). The server evaluates locally, so these
     * never leave for evaluation at all; the only egress is /collect, and the
     * listed keys are stripped from every outbound track() payload.
     *
     * @var array<int, string>
     */
    private array $privateAttributes = [];

    /** Optional sticky-bucketing store; absent ⇒ deterministic eval. */
    private ?StickyBucketStore $stickyStore = null;

    /** @var array<string, bool> */
    private array $flagOverrides = [];
    /** @var array<string, mixed> */
    private array $configOverrides = [];
    /** @var array<string, array{group: string, params: mixed}> */
    private array $experimentOverrides = [];

    /** @var array<int, callable> change listeners, keyed by registration id */
    private array $changeListeners = [];
    private int $nextListenerId = 0;

    /**
     * @param array<int, string> $privateAttributes Attribute names stripped from
     *        every outbound track() payload (LD/Statsig private attributes).
     * @param StickyBucketStore|null $stickyStore Sticky-bucketing store; when
     *        supplied, getExperiment() locks a unit to its first-assigned
     *        variant (changing allocation %/weights won't re-bucket an enrolled
     *        unit — changing the experiment salt is the reshuffle lever). Absent
     *        ⇒ deterministic (fully backward compatible).
     */
    public function __construct(
        string $apiKey,
        ?string $baseUrl = null,
        string $env = 'prod',
        bool $disableTelemetry = false,
        ?string $telemetryUrl = null,
        array $privateAttributes = [],
        ?StickyBucketStore $stickyStore = null
    ) {
        $this->apiKey = $apiKey;
        $this->baseUrl = rtrim($baseUrl ?? self::DEFAULT_BASE_URL, '/');
        $this->privateAttributes = array_values($privateAttributes);
        $this->stickyStore = $stickyStore;
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
    public static function forTesting(?StickyBucketStore $stickyStore = null): self
    {
        $c = new self('', null, 'prod', true, null, [], $stickyStore);
        $c->localMode = true;
        $c->initialized = true;
        return $c;
    }

    /**
     * Build an offline client backed by a JSON snapshot file. The file holds
     * `{ "flags": <body of /sdk/flags>, "experiments": <body of /sdk/experiments> }`.
     * The returned client never touches the network: init()/initOnce()/track()
     * are no-ops and telemetry is off, but evaluations run the real eval against
     * the snapshot (overrides apply on top). Useful for edge/air-gapped hosts
     * that ship a baked blob, or for reproducible CI.
     */
    public static function fromFile(string $path, ?StickyBucketStore $stickyStore = null): self
    {
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException("fromFile: cannot read $path");
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new \RuntimeException("fromFile: invalid JSON in $path");
        }
        $flags = is_array($data['flags'] ?? null) ? $data['flags'] : [];
        $experiments = is_array($data['experiments'] ?? null) ? $data['experiments'] : [];
        return self::fromSnapshot($flags, $experiments, $stickyStore);
    }

    /**
     * Build an offline client from already-decoded blobs. $flags is the body of
     * /sdk/flags (with `gates`/`configs`) and $experiments is the body of
     * /sdk/experiments (with `experiments`/`universes`). No network, telemetry
     * off, marked initialized; evaluations run against the snapshot.
     */
    public static function fromSnapshot(array $flags, array $experiments, ?StickyBucketStore $stickyStore = null): self
    {
        $c = new self('', null, 'prod', true, null, [], $stickyStore);
        $c->localMode = true;
        $c->initialized = true;
        $c->flagsBlob = $flags;
        $c->expsBlob = $experiments;
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

    /**
     * Register a callback fired whenever the client refreshes its data with a
     * NEW server response (a 200 from a subsequent initOnce()/refresh(), never a
     * 304 and never in localMode). Returns an unsubscribe callable; call it to
     * remove the listener.
     *
     * NOTE: PHP is request-scoped and this SDK runs no background poll thread,
     * so under classic PHP-FPM the client is rebuilt per request and a listener
     * will not fire on its own. Change listeners are mainly relevant to
     * long-running runtimes (Swoole, RoadRunner, queue/CLI workers) that keep a
     * client alive across requests and call refresh() on a schedule.
     */
    public function onChange(callable $fn): callable
    {
        $id = $this->nextListenerId++;
        $this->changeListeners[$id] = $fn;
        return function () use ($id): void {
            unset($this->changeListeners[$id]);
        };
    }

    /**
     * Re-fetch the blobs and fire change listeners if new data (a 200, not a
     * 304) was applied. No-op in localMode. Long-running hosts call this on a
     * schedule; PHP-FPM hosts typically just rely on per-request init().
     */
    public function refresh(): void
    {
        if ($this->localMode) return;
        $this->fetchAll();
    }

    /** Notify listeners that fetched data changed. Never throws. */
    private function fireChange(): void
    {
        foreach ($this->changeListeners as $fn) {
            try {
                $fn();
            } catch (\Throwable) {
                // A listener must never break a refresh.
            }
        }
    }

    /**
     * Evaluate a flag, returning $default ONLY when the flag cannot be
     * evaluated — i.e. the client is not initialized (CLIENT_NOT_READY) or the
     * gate is not in the blob (FLAG_NOT_FOUND). A flag that evaluates to false
     * (off/no-match) returns false, not the default.
     */
    public function getFlag(string $name, array $user, bool $default = false): bool
    {
        $d = $this->getFlagDetail($name, $user);
        if ($d->reason === FlagDetail::CLIENT_NOT_READY || $d->reason === FlagDetail::FLAG_NOT_FOUND) {
            return $default;
        }
        return $d->value;
    }

    /**
     * Evaluate a flag and report why it resolved that way. The reason is one of
     * the FlagDetail constants. The "gate" telemetry beacon fires exactly once
     * per call (steps 2–5), never for a local override. The reason is computed
     * at this boundary without changing the canonical Eval_::evalGate.
     */
    public function getFlagDetail(string $name, array $user): FlagDetail
    {
        // 1. Override — short-circuit before telemetry, like getFlag's old path.
        if (array_key_exists($name, $this->flagOverrides)) {
            return new FlagDetail($this->flagOverrides[$name], FlagDetail::OVERRIDE);
        }

        $this->telemetry->emit('gate', $name);

        // 2. Not initialized — the blob was never fetched.
        if (!$this->initialized) {
            return new FlagDetail(false, FlagDetail::CLIENT_NOT_READY);
        }

        // 3. Gate not present in the blob.
        $gate = $this->flagsBlob['gates'][$name] ?? null;
        if ($gate === null) {
            return new FlagDetail(false, FlagDetail::FLAG_NOT_FOUND);
        }

        // 4. Gate present but disabled (mirrors evalGate's `enabled` read).
        $enabled = ($gate['enabled'] ?? null) === true || ($gate['enabled'] ?? null) === 1;
        if (!$enabled) {
            return new FlagDetail(false, FlagDetail::OFF);
        }

        // 5. Run the canonical eval; reason follows the result.
        $value = Eval_::evalGate($gate, self::withAnonId($user));
        return new FlagDetail($value, $value ? FlagDetail::RULE_MATCH : FlagDetail::DEFAULT);
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

    /**
     * Read a dynamic config value, returning $default when the config key is
     * absent (not in the blob, or the client is not initialized).
     */
    public function getConfig(string $name, mixed $default = null): mixed
    {
        if (array_key_exists($name, $this->configOverrides)) {
            return $this->configOverrides[$name];
        }
        $this->telemetry->emit('config', $name);
        if (!isset($this->flagsBlob['configs'][$name]) || !array_key_exists('value', $this->flagsBlob['configs'][$name])) {
            return $default;
        }
        return $this->flagsBlob['configs'][$name]['value'];
    }

    public function getExperiment(string $name, array $user, mixed $defaultParams): ExperimentResult
    {
        if (isset($this->experimentOverrides[$name])) {
            $o = $this->experimentOverrides[$name];
            return new ExperimentResult(true, $o['group'], $o['params']);
        }
        $this->telemetry->emit('experiment', $name);
        $exp = $this->expsBlob['experiments'][$name] ?? null;
        $r = Eval_::evalExperiment(
            $exp,
            $this->flagsBlob,
            $this->expsBlob,
            self::withAnonId($user),
            $this->stickyStore,
            $name
        );
        if ($r->params === null) {
            return new ExperimentResult($r->inExperiment, $r->group, $defaultParams);
        }
        return $r;
    }

    public function track(string $userId, string $eventName, array $properties = []): void
    {
        if ($this->localMode) return; // test mode never sends events
        $safeProps = $this->stripPrivate($properties);
        $event = [
            'type' => 'metric',
            'event_name' => $eventName,
            'user_id' => $userId,
            'ts' => (int) (microtime(true) * 1000),
        ];
        if (!empty($safeProps)) $event['properties'] = $safeProps;
        $body = json_encode(['events' => [$event]], JSON_UNESCAPED_UNICODE);
        $this->postNonBlocking('/collect', $body);
    }

    /**
     * Drop caller-marked private attributes from an outbound props bag. Returns
     * the props unchanged when no private attributes are configured.
     *
     * @param array<string, mixed> $props
     * @return array<string, mixed>
     */
    public function stripPrivate(array $props): array
    {
        if ($props === [] || $this->privateAttributes === []) return $props;
        foreach ($this->privateAttributes as $key) {
            unset($props[$key]);
        }
        return $props;
    }

    /**
     * Emit an exposure event for an experiment at the server-side decision point
     * (parity with the browser's auto-exposure). The server is stateless and
     * never auto-logs, so call this when you actually present the treatment.
     * Re-evaluates the experiment for $user (a bare user_id string is wrapped as
     * ['user_id' => …]); if the user is enrolled, POSTs a single exposure to
     * /collect. No-op in local/test mode or when the user isn't enrolled.
     *
     * @param string|array<string, mixed> $user
     */
    public function logExposure(string|array $user, string $experimentName): void
    {
        if ($this->localMode) return; // test mode never sends events
        $u = is_string($user) ? ['user_id' => $user] : $user;
        $result = $this->getExperiment($experimentName, $u, null);
        if (!$result->inExperiment) return;
        $event = [
            'type' => 'exposure',
            'experiment' => $experimentName,
            'group' => $result->group,
            'ts' => (int) (microtime(true) * 1000),
        ];
        if (isset($u['user_id']) && $u['user_id'] !== '') {
            $event['user_id'] = (string) $u['user_id'];
        }
        if (isset($u['anonymous_id']) && $u['anonymous_id'] !== '') {
            $event['anonymous_id'] = (string) $u['anonymous_id'];
        }
        $body = json_encode(['events' => [$event]], JSON_UNESCAPED_UNICODE);
        $this->postNonBlocking('/collect', $body);
    }

    private function fetchAll(): void
    {
        $newFlags = null;
        $newExps = null;
        $changed = false;

        [$flagsStatus, $flagsHeaders, $flagsBody] = $this->httpGet('/sdk/flags', $this->flagsEtag);
        if ($flagsStatus === 200) {
            if (!empty($flagsHeaders['etag'])) $this->flagsEtag = $flagsHeaders['etag'];
            $newFlags = json_decode($flagsBody, true);
            $changed = true;
        } elseif ($flagsStatus !== 304) {
            throw new \RuntimeException("/sdk/flags: $flagsStatus");
        }

        [$expsStatus, $expsHeaders, $expsBody] = $this->httpGet('/sdk/experiments', $this->expsEtag);
        if ($expsStatus === 200) {
            if (!empty($expsHeaders['etag'])) $this->expsEtag = $expsHeaders['etag'];
            $newExps = json_decode($expsBody, true);
            $changed = true;
        } elseif ($expsStatus !== 304) {
            throw new \RuntimeException("/sdk/experiments: $expsStatus");
        }

        if ($changed) {
            $this->applyData($newFlags, $newExps);
        }
    }

    /**
     * Install freshly-fetched blobs and notify change listeners. A null arg
     * means "that blob was not refreshed" (e.g. a 304) — keep the existing one.
     * Called from fetchAll() on any 200; tests drive change-listener firing
     * through this seam without real network.
     *
     * @internal Not part of the public contract — used by fetchAll() and tests.
     */
    public function applyData(?array $flags, ?array $exps): void
    {
        if ($flags !== null) $this->flagsBlob = $flags;
        if ($exps !== null) $this->expsBlob = $exps;
        $this->fireChange();
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

    protected function postNonBlocking(string $path, string $body): void
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
