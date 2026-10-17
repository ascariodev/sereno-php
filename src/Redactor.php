<?php

declare(strict_types=1);

namespace Sereno;

final class Redactor
{
    public static function redact(string $text, string ...$secrets): string
    {
        $secrets = array_filter($secrets, static fn (string $secret): bool => $secret !== '');
        usort($secrets, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        foreach ($secrets as $secret) {
            $escaped = [
                self::inner(json_encode($secret)),
                self::inner(json_encode($secret, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
                addcslashes($secret, "\0..\37\\"),
            ];

            $variants = array_filter(array_unique(array_merge($escaped, [$secret])), static fn (string $variant): bool => $variant !== '');

            foreach ($variants as $variant) {
                $text = str_replace(['Bearer '.$variant, $variant], '***', $text);
            }
        }

        return (string) preg_replace('~(?<=//)[^/\s@]*@~', '', $text);
    }

    private static function inner(string|false $encoded): string
    {
        return $encoded === false ? '' : substr($encoded, 1, -1);
    }
}
