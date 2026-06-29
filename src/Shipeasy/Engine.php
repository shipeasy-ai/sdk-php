<?php

declare(strict_types=1);

namespace Shipeasy;

/**
 * The heavyweight Shipeasy engine: owns the API key, HTTP/blob cache, fetch,
 * overrides, track() and the see()/default-engine wiring. Construct it directly
 * for full control, or use the {@see configure()} + {@see Client} front door for
 * the ergonomic, user-bound flow.
 *
 * (Renamed from `Client` in 0.8.0 — `Client` is now the lightweight,
 * user-bound handle built via `new Client($user)`.)
 */
class Engine
{
    private const DEFAULT_BASE_URL = 'https://edge.shipeasy.dev';
    // CDN origin serving the static loader scripts (/sdk/bootstrap.js,
    // /sdk/i18n/loader.js) — distinct from the edge API the blobs are fetched from.
    private const DEFAULT_CDN_BASE = 'https://cdn.shipeasy.ai';

    /**
     * The SDK's own version string, sent as `sdk_version` on see() error events.
     * This is the single runtime source of truth — keep it in sync with the
     * `version` field in composer.json (composer exposes no runtime constant).
     */
    public const VERSION = '0.12.0';

    private string $apiKey;
    private string $baseUrl;
    private string $env;
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

    /** Per-process spam guard for see() reports. */
    private SeeLimiter $seeLimiter;

