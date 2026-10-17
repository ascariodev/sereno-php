<?php

declare(strict_types=1);

namespace Sereno;

use DateTimeImmutable;
use Monolog\Level;
use Monolog\LogRecord;
use Throwable;

final class ConnectionTester
{
    public const CONNECTED = 'connected';

    public const UNAUTHORIZED = 'unauthorized';

    public const NO_URL = 'no_url';

    public const NO_KEY = 'no_key';

    public const FAILED = 'failed';

    public const TIMEOUT_SECONDS = 5;

    public function __construct(private readonly EventFormatter $formatter = new EventFormatter) {}

    /**
     * @return array{status: string, detail: ?string}
     */
    public function test(?string $url, ?string $key): array
    {
        $url = trim((string) $url);
        $key = trim((string) $key);

        if ($url === '') {
            return ['status' => self::NO_URL, 'detail' => null];
        }

        if ($key === '') {
            return ['status' => self::NO_KEY, 'detail' => null];
        }

        try {
            $record = new LogRecord(
                datetime: new DateTimeImmutable,
                channel: 'sereno',
                level: Level::Info,
                message: 'Sereno connection test',
            );
            $payload = json_encode(['events' => [$this->formatter->format($record)]]);

            if ($payload === false) {
                return ['status' => self::FAILED, 'detail' => Redactor::redact(json_last_error_msg(), $key)];
            }

            $handle = curl_init(rtrim($url, '/').Transport::INGEST_PATH);

            if ($handle === false) {
                return ['status' => self::FAILED, 'detail' => 'curl could not be initialized'];
            }

            curl_setopt_array($handle, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer '.$key,
                    'Content-Type: application/json',
                    'Accept: application/json',
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => self::TIMEOUT_SECONDS,
                CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
                CURLOPT_NOSIGNAL => true,
            ]);

            if (curl_exec($handle) === false) {
                return ['status' => self::FAILED, 'detail' => Redactor::redact(curl_error($handle) ?: 'curl request failed', $key)];
            }

            $code = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        } catch (Throwable $e) {
            return ['status' => self::FAILED, 'detail' => Redactor::redact($e->getMessage(), $key)];
        }

        if ($code === 202) {
            return ['status' => self::CONNECTED, 'detail' => null];
        }

        if ($code === 401) {
            return ['status' => self::UNAUTHORIZED, 'detail' => null];
        }

        if ($code >= 300 && $code < 400) {
            return ['status' => self::FAILED, 'detail' => 'HTTP '.$code.', check the URL scheme'];
        }

        return ['status' => self::FAILED, 'detail' => 'HTTP '.$code];
    }
}
