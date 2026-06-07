<?php

declare(strict_types=1);

namespace Shipeasy;

/**
 * Per-evaluation usage telemetry. Fires one fire-and-forget HTTP beacon per
 * evaluation so usage is counted by Cloudflare's native per-path analytics.
 * Mirrors the contract in the TypeScript reference SDK and
 * experiment-platform/15-usage-metering.md. The path carries sha256(apiKey) --
 * never the raw key -- plus side/env, then feature/resource. The 2s dedup
 * window bounds volume. PHP has no threads, so the beacon uses a socket
 * write-and-close (connect, write request, close without reading the response).
 */
final class Telemetry
{
    public const DEFAULT_TELEMETRY_URL = 'https://t.shipeasy.ai';

    private bool $disabled;
    private int $dedupeMs;
    private string $prefix = '';
    /** @var array<string, float> */
    private array $last = [];

    public function __construct(
        string $endpoint,
        string $sdkKey,
        string $side = 'server',
        string $env = 'prod',
        bool $disabled = false,
        int $dedupeMs = 2000
    ) {
        $endpoint = rtrim($endpoint, '/');
        $this->disabled = $disabled || $sdkKey === '' || $endpoint === '';
        $this->dedupeMs = $dedupeMs;
        if (!$this->disabled) {
            $this->prefix = $endpoint . '/t/' . hash('sha256', $sdkKey) . '/' . $side . '/' . rawurlencode($env);
        }
    }

    public function emit(string $feature, string $resource): void
    {
        if ($this->disabled) {
            return;
        }
        if ($this->dedupeMs > 0) {
            $key = $feature . '/' . $resource;
            $now = microtime(true) * 1000.0;
            if (isset($this->last[$key]) && ($now - $this->last[$key]) < $this->dedupeMs) {
                return;
            }
            $this->last[$key] = $now;
        }
        $this->dispatch($this->prefix . '/' . $feature . '/' . rawurlencode($resource));
    }

    /**
     * Fire-and-forget HTTP GET (socket write-and-close). Protected so tests can
     * subclass and capture the URL without real network.
     */
    protected function dispatch(string $url): void
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['host'])) {
            return;
        }
        $https = ($parts['scheme'] ?? 'https') === 'https';
        $host = $parts['host'];
        $port = $parts['port'] ?? ($https ? 443 : 80);
        $path = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
        $target = ($https ? 'ssl://' : '') . $host;

        $fp = @fsockopen($target, $port, $errno, $errstr, 0.3);
        if ($fp === false) {
            return; // telemetry must never affect the caller
        }
        $req = "GET {$path} HTTP/1.1\r\nHost: {$host}\r\nConnection: Close\r\n\r\n";
        @fwrite($fp, $req);
        @fclose($fp);
    }
}
