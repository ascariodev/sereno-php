<?php

declare(strict_types=1);

namespace Sereno\Tests;

use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;
use Sereno\ConnectionTester;
use Sereno\EventFormatter;
use Sereno\Redactor;
use Sereno\Transport;

final class RedactorTest extends TestCase
{
    private const KEY = 'wsk_0123456789012345678901234567890123456789';

    public function test_key_in_a_message_is_redacted(): void
    {
        $this->assertSame('failed with ***', Redactor::redact('failed with '.self::KEY, self::KEY));
        $this->assertSame('bad: ***', Redactor::redact('bad: Bearer '.self::KEY, self::KEY));
    }

    public function test_key_with_an_inner_newline_is_redacted_raw_and_json_escaped(): void
    {
        $key = "wsk_abc\ndef/ghi";
        $escaped = substr((string) json_encode($key), 1, -1);

        $this->assertSame('a *** b', Redactor::redact("a $key b", $key));
        $this->assertSame('a *** b', Redactor::redact("a $escaped b", $key));
        $this->assertSame('a *** b', Redactor::redact("a Bearer $escaped b", $key));
        $this->assertStringNotContainsString('abc', Redactor::redact("{\"k\":\"$escaped\"}", $key));
    }

    public function test_userinfo_of_urls_is_dropped(): void
    {
        $this->assertSame(
            'cannot reach https://sereno.test/api',
            Redactor::redact('cannot reach https://user:p%40ss@sereno.test/api')
        );
        $this->assertSame('see http://sereno.test', Redactor::redact('see http://tok@sereno.test'));
    }

    public function test_non_utf8_key_does_not_redact_the_bearer_prefix_alone(): void
    {
        $key = "wsk_ÿş";

        $this->assertSame('Bearer x ***', Redactor::redact("Bearer x $key", $key));
        $this->assertSame('Authorization: Bearer abc', Redactor::redact('Authorization: Bearer abc', $key));
    }

    public function test_transport_error_message_does_not_carry_the_key_or_userinfo(): void
    {
        $key = "wsk_abc
def";
        $escaped = substr((string) json_encode($key), 1, -1);
        $clock = static function () use ($escaped): float {
            throw new \RuntimeException("failed https://user:secret@sereno.test with Bearer $escaped");
        };

        $result = (new Transport('http://user:secret@sereno.test', $key, null, $clock))->send([['message' => 'x']]);

        $this->assertSame('error', $result->status);
        $this->assertSame('failed https://sereno.test with ***', $result->error);
    }

    public function test_connection_tester_detail_does_not_carry_the_key_or_userinfo(): void
    {
        $formatter = new class extends EventFormatter
        {
            public function format(LogRecord $record): array
            {
                throw new \RuntimeException('boom http://user:secret@sereno.test '.self::KEY);
            }

            public const KEY = 'wsk_0123456789';
        };

        $result = (new ConnectionTester($formatter))->test('http://sereno.test', 'wsk_0123456789');

        $this->assertSame(ConnectionTester::FAILED, $result['status']);
        $this->assertSame('boom http://sereno.test ***', $result['detail']);
    }
}
