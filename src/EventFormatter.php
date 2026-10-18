<?php

declare(strict_types=1);

namespace Sereno;

use DateTimeInterface;
use DateTimeZone;
use JsonSerializable;
use Monolog\LogRecord;
use Stringable;
use Throwable;

class EventFormatter
{
    public const MAX_MESSAGE_LENGTH = 8192;

    public const MAX_CONTEXT_BYTES = 32768;

    public const MAX_CONTEXT_DEPTH = 8;

    public const MAX_FINGERPRINT_LENGTH = 255;

    private const MAX_STRING_LENGTH = 2048;

    private const MAX_TRACE_LENGTH = 2000;

    private const MAX_ITEMS_PER_ARRAY = 50;

    private const TRUNCATED_KEY = '_truncated';

    private const TRIM_CHARS = '\s\x{0000}\x{00A0}\x{00AD}\x{034F}\x{061C}\x{115F}\x{1160}\x{17B4}\x{17B5}\x{180E}'
        .'\x{2000}-\x{200F}\x{202F}\x{205F}\x{2060}-\x{2065}\x{206A}-\x{206F}\x{3000}\x{2800}\x{3164}\x{FEFF}'
        .'\x{FFA0}\x{1D159}\x{1D173}-\x{1D17A}\x{E0020}';

    /**
     * @return array{id: string, level: string, message: string, context?: array<string, mixed>, occurred_at: string, fingerprint?: string}
     */
    public function format(LogRecord $record): array
    {
        $event = [
            'id' => $this->uuid(),
            'level' => $record->level->toPsrLogLevel(),
            'message' => $this->message($record->message),
            'occurred_at' => $record->datetime->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z'),
        ];

        $context = $this->context($record->context);

        if ($context !== []) {
            $event['context'] = $context;
        }

        $fingerprint = $this->fingerprint($record->context);

        if ($fingerprint !== null) {
            $event['fingerprint'] = $fingerprint;
        }

        return $event;
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);
        $hex = bin2hex($bytes);

        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'.substr($hex, 16, 4).'-'.substr($hex, 20);
    }

    private function message(string $message): string
    {
        $message = mb_substr($this->clean($message), 0, self::MAX_MESSAGE_LENGTH);

        return $this->trim($message) === '' ? '-' : $message;
    }

    /**
     * @param  array<mixed>  $context
     * @return array<string, mixed>
     */
    private function context(array $context): array
    {
        $truncated = false;
        $normalized = $this->normalizeArray($context, 1, $truncated);

        $fitted = [];

        foreach ($normalized as $key => $value) {
            $candidate = $fitted + [$key => $value];

            if ($this->serializedSize($candidate) > self::MAX_CONTEXT_BYTES - 64) {
                $candidate = $fitted + [$key => '[truncated]'];

                if ($this->serializedSize($candidate) > self::MAX_CONTEXT_BYTES - 64) {
                    $truncated = true;

                    continue;
                }
            }

            if ($candidate[$key] !== $value) {
                $truncated = true;
            }

            $fitted = $candidate;
        }

        if ($truncated) {
            $fitted[self::TRUNCATED_KEY] = true;
        }

        return $fitted;
    }

    /**
     * @param  array<mixed>  $values
     * @return array<string|int, mixed>
     */
    private function normalizeArray(array $values, int $depth, bool &$truncated): array
    {
        $result = [];
        $count = 0;

        foreach ($values as $key => $value) {
            if (++$count > self::MAX_ITEMS_PER_ARRAY) {
                $truncated = true;
                break;
            }

            $key = is_string($key) ? $this->clean($key) : $key;
            $result[$key] = $this->normalize($value, $depth, $truncated);
        }

        return $result;
    }

    private function normalize(mixed $value, int $depth, bool &$truncated): mixed
    {
        if ($value instanceof Throwable) {
            $value = $this->exceptionToArray($value);
        } elseif ($value instanceof DateTimeInterface) {
            return $value->format(DATE_ATOM);
        } elseif ($value instanceof JsonSerializable) {
            $value = $value->jsonSerialize();
        } elseif ($value instanceof Stringable) {
            return $this->limitedString((string) $value, $truncated);
        }

        if (is_object($value)) {
            return '[object '.$value::class.']';
        }

        if (is_array($value)) {
            if ($depth >= self::MAX_CONTEXT_DEPTH) {
                $truncated = true;

                return '[truncated]';
            }

            return $this->normalizeArray($value, $depth + 1, $truncated);
        }

        if (is_string($value)) {
            return $this->limitedString($value, $truncated);
        }

        if (is_float($value) && ! is_finite($value)) {
            return is_nan($value) ? 'NAN' : ($value > 0 ? 'INF' : '-INF');
        }

        if (is_int($value) || is_float($value) || is_bool($value) || $value === null) {
            return $value;
        }

        return '[resource]';
    }

    private function limitedString(string $value, bool &$truncated): string
    {
        $value = $this->clean($value);

        if (mb_strlen($value) > self::MAX_STRING_LENGTH) {
            $truncated = true;

            return mb_substr($value, 0, self::MAX_STRING_LENGTH);
        }

        return $value;
    }

    /**
     * @return array{class: string, message: string, location: string, trace: string}
     */
    private function exceptionToArray(Throwable $exception): array
    {
        return [
            'class' => $exception::class,
            'message' => $exception->getMessage(),
            'location' => $exception->getFile().':'.$exception->getLine(),
            'trace' => mb_substr($this->clean($exception->getTraceAsString()), 0, self::MAX_TRACE_LENGTH),
        ];
    }

    /**
     * @param  array<mixed>  $context
     */
    private function fingerprint(array $context): ?string
    {
        $explicit = $context['fingerprint'] ?? null;

        if (is_string($explicit) && ($explicit = $this->trim($this->clean($explicit))) !== '') {
            return $this->fitFingerprint($explicit);
        }

        $exception = $context['exception'] ?? null;

        if ($exception instanceof Throwable) {
            return $this->fitFingerprint($exception::class.'|'.$exception->getFile().':'.$exception->getLine());
        }

        return null;
    }

    private function fitFingerprint(string $fingerprint): string
    {
        return mb_strlen($fingerprint) > self::MAX_FINGERPRINT_LENGTH ? sha1($fingerprint) : $fingerprint;
    }

    /** Same measure as the ingest validator: json_encode with default flags. */
    private function serializedSize(array $context): int
    {
        return strlen(json_encode($context, JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '');
    }

    private function clean(string $value): string
    {
        return str_replace("\0", '', mb_convert_encoding($value, 'UTF-8', 'UTF-8'));
    }

    private function trim(string $value): string
    {
        $class = '['.self::TRIM_CHARS.']+';

        $char = '['.self::TRIM_CHARS.']';

        return preg_replace('~^'.$class.'|'.$char.'(?<!'.$char.$char.')'.$char.'*+$~u', '', $value) ?? trim($value);
    }
}
