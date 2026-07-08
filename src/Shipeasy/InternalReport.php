<?php

declare(strict_types=1);

namespace Shipeasy;

/**
 * Internal self-monitoring channel — SDK bugs that are "on our end".
 *
 * When the SDK swallows one of its OWN internal errors (the per-read fail-safe
 * guards in {@see Engine::getFlag()} / getFlagDetail() / getConfig() /
 * getKillswitch() / getExperiment(), which keep a runtime read from throwing
 * into product code even when an internal invariant is violated), it ALSO ships
 * a structured see event here — to Shipeasy's OWN project, NOT the consumer's —
 * so the SDK team can track SDK-internal failures across every app the SDK runs
 * in.
 *
 * This is deliberately distinct from the customer-facing see() path
 * ({@see Engine::see()} / {@see dispatchSee()}), which authenticates with the
 * consumer's key and lands in the consumer's Errors tab. Internal errors must
 * never pollute a customer's dashboard, and the SDK team must see them
 * centrally — so this channel has its own baked-in destination + credential.
 *
 * Guarantees (identical to telemetry/see): fire-and-forget, never blocks, never
 * throws into product code, deduped/rate-limited via {@see SeeLimiter}. A failed
 * send is swallowed silently — it must NOT log (that would risk recursion back
 * through the guard).
 */
final class InternalReport
{
    // ---- Baked-in destination ----
    //
    // The main Shipeasy project. The credential is a PUBLIC client key — the
    // same class of credential already embedded verbatim in every browser bundle
    // that ships the client SDK, and mirroring how the CLI bakes Shipeasy's own
    // public key for setup-bug self-reporting — so baking it into the published
    // package is safe. /collect treats it as a write-only ingest key; it grants
    // no read access. The canonical ingest host is api.shipeasy.ai (the SDK
    // default baseUrl), which routes /collect to the edge worker.
    public const INGEST_URL = 'https://api.shipeasy.ai/collect';

    // Sentinel used until the real key is minted + baked. While INGEST_KEY is
    // still the placeholder the channel stays fully inert (see report()), so a
    // build that ships before the key is provisioned never fires doomed
    // requests. Mint the key with:
    //   shipeasy keys create --type client --env prod \
    //     --name "SDK internal error self-reporting" --scopes events:write
    // then replace $ingestKey's initializer below with the returned value.
    public const PLACEHOLDER_KEY = 'sdk_client_REPLACE_WITH_SHIPEASY_INTERNAL_ERROR_KEY';

    /**
     * The baked-in ingest credential. Swap this initializer for the real minted
     * public client key. While it equals PLACEHOLDER_KEY the channel is inert.
     */
    private static string $ingestKey = self::PLACEHOLDER_KEY;

    /**
     * Stable consequence. The $label (the guard's operation name, e.g.
     * "getFlag") is the subject; the outcome is fixed. Both are constant per
     * operation — no variable data — so occurrences of the same internal bug
     * fold into one issue on our dashboard (fingerprint = error_type +
     * normalized message + top stack + subject|outcome).
     */
    public const OUTCOME = 'returned a safe default';

    /** Marks which language SDK reported the internal error. */
    public const SDK_ID = 'php';

    /** Set once per process from {@see Engine::__construct()}. Null until then. */
    private static ?string $side = null;
    private static string $sdkVersion = '';
    private static bool $enabled = false;

    /** Bounds network chatter from a hot internal-error loop. */
    private static ?SeeLimiter $limiter = null;

    /**
     * Test seam: a swappable sender. When set, report() routes the wire body to
     * this closure instead of the real non-blocking HTTP POST, so specs assert
     * the destination/key/body without real network. Signature:
     * fn(string $url, string $key, string $body): void.
     *
     * @var \Closure|null
     */
    private static ?\Closure $sender = null;

    /**
     * Wire the self-monitoring channel. Called from the Engine constructor with
     * the bundle's side + version. $enabled defaults on; it is forced off in
     * local/test mode (no network) and when the caller opts out via
     * `disableInternalErrorReporting`.
     */
    public static function setContext(string $side, string $sdkVersion, bool $enabled = true): void
    {
        self::$side = $side;
        self::$sdkVersion = $sdkVersion;
        self::$enabled = $enabled;
        if (self::$limiter === null) {
            self::$limiter = new SeeLimiter();
        }
    }

    /** True once a real key has been baked in (not the placeholder sentinel). */
    private static function keyConfigured(): bool
    {
        return self::$ingestKey !== '' && self::$ingestKey !== self::PLACEHOLDER_KEY;
    }

    /**
     * Report an SDK-internal error to Shipeasy's own project. Called from a
     * runtime read's fail-safe catch. $label is the swallowed operation (e.g.
     * "getFlag") and becomes the stable issue subject. Never throws.
     */
    public static function report(string $label, \Throwable $err): void
    {
        try {
            if (self::$side === null || !self::$enabled || !self::keyConfigured()) {
                return;
            }
            $ev = See::buildEvent(
                $err,
                $label,
                self::OUTCOME,
                ['sdk' => self::SDK_ID],
                self::$side,
                self::$sdkVersion,
                null // no consumer env — this is SDK-side context only
            );
            $limiter = self::$limiter ?? (self::$limiter = new SeeLimiter());
            if (!$limiter->shouldSend($ev)) {
                return;
            }
            $body = json_encode(['events' => [$ev]], JSON_UNESCAPED_UNICODE);
            if ($body === false) {
                return;
            }
            self::send(self::INGEST_URL, self::$ingestKey, $body);
        } catch (\Throwable) {
            // Self-reporting must never throw into product code. Do NOT log —
            // logging could recurse back through the fail-safe guard.
        }
    }

    /**
     * Fire-and-forget POST to /collect with the baked ingest key. text/plain
     * matches the SDK's existing /collect posts (the worker reads the raw body
     * as JSON). Never blocks meaningfully; never surfaces a network error.
     */
    private static function send(string $url, string $key, string $body): void
    {
        if (self::$sender !== null) {
            (self::$sender)($url, $key, $body);
            return;
        }
        try {
            $ch = curl_init($url);
            if ($ch === false) {
                return;
            }
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'X-SDK-Key: ' . $key,
                    'Content-Type: text/plain',
                ],
                CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_TIMEOUT => 5,
            ]);
            @curl_exec($ch);
            curl_close($ch);
        } catch (\Throwable) {
            // Self-reporting must never surface a network error.
        }
    }

    // ---- Test seams ----

    /**
     * Route report() sends through $sender instead of real HTTP, so specs can
     * capture the (url, key, body) without network. Pass null to restore the
     * real sender.
     */
    public static function setSenderForTest(?\Closure $sender): void
    {
        self::$sender = $sender;
    }

    /**
     * Stand in a real-looking key so specs can exercise the send path without
     * the (deliberately inert) placeholder blocking it.
     */
    public static function setIngestKeyForTest(string $key): void
    {
        self::$ingestKey = $key;
    }

    /**
     * Reset module state (context + rate limiter + key + sender) so a spec
     * starts from a clean, inert channel.
     */
    public static function resetForTest(): void
    {
        self::$side = null;
        self::$sdkVersion = '';
        self::$enabled = false;
        self::$limiter = new SeeLimiter();
        self::$ingestKey = self::PLACEHOLDER_KEY;
        self::$sender = null;
    }
}