    /**
     * Default engine backing the package-level Shipeasy\see()/seeViolation()/
     * controlFlowException() functions AND the user-bound {@see Client}. Last
     * constructed (or first {@see configure()}'d) wins — the server-SDK analog
     * of TS's shipeasy({key}) configure call.
     */
    private static ?Engine $default = null;

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
        $this->env = $env;
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
        $this->seeLimiter = new SeeLimiter();
        // Register as the default engine backing the package-level see() funcs
        // and the user-bound Client (last constructed wins — the server-SDK
        // analog of TS's shipeasy({key})). configure() uses setDefaultIfAbsent()
        // for first-config-wins idempotency instead.
        self::setDefault($this);
    }

    /**
     * Register the engine backing the package-level Shipeasy\see() functions and
     * the user-bound {@see Client}. Called automatically from the constructor
     * (last wins); also callable explicitly. Mirrors the Python
     * set_default_client / TS shipeasy({key}).
     */
    public static function setDefault(Engine $engine): void
    {
        self::$default = $engine;
    }

    /**
     * Register $engine as the default ONLY if none is set yet (first wins).
     * Used by {@see configure()} for its first-config-wins idempotency.
     */
    public static function setDefaultIfAbsent(Engine $engine): void
    {
        if (self::$default === null) {
            self::$default = $engine;
        }
    }

    /** The current default engine, or null if none has been constructed. */
    public static function getDefault(): ?Engine
    {
        return self::$default;
    }

    /**
     * Process-wide configure: build (first-config-wins) the single global
     * {@see Engine} from $apiKey + engine options, store the optional
     * $attributes transform used by every {@see Client}, and fire the one-shot
     * fetch (initOnce) fire-and-forget so `new Client($user)->getFlag(...)`
     * resolves against real rules without an explicit init call.
     *
     * Long-running runtimes may instead keep the returned Engine and call its
     * init()/refresh() on a schedule.
     *
     * @param string $apiKey The Shipeasy **server** key.
     * @param callable|null $attributes Transform from the customer's own user
     *        object to the Shipeasy attribute map (`['user_id' => ..., ...]`).
     *        Default = identity (the user object IS the attribute map).
     * @param array<string, mixed> $opts Extra Engine options: baseUrl, env,
     *        disableTelemetry, telemetryUrl, privateAttributes, stickyStore.
     */
    public static function configure(string $apiKey, ?callable $attributes = null, array $opts = []): Engine
    {
        // First-config-wins: if an engine was already configured, reuse it and
        // only (re)set the attributes transform when one was supplied.
        $existing = self::getDefault();
        if ($existing !== null) {
            if ($attributes !== null) {
                self::$attributesTransform = $attributes;
            }
            return $existing;
        }

        $engine = new self(
            $apiKey,
            $opts['baseUrl'] ?? null,
            $opts['env'] ?? 'prod',
            (bool) ($opts['disableTelemetry'] ?? false),
            $opts['telemetryUrl'] ?? null,
            $opts['privateAttributes'] ?? [],
            $opts['stickyStore'] ?? null,
        );
        self::setDefaultIfAbsent($engine);
        self::$attributesTransform = $attributes;

        // One-shot fetch, fire-and-forget — never let a transient fetch failure
        // break configure().
        try {
            $engine->initOnce();
        } catch (\Throwable) {
            // Evaluations will return defaults until a later init()/refresh().
        }

        return $engine;
    }

    /**
     * REPLACE the process-wide engine + attributes transform. Unlike
     * {@see configure()} (first-config-wins), the configureFor* siblings replace
     * so a test suite can reconfigure between cases.
     *
     * @param array<string, mixed> $opts seeds: attributes, flags, configs,
     *        experiments (see {@see configureForTesting()}).
     */
    private static function installGlobal(Engine $engine, array $opts): Engine
    {
        foreach (($opts['flags'] ?? []) as $name => $value) {
            $engine->overrideFlag((string) $name, (bool) $value);
        }
        foreach (($opts['configs'] ?? []) as $name => $value) {
            $engine->overrideConfig((string) $name, $value);
        }
        foreach (($opts['experiments'] ?? []) as $name => $spec) {
            // spec is [group, params].
            [$group, $params] = $spec;
            $engine->overrideExperiment((string) $name, $group, $params);
        }
        self::$default = $engine;
        self::$attributesTransform = $opts['attributes'] ?? null;
        return $engine;
    }

    /**
     * Configure Shipeasy in **test mode** — a drop-in sibling of {@see configure()}
     * with no network, ever (no api key needed). Seed the values your code under
     * test should see, then read them through the ordinary `new Client($user)`:
     *
     * ```php
     * Engine::configureForTesting(['flags' => ['new_checkout' => true]]);
     * (new Client(['user_id' => 'u_1']))->getFlag('new_checkout'); // true
     * ```
     *
     * Replaces any previously-configured engine. Seeds: `attributes` (transform),
     * `flags` (`name => bool`), `configs` (`name => value`), `experiments`
     * (`name => [group, params]`).
     *
     * @param array<string, mixed> $opts
     */
    public static function configureForTesting(array $opts = []): self
    {
        return self::installGlobal(self::forTesting($opts['stickyStore'] ?? null), $opts);
    }

    /**
     * Configure Shipeasy **offline** — evaluate the REAL rules from an in-memory
     * snapshot or a JSON file, with no network. Provide exactly one source:
     * `path` (a JSON file `{"flags":...,"experiments":...}`) or `snapshot`
     * (`['flags' => [...], 'experiments' => [...]]`). Optional `flags`/`configs`/
     * `experiments` overrides layer on top. Replaces any previously-configured
     * engine.
     *
     * @param array<string, mixed> $opts
     */
    public static function configureForOffline(array $opts = []): self
    {
        if (isset($opts['path'])) {
            $engine = self::fromFile((string) $opts['path'], $opts['stickyStore'] ?? null);
        } elseif (isset($opts['snapshot']) && is_array($opts['snapshot'])) {
            $snap = $opts['snapshot'];
            $engine = self::fromSnapshot(
                is_array($snap['flags'] ?? null) ? $snap['flags'] : [],
                is_array($snap['experiments'] ?? null) ? $snap['experiments'] : [],
                $opts['stickyStore'] ?? null,
            );
        } else {
            throw new \InvalidArgumentException(
                'configureForOffline requires either a "path" or a "snapshot" option'
            );
        }
        return self::installGlobal($engine, $opts);
    }

    /**
     * The package-global attributes transform applied by every {@see Client}
     * constructor. null ⇒ identity (the user array IS the attribute map).
     *
     * @var callable|null
     */
    private static $attributesTransform = null;

    /**
     * Apply the configured attributes transform to the customer's user object,
     * returning the Shipeasy attribute map. Identity when no transform is set.
     *
     * @param array<string, mixed>|object $user
     * @return array<string, mixed>
     */
    public static function applyAttributes(array|object $user): array
    {
        $fn = self::$attributesTransform;
        $mapped = $fn !== null ? $fn($user) : $user;
        if (is_object($mapped)) {
            $mapped = (array) $mapped;
        }
        if (!is_array($mapped)) {
            throw new \RuntimeException(
                'Shipeasy attributes transform must return an array (attribute map)'
            );
        }
        return $mapped;
    }

    /** @internal Reset global engine + transform. Test-only seam. */
    public static function resetForTesting(): void
    {
        self::$default = null;
        self::$attributesTransform = null;
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
    public static function withBoundAnonId(array $user): array
    {
        return self::withAnonId($user);
    }

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

    /**
     * Read a kill switch's boolean state from the flags blob. Returns the
     * killswitch's top-level `killed` value, or — when $switchKey is supplied —
     * the per-key override under `switches[$switchKey]` (falling back to
     * `killed` when that named switch is absent). Returns false when the
     * killswitch is not in the blob or the engine is not initialized.
     *
     * Kill switches ship in the flags blob's `killswitches` map (the published
     * `@shipeasy/sdk` reads top-level `killed`/`switches`); their effect is also
     * folded into gate evaluation, so getFlag already honours an active kill.
     */
    public function getKillswitch(string $name, ?string $switchKey = null): bool
    {
        if (!$this->initialized) {
            return false;
        }
        $entry = $this->flagsBlob['killswitches'][$name] ?? null;
        if (!is_array($entry)) {
            return false;
        }
        if ($switchKey !== null && is_array($entry['switches'] ?? null)
            && array_key_exists($switchKey, $entry['switches'])) {
            return (bool) $entry['switches'][$switchKey];
        }
        return (bool) ($entry['killed'] ?? false);
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

    /**
     * Batch-evaluate every loaded gate, config and experiment for $user into a
     * bootstrap payload (`['flags' => ..., 'configs' => ..., 'experiments' =>
     * ..., 'killswitches' => ...]`) keyed to match the browser SDK's
     * window.__SE_BOOTSTRAP shape. Local overrides win. Killswitches are folded
     * into per-gate evaluation, so the standalone `killswitches` map is empty
     * for this SDK. No telemetry (a batch evaluate is not a per-flag exposure).
     */
    public function evaluate(array $user): array
    {
        $user = self::withAnonId($user);

        $flags = [];
        foreach (($this->flagsBlob['gates'] ?? []) as $name => $gate) {
            $flags[$name] = array_key_exists($name, $this->flagOverrides)
                ? $this->flagOverrides[$name]
                : Eval_::evalGate($gate, $user);
        }

        $configs = [];
        foreach (($this->flagsBlob['configs'] ?? []) as $name => $entry) {
            $configs[$name] = array_key_exists($name, $this->configOverrides)
                ? $this->configOverrides[$name]
                : ($entry['value'] ?? null);
        }

        $experiments = [];
        foreach (($this->expsBlob['experiments'] ?? []) as $name => $exp) {
            if (isset($this->experimentOverrides[$name])) {
                $o = $this->experimentOverrides[$name];
                $experiments[$name] = [
                    'inExperiment' => true,
                    'group' => $o['group'],
                    'params' => $o['params'],
                ];
                continue;
            }
            $r = Eval_::evalExperiment(
                $exp,
                $this->flagsBlob,
                $this->expsBlob,
                $user,
                $this->stickyStore,
                $name
            );
            $experiments[$name] = [
                'inExperiment' => $r->inExperiment,
                'group' => $r->group,
                'params' => $r->params,
            ];
        }

        return [
            'flags' => (object) $flags,
            'configs' => (object) $configs,
            'experiments' => (object) $experiments,
            'killswitches' => (object) [],
        ];
    }

    /**
     * Return the cross-platform SSR bootstrap <script> tag for a request:
     * se-bootstrap.js reads its data-* attributes and hydrates
     * window.__SE_BOOTSTRAP (and writes the anon cookie). No key is embedded.
     *
     * $opts: ['anonId' => string, 'i18nProfile' => 'en:prod', 'baseUrl' => '...'].
     */
    public function bootstrapScriptTag(array $user, array $opts = []): string
    {
        $payload = $this->evaluate($user);
        $base = self::cdnBase($opts['baseUrl'] ?? null);
        $profile = $opts['i18nProfile'] ?? 'en:prod';
        $attrs = [
            'data-se-bootstrap',
            self::attr('data-flags', json_encode($payload['flags'])),
            self::attr('data-configs', json_encode($payload['configs'])),
            self::attr('data-experiments', json_encode($payload['experiments'])),
            self::attr('data-killswitches', json_encode($payload['killswitches'])),
            self::attr('data-i18n-profile', $profile),
            self::attr('data-api-url', $base),
        ];
        if (!empty($opts['anonId'])) {
            $attrs[] = self::attr('data-anon-id', $opts['anonId']);
        }
        $src = htmlspecialchars($base . '/sdk/bootstrap.js', ENT_QUOTES);
        return '<script src="' . $src . '" ' . implode(' ', $attrs) . '></script>';
    }

    /**
     * Return the i18n loader <script> tag. The loader fetches translations for
     * the profile using the PUBLIC client key (safe to embed in HTML).
     */
    public function i18nScriptTag(string $clientKey, string $profile = 'en:prod', array $opts = []): string
    {
        $base = self::cdnBase($opts['baseUrl'] ?? null);
        $src = htmlspecialchars($base . '/sdk/i18n/loader.js', ENT_QUOTES);
        return '<script src="' . $src . '" '
            . self::attr('data-key', $clientKey) . ' '
            . self::attr('data-profile', $profile) . '></script>';
    }

    private static function cdnBase(?string $override): string
    {
        return rtrim($override ?: self::DEFAULT_CDN_BASE, '/');
    }

    private static function attr(string $name, string $value): string
    {
        return $name . '="' . htmlspecialchars($value, ENT_QUOTES) . '"';
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

    // ---- see() structured error reporting ----

    /**
     * Report a caught throwable (or a thrown non-throwable problem). Fire-and-
     * forget; never blocks or throws into the request path. Terminate with
     * ->to($outcome):
     *
     *     $client->see($e)->causesThe("checkout")->to("use cached prices");
     */
    public function see(mixed $problem): SeeChain
    {
        return new SeeChain($problem, $this->dispatchSee(...));
    }

    /**
     * Report a non-exception problem. The name is a stable fingerprint key —
     * put variable data in ->extras(), never the name.
     */
    public function seeViolation(string $name): SeeChain
    {
        return new SeeChain(new Violation($name), $this->dispatchSee(...));
    }

    /** Mark a throwable as expected control flow — reports nothing. */
    public function controlFlowException(\Throwable $err): ControlFlowChain
    {
        return new ControlFlowChain($err);
    }

    /**
     * Build the wire event and fire-and-forget POST it to /collect. No-op in
     * local/test mode. Spam-guarded. Never raises into caller code.
     *
     * @param array<string, mixed>|null $extras
     */
    private function dispatchSee(mixed $problem, string $subject, string $outcome, ?array $extras): void
    {
        if ($this->localMode) {
            return; // test mode never sends events
        }
        try {
            $safeExtras = $extras !== null ? $this->stripPrivate($extras) : null;
            $ev = See::buildEvent(
                $problem,
                $subject,
                $outcome,
                $safeExtras,
                'server',
                self::VERSION,
                $this->env
            );
            if (!$this->seeLimiter->shouldSend($ev)) {
                return;
            }
            $body = json_encode(['events' => [$ev]], JSON_UNESCAPED_UNICODE);
            $this->postNonBlocking('/collect', $body);
        } catch (\Throwable) {
            // Reporting must never raise into caller code.
        }
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
