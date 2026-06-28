<?php

declare(strict_types=1);

namespace Shipeasy\OpenFeature;

use OpenFeature\implementation\provider\AbstractProvider;
use OpenFeature\implementation\provider\ResolutionDetailsBuilder;
use OpenFeature\implementation\provider\ResolutionError;
use OpenFeature\interfaces\flags\EvaluationContext;
use OpenFeature\interfaces\provider\ErrorCode;
use OpenFeature\interfaces\provider\Reason;
use OpenFeature\interfaces\provider\ResolutionDetails;
use Shipeasy\Engine;
use Shipeasy\FlagDetail;

/**
 * OpenFeature **server** provider for Shipeasy.
 *
 * Lets a PHP app standardized on the CNCF OpenFeature API plug Shipeasy in as
 * the backing provider. Pure adapter over {@see Engine} — no change to
 * evaluation:
 *
 * ```php
 * use OpenFeature\OpenFeatureAPI;
 * use Shipeasy\Engine;
 * use Shipeasy\OpenFeature\ShipeasyProvider;
 *
 * $engine = new Engine($_ENV['SHIPEASY_SERVER_KEY']);
 * $engine->initOnce();
 *
 * $api = OpenFeatureAPI::getInstance();
 * $api->setProvider(new ShipeasyProvider($engine));
 *
 * $of = $api->getClient();
 * $on = $of->getBooleanValue('new_checkout', false, $ctx);
 * ```
 *
 * `open-feature/sdk` (^2.0) is an optional dependency — declared under
 * `suggest`/`require-dev` here, install it in the consuming app.
 *
 * Reason mapping (Shipeasy {@see FlagDetail} → OpenFeature reason / error):
 *   RULE_MATCH       → TARGETING_MATCH
 *   DEFAULT          → DEFAULT
 *   OFF              → DISABLED
 *   OVERRIDE         → STATIC
 *   FLAG_NOT_FOUND   → ERROR (errorCode FLAG_NOT_FOUND)
 *   CLIENT_NOT_READY → ERROR (errorCode PROVIDER_NOT_READY)
 */
final class ShipeasyProvider extends AbstractProvider
{
    protected static string $NAME = 'shipeasy';

    /** OpenFeature reason that has no constant on this SDK version. */
    private const REASON_STATIC = 'STATIC';

    private readonly Engine $client;

    /**
     * Construct the provider. The **global form** (no argument) resolves the
     * engine built by `Shipeasy\configure(...)`, so the docs wire OpenFeature
     * without naming the Engine:
     *
     * ```php
     * Shipeasy\configure($_ENV['SHIPEASY_SERVER_KEY']);
     * OpenFeatureAPI::getInstance()->setProvider(new ShipeasyProvider());
     * ```
     *
     * Passing an explicit Engine stays supported for advanced/back-compat use.
     * Throws if no engine is passed and configure() has not run.
     */
    public function __construct(?Engine $client = null)
    {
        $resolved = $client ?? Engine::getDefault();
        if ($resolved === null) {
            throw new \RuntimeException(
                '[shipeasy] new ShipeasyProvider() resolves the configured global engine — '
                . 'call Shipeasy\\configure() first, or pass an Engine explicitly.'
            );
        }
        $this->client = $resolved;
    }

    /**
     * Resolve a boolean flag (gate). Builds a Shipeasy user from the context,
     * evaluates the gate, and maps the resulting reason. On an error reason the
     * default value is returned with the corresponding OpenFeature error code.
     */
    public function resolveBooleanValue(string $flagKey, bool $defaultValue, ?EvaluationContext $context = null): ResolutionDetails
    {
        try {
            $detail = $this->client->getFlagDetail($flagKey, $this->toUser($context));

            return match ($detail->reason) {
                FlagDetail::RULE_MATCH => $this->ok($detail->value, Reason::TARGETING_MATCH),
                FlagDetail::DEFAULT => $this->ok($detail->value, Reason::DEFAULT),
                FlagDetail::OFF => $this->ok($detail->value, Reason::DISABLED),
                FlagDetail::OVERRIDE => $this->ok($detail->value, self::REASON_STATIC),
                FlagDetail::FLAG_NOT_FOUND => $this->error(
                    $defaultValue,
                    ErrorCode::FLAG_NOT_FOUND(),
                    "flag '$flagKey' not found",
                ),
                FlagDetail::CLIENT_NOT_READY => $this->error(
                    $defaultValue,
                    ErrorCode::PROVIDER_NOT_READY(),
                    'Shipeasy client not initialized',
                ),
                default => $this->ok($detail->value, Reason::UNKNOWN),
            };
        } catch (\Throwable $e) {
            return $this->error($defaultValue, ErrorCode::GENERAL(), $e->getMessage());
        }
    }

