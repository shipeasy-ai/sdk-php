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

    public function __construct(string $apiKey, ?string $baseUrl = null)
    {
        $this->apiKey = $apiKey;
        $this->baseUrl = rtrim($baseUrl ?? self::DEFAULT_BASE_URL, '/');
    }

    /**
     * PHP-FPM style: fetch once per request, no background timer. Long-running
     * runtimes (Swoole, RoadRunner, CLI workers) should call this from a
     * scheduled task instead — PHP has no thread-based polling primitive.
     */
    public function initOnce(): void
    {
        if ($this->initialized) return;
        $this->fetchAll();
        $this->initialized = true;
    }

    /** Alias for initOnce(). PHP has no background poll. */
    public function init(): void { $this->initOnce(); }

    public function destroy(): void { /* no-op */ }

    public function getFlag(string $name, array $user): bool
    {
        return Eval_::evalGate($this->flagsBlob['gates'][$name] ?? null, $user);
    }

    public function getConfig(string $name): mixed
    {
        return $this->flagsBlob['configs'][$name]['value'] ?? null;
    }

    public function getExperiment(string $name, array $user, mixed $defaultParams): ExperimentResult
    {
        $exp = $this->expsBlob['experiments'][$name] ?? null;
        $r = Eval_::evalExperiment($exp, $this->flagsBlob, $this->expsBlob, $user);
        if ($r->params === null) {
            return new ExperimentResult($r->inExperiment, $r->group, $defaultParams);
        }
        return $r;
    }

    public function track(string $userId, string $eventName, array $properties = []): void
    {
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
