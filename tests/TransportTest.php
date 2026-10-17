<?php

declare(strict_types=1);

namespace Sereno\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sereno\Transport;

final class TransportTest extends TestCase
{
    private const KEY = 'wsk_0123456789012345678901234567890123456789';

    private ?TestServer $server = null;

    private string $dir = '';

    private int $port = 0;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/sereno-transport-'.bin2hex(random_bytes(6));
        mkdir($this->dir);
        $this->port = TestServer::freePort();
    }

    protected function tearDown(): void
    {
        $this->server?->stop();

        foreach (glob($this->dir.'/*') ?: [] as $file) {
            unlink($file);
        }

        @rmdir($this->dir);
    }

    public function test_accepted_batch_is_sent_with_the_right_request(): void
    {
        $this->startServer([['status' => 202, 'body' => '{"accepted":2}']]);
        $batch = $this->batch(2);

        $result = $this->transport()->send($batch);

        $this->assertTrue($result->isSent());
        $this->assertSame(202, $result->code);
        $this->assertSame(1, $result->attempts);

        $requests = $this->requests();
        $this->assertCount(1, $requests);
        $this->assertSame('POST', $requests[0]['method']);
        $this->assertSame('/api/ingest/events', $requests[0]['path']);
        $this->assertSame('Bearer '.self::KEY, $requests[0]['headers']['authorization']);
        $this->assertSame('application/json', $requests[0]['headers']['content-type']);
        $this->assertSame('application/json', $requests[0]['headers']['accept']);
        $this->assertSame(['events' => $batch], json_decode($requests[0]['body'], true));
    }

    public function test_trailing_slash_in_the_base_url_is_ignored(): void
    {
        $this->startServer([['status' => 202, 'body' => '{}']]);

        $result = (new Transport($this->baseUrl().'/', self::KEY))->send($this->batch(1));

        $this->assertTrue($result->isSent());
        $this->assertSame('/api/ingest/events', $this->requests()[0]['path']);
    }

    public function test_unauthorized_is_rejected_without_retry(): void
    {
        $this->startServer([['status' => 401, 'body' => '{"message":"Unauthenticated."}']]);

        $result = $this->transport()->send($this->batch(1));

        $this->assertTrue($result->isRejected());
        $this->assertSame(401, $result->code);
        $this->assertFalse($result->transient);
        $this->assertSame('{"message":"Unauthenticated."}', $result->body);
        $this->assertCount(1, $this->requests());
    }

    public function test_rejected_body_is_cut_to_500_characters(): void
    {
        $this->startServer([['status' => 422, 'body' => str_repeat('é', 800)]]);

        $result = $this->transport()->send($this->batch(1));

        $this->assertSame(500, mb_strlen((string) $result->body));
    }

    public function test_server_error_is_retried_once_with_the_same_ids(): void
    {
        $this->startServer([['status' => 500, 'body' => 'boom'], ['status' => 202, 'body' => '{}']]);
        $batch = $this->batch(3);

        $result = $this->transport()->send($batch);

        $this->assertTrue($result->isSent());
        $this->assertSame(2, $result->attempts);

        $requests = $this->requests();
        $this->assertCount(2, $requests);
        $this->assertSame($requests[0]['body'], $requests[1]['body']);
        $this->assertSame(
            array_column($batch, 'id'),
            array_column(json_decode($requests[1]['body'], true)['events'], 'id'),
        );
    }

    public function test_persistent_server_error_makes_exactly_one_retry(): void
    {
        $this->startServer([['status' => 503, 'body' => 'down']]);

        $result = $this->transport()->send($this->batch(1));

        $this->assertTrue($result->isRejected());
        $this->assertSame(503, $result->code);
        $this->assertTrue($result->transient);
        $this->assertSame(2, $result->attempts);
        $this->assertCount(2, $this->requests());
    }

    public function test_connection_error_is_retried_once(): void
    {
        $calls = 0;
        $clock = function () use (&$calls): float {
            $calls++;

            return 0.0;
        };

        $result = (new Transport($this->baseUrl(), self::KEY, 1, $clock))->send($this->batch(1));

        $this->assertTrue($result->isError());
        $this->assertTrue($result->transient);
        $this->assertSame(2, $result->attempts);
        $this->assertNotSame('', (string) $result->error);
        $this->assertSame(2, $calls);
    }

    public function test_timeout_stays_inside_the_budget(): void
    {
        $listener = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertNotFalse($listener);
        $name = (string) stream_socket_get_name($listener, false);
        $port = (int) substr($name, strrpos($name, ':') + 1);

        $startedAt = microtime(true);
        $result = (new Transport('http://127.0.0.1:'.$port, self::KEY, 0.4))->send($this->batch(1));
        $elapsed = microtime(true) - $startedAt;

        $this->assertTrue($result->isError());
        $this->assertTrue($result->transient);
        $this->assertSame(2, $result->attempts);
        $this->assertGreaterThanOrEqual(0.7, $elapsed);
        $this->assertLessThan(3.0, $elapsed);

        $connections = 0;
        while (($connection = @stream_socket_accept($listener, 0)) !== false) {
            $connections++;
            fclose($connection);
        }
        fclose($listener);
        $this->assertSame(2, $connections);
    }

    public function test_retry_is_skipped_when_less_than_half_a_second_is_left(): void
    {
        $this->startServer([['status' => 500, 'body' => 'boom']]);
        $times = [0.0, 9.6];
        $clock = static function () use (&$times): float {
            return array_shift($times) ?? 99.0;
        };

        $result = (new Transport($this->baseUrl(), self::KEY, 2, $clock))->send($this->batch(1));

        $this->assertTrue($result->isRejected());
        $this->assertSame(500, $result->code);
        $this->assertSame(1, $result->attempts);
        $this->assertCount(1, $this->requests());
    }

    public function test_retry_happens_when_exactly_half_a_second_is_left(): void
    {
        $this->startServer([['status' => 500, 'body' => 'boom'], ['status' => 202, 'body' => '{}']]);
        $times = [0.0, 9.5];
        $clock = static function () use (&$times): float {
            return array_shift($times) ?? 99.0;
        };

        $result = (new Transport($this->baseUrl(), self::KEY, 2, $clock))->send($this->batch(1));

        $this->assertTrue($result->isSent());
        $this->assertSame(2, $result->attempts);
    }

    public function test_unserializable_batch_returns_an_error_without_throwing(): void
    {
        $result = $this->transport()->send([['message' => NAN]]);

        $this->assertTrue($result->isError());
        $this->assertFalse($result->transient);
        $this->assertSame(0, $result->attempts);
    }

    /**
     * @return iterable<string, array{mixed, int|float}>
     */
    public static function timeouts(): iterable
    {
        yield 'null' => [null, 2];
        yield 'zero' => [0, 2];
        yield 'negative' => [-3, 2];
        yield 'text' => ['abc', 2];
        yield 'numeric string' => ['3.5', 3.5];
        yield 'integer' => [5, 5];
        yield 'above the maximum' => [60, 10];
        yield 'infinite' => [INF, 10];
    }

    #[DataProvider('timeouts')]
    public function test_timeout_is_normalized(mixed $input, int|float $expected): void
    {
        $this->assertEquals($expected, Transport::normalizeTimeout($input));
        $this->assertEquals($expected, (new Transport('http://x', self::KEY, $input))->timeout());
    }

    private function transport(): Transport
    {
        return new Transport($this->baseUrl(), self::KEY, 2);
    }

    private function baseUrl(): string
    {
        return 'http://127.0.0.1:'.$this->port;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function batch(int $size): array
    {
        $batch = [];

        for ($i = 0; $i < $size; $i++) {
            $batch[] = [
                'id' => bin2hex(random_bytes(16)),
                'level' => 'error',
                'message' => 'event '.$i,
                'occurred_at' => '2026-01-01T00:00:00.000Z',
            ];
        }

        return $batch;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function requests(): array
    {
        $file = $this->dir.'/requests.log';

        if (! is_file($file)) {
            return [];
        }

        return array_map(
            static fn (string $line): array => json_decode($line, true),
            array_values(array_filter(file($file, FILE_IGNORE_NEW_LINES))),
        );
    }

    /**
     * @param  list<array{status: int, body: string}>  $responses
     */
    private function startServer(array $responses): void
    {
        file_put_contents($this->dir.'/control.json', json_encode($responses));

        $this->server = TestServer::start($this->dir);
        $this->port = $this->server->port;
    }
}