    public function resolveStringValue(string $flagKey, string $defaultValue, ?EvaluationContext $context = null): ResolutionDetails
    {
        return $this->resolveConfig($flagKey, $defaultValue, 'string');
    }

    public function resolveIntegerValue(string $flagKey, int $defaultValue, ?EvaluationContext $context = null): ResolutionDetails
    {
        return $this->resolveConfig($flagKey, $defaultValue, 'integer');
    }

    public function resolveFloatValue(string $flagKey, float $defaultValue, ?EvaluationContext $context = null): ResolutionDetails
    {
        return $this->resolveConfig($flagKey, $defaultValue, 'float');
    }

    /**
     * @param mixed[] $defaultValue
     */
    public function resolveObjectValue(string $flagKey, array $defaultValue, ?EvaluationContext $context = null): ResolutionDetails
    {
        return $this->resolveConfig($flagKey, $defaultValue, 'object');
    }

    /**
     * Resolve a dynamic config to the requested type.
     *   absent          → default + DEFAULT
     *   present, match   → value   + TARGETING_MATCH
     *   present, wrong   → default + TYPE_MISMATCH error
     *
     * @param bool|string|int|float|mixed[]|null $defaultValue
     */
    private function resolveConfig(string $flagKey, bool | string | int | float | array | null $defaultValue, string $type): ResolutionDetails
    {
        try {
            // A unique sentinel distinguishes "config absent" from a config whose
            // stored value happens to equal the caller's default.
            $sentinel = new \stdClass();
            $raw = $this->client->getConfig($flagKey, $sentinel);

            if ($raw === $sentinel) {
                return $this->ok($defaultValue, Reason::DEFAULT);
            }

            if (!$this->matchesType($raw, $type)) {
                return $this->error(
                    $defaultValue,
                    ErrorCode::TYPE_MISMATCH(),
                    "config '$flagKey' value is not of type $type",
                );
            }

            return $this->ok($raw, Reason::TARGETING_MATCH);
        } catch (\Throwable $e) {
            return $this->error($defaultValue, ErrorCode::GENERAL(), $e->getMessage());
        }
    }

    /** Type-check a raw config value against the OpenFeature-requested type. */
    private function matchesType(mixed $raw, string $type): bool
    {
        return match ($type) {
            'string' => is_string($raw),
            // Reject bool (is_int(true) is false already, but be explicit) — an
            // integer flag must be a real integer, not a bool or float.
            'integer' => is_int($raw),
            // Accept ints as floats (JSON has one number type); reject bool.
            'float' => (is_float($raw) || is_int($raw)) && !is_bool($raw),
            'object' => is_array($raw),
            default => false,
        };
    }

    /**
     * Convert an OpenFeature evaluation context into a Shipeasy user array.
     * `targetingKey` becomes `user_id`; every attribute is carried through
     * verbatim for targeting. A `user_id`/`anonymous_id` already supplied via
     * attributes is preserved when no targeting key is given.
     *
     * @return array<string, mixed>
     */
    private function toUser(?EvaluationContext $context): array
    {
        if ($context === null) {
            return [];
        }

        $user = [];
        foreach ($context->getAttributes()->toArray() as $key => $value) {
            $user[(string) $key] = $value;
        }

        $targetingKey = $context->getTargetingKey();
        if ($targetingKey !== null && $targetingKey !== '') {
            $user['user_id'] = $targetingKey;
        }

        return $user;
    }

    /**
     * @param bool|string|int|float|mixed[]|null $value
     */
    private function ok(bool | string | int | float | array | null $value, string $reason): ResolutionDetails
    {
        return (new ResolutionDetailsBuilder())
            ->withValue($value)
            ->withReason($reason)
            ->build();
    }

    /**
     * @param bool|string|int|float|mixed[]|null $defaultValue
     */
    private function error(bool | string | int | float | array | null $defaultValue, ErrorCode $code, string $message): ResolutionDetails
    {
        return (new ResolutionDetailsBuilder())
            ->withValue($defaultValue)
            ->withReason(Reason::ERROR)
            ->withError(new ResolutionError($code, $message))
            ->build();
    }
}
