<?php

declare(strict_types=1);

namespace Sereno\Tests;

use PHPUnit\Framework\TestCase;
use Sereno\ConnectionTester;

final class ConnectionTesterTest extends TestCase
{
    private const KEY = 'wsk_0123456789012345678901234567890123456789';

    private ?TestServer $server = null;

    private string $dir = '';

    private int $port = 0;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/sereno-tester-'.bin2hex(random_bytes(6));
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

    public function test_accepted_event_is_connected_and_is_an_info_test_event(): void
    {
        $this->startServer(202);

        $result = (new ConnectionTester)->test($this->url(), self::KEY);

        $this->assertSame(['status' => ConnectionTester::CONNECTED, 'detail' => null], $result);

        $requests = $this->requests();
        $this->assertCount(1, $requests);
        $this->assertSame('/api/ingest/events', $requests[0]['path']);
        $this->assertSame('Bearer '.self::KEY, $requests[0]['headers']['authorization']);

        $event = json_decode($requests[0]['body'], true)['events'][0];
        $this->assertSame('info', $event['level']);
        $this->assertSame('Sereno connection test', $event['message']);
        $this->assertArrayHasKey('id', $event);
        $this->assertArrayHasKey('occurred_at', $event);
    }

    public function test_401_is_unauthorized(): void
    {
        $this->startServer(401);

        $this->assertSame(
            ['status' => ConnectionTester::UNAUTHORIZED, 'detail' => null],
            (new ConnectionTester)->test($this->url(), self::KEY),
        );
    }

    public function test_redirect_is_failed_with_scheme_hint_and_is_not_followed(): void
    {
        $this->startServer(302);

        $result = (new ConnectionTester)->test($this->url(), self::KEY);

        $this->assertSame(ConnectionTester::FAILED, $result['status']);
        $this->assertSame('HTTP 302, check the URL scheme', $result['detail']);
        $this->assertCount(1, $this->requests());
    }

    public function test_server_error_is_failed_with_the_status(): void
    {
        $this->startServer(500);

        $this->assertSame(
            ['status' => ConnectionTester::FAILED, 'detail' => 'HTTP 500'],
            (new ConnectionTester)->test($this->url(), self::KEY),
        );
    }

    public function test_unreachable_server_is_failed(): void
    {
        $result = (new ConnectionTester)->test($this->url(), self::KEY);

        $this->assertSame(ConnectionTester::FAILED, $result['status']);
        $this->assertNotEmpty($result['detail']);
    }

    public function test_missing_url_and_key_are_reported_without_a_request(): void
    {
        $tester = new ConnectionTester;

        $this->assertSame(['status' => ConnectionTester::NO_URL, 'detail' => null], $tester->test('  ', self::KEY));
        $this->assertSame(['status' => ConnectionTester::NO_URL, 'detail' => null], $tester->test(null, null));
        $this->assertSame(['status' => ConnectionTester::NO_KEY, 'detail' => null], $tester->test($this->url(), ' '));
    }

    public function test_binary_exits_zero_only_when_connected(): void
    {
        $this->startServer(202);
        [$output, $code] = $this->runBinary(['--key='.self::KEY, '--url='.$this->url()]);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('Connected', $output);

        [$output, $code] = $this->runBinary([], ['SERENO_KEY' => self::KEY, 'SERENO_URL' => $this->url()]);
        $this->assertSame(0, $code);

        [$output, $code] = $this->runBinary([], ['SERENO_URL' => $this->url()]);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('Missing key', $output);

        [$output, $code] = $this->runBinary(['--key='.self::KEY], ['SERENO_URL' => '']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('Missing URL', $output);
    }

    public function test_binary_exits_one_when_unauthorized(): void
    {
        $this->startServer(401);

        [$output, $code] = $this->runBinary(['--key='.self::KEY, '--url='.$this->url()]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('Unauthorized', $output);
    }

    /**
     * @param  list<string>  $arguments
     * @param  array<string, string>  $env
     * @return array{string, int}
     */
    private function runBinary(array $arguments, array $env = []): array
    {
        $base = array_diff_key(getenv(), ['SERENO_KEY' => 1, 'SERENO_URL' => 1]);
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__).'/bin/sereno-test', ...$arguments],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            array_merge($base, $env),
        );
        $output = (string) stream_get_contents($pipes[1]).(string) stream_get_contents($pipes[2]);
        $code = proc_close($process);

        return [$output, $code];
    }

    private function url(): string
    {
        return 'http://127.0.0.1:'.$this->port;
    }

    /**
     * @return list<array{method: string, path: string, headers: array<string, string>, body: string}>
     */
    private function requests(): array
    {
        $file = $this->dir.'/requests.log';

        return is_file($file)
            ? array_map(static fn (string $line): array => json_decode($line, true), file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES))
            : [];
    }

    private function startServer(int $status): void
    {
        file_put_contents($this->dir.'/control.json', json_encode([['status' => $status, 'body' => '{}']]));

        $this->server = TestServer::start($this->dir);
        $this->port = $this->server->port;
    }
}
