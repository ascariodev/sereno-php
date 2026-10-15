<?php

declare(strict_types=1);

namespace Sereno;

use Closure;
use Throwable;

final class Transport
{
    public const TIMEOUT_SECONDS = 2;

    public const MAX_TIMEOUT_SECONDS = 10;

    public const MIN_RETRY_SECONDS = 0.5;

    public const INGEST_PATH = '/api/ingest/events';

    private readonly string $endpoint;

    private readonly string $key;

    private readonly int|float $timeout;

    private readonly Closure $clock;

    public function __construct(
        string $url,
        string $key,
        int|float|string|null $timeout = null,
        ?Closure $clock = null,
    ) {
        $this->endpoint = rtrim(trim($url), '/').self::INGEST_PATH;
        $this->key = trim($key);
        $this->timeout = self::normalizeTimeout($timeout);
        $this->clock = $clock ?? static fn (): float => hrtime(true) / 1e9;
    }

    public static function normalizeTimeout(int|float|string|null $timeout): int|float
    {
        if (! is_numeric($timeout) || $timeout + 0 <= 0) {
            return self::TIMEOUT_SECONDS;
        }

        $value = $timeout + 0;

        return ! is_finite($value) || $value > self::MAX_TIMEOUT_SECONDS ? self::MAX_TIMEOUT_SECONDS : $value;
    }

    public function timeout(): int|float
    {
        return $this->timeout;
    }

    /**
     * @param  list<array<string, mixed>>  $batch
     */
    public function send(array $batch): DeliveryResult
    {
        try {
            $payload = json_encode(['events' => $batch]);

            if ($payload === false) {
                return DeliveryResult::error(json_last_error_msg(), false, 0);
            }

            $startedAt = ($this->clock)();
            $result = $this->attempt($payload, $this->timeout, 1);

            if ($result->transient) {
                $remaining = self::MAX_TIMEOUT_SECONDS - (($this->clock)() - $startedAt);

                if ($remaining >= self::MIN_RETRY_SECONDS) {
                    $result = $this->attempt($payload, min($this->timeout, $remaining), 2);
                }
            }

            return $result;
        } catch (Throwable $e) {
            return DeliveryResult::error($e->getMessage(), false, 0);
        }
    }

    private function attempt(string $payload, int|float $timeout, int $attempts): DeliveryResult
    {
        try {
            $handle = curl_init($this->endpoint);

            if ($handle === false) {
                return DeliveryResult::error('curl could not be initialized', false, $attempts);
            }

            $milliseconds = max(1, (int) ceil($timeout * 1000));

            curl_setopt_array($handle, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer '.$this->key,
                    'Content-Type: application/json',
                    'Accept: application/json',
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT_MS => $milliseconds,
                CURLOPT_TIMEOUT_MS => $milliseconds,
                CURLOPT_NOSIGNAL => true,
            ]);

            $body = curl_exec($handle);

            if ($body === false) {
                return DeliveryResult::error(curl_error($handle) ?: 'curl request failed', true, $attempts);
            }

            $code = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

            if ($code >= 200 && $code < 300) {
                return DeliveryResult::sent($code, $attempts);
            }

            return DeliveryResult::rejected($code, (string) $body, $attempts);
        } catch (Throwable $e) {
            return DeliveryResult::error($e->getMessage(), false, $attempts);
        }
    }
}
