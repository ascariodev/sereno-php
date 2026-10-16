<?php

declare(strict_types=1);

namespace Sereno;

final class DeliveryResult
{
    private const SENT = 'sent';

    private const REJECTED = 'rejected';

    private const ERROR = 'error';

    private function __construct(
        public readonly string $status,
        public readonly ?int $code,
        public readonly ?string $body,
        public readonly ?string $error,
        public readonly bool $transient,
        public readonly int $attempts,
    ) {}

    public static function sent(int $code, int $attempts): self
    {
        return new self(self::SENT, $code, null, null, false, $attempts);
    }

    public static function rejected(int $code, string $body, int $attempts): self
    {
        return new self(self::REJECTED, $code, mb_substr($body, 0, 500), null, $code >= 500, $attempts);
    }

    public static function error(string $message, bool $transient, int $attempts): self
    {
        return new self(self::ERROR, null, null, $message, $transient, $attempts);
    }

    public function isSent(): bool
    {
        return $this->status === self::SENT;
    }

    public function isRejected(): bool
    {
        return $this->status === self::REJECTED;
    }

    public function isError(): bool
    {
        return $this->status === self::ERROR;
    }
}
