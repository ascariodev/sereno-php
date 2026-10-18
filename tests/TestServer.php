<?php

declare(strict_types=1);

namespace Sereno\Tests;

use RuntimeException;

final class TestServer
{
    private const READY_BODY = 'sereno-test-server';

    /** @var resource|null */
    private $process;

    /**
     * @param  resource  $process
     */
    private function __construct(
        $process,
        public readonly int $port,
    ) {
        $this->process = $process;
    }

    public static function start(string $dir): self
    {
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $port = self::freePort();
            $process = proc_open(
                [PHP_BINARY, '-S', '127.0.0.1:'.$port, __DIR__.'/server/router.php'],
                [0 => ['file', $null, 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']],
                $pipes,
                null,
                array_merge(getenv(), ['SERENO_TEST_DIR' => $dir, 'PHP_CLI_SERVER_WORKERS' => '1']),
            );

            if (! is_resource($process)) {
                continue;
            }

            $server = new self($process, $port);

            if ($server->waitUntilReady()) {
                return $server;
            }

            $server->stop();
        }

        throw new RuntimeException('The test HTTP server did not start.');
    }

    public function stop(): void
    {
        if (! is_resource($this->process)) {
            return;
        }

        proc_terminate($this->process);

        for ($i = 0; $i < 40 && (proc_get_status($this->process)['running'] ?? false); $i++) {
            usleep(50_000);
        }

        if (proc_get_status($this->process)['running'] ?? false) {
            proc_terminate($this->process, 9);
        }

        proc_close($this->process);
        $this->process = null;
    }

    private function waitUntilReady(): bool
    {
        for ($i = 0; $i < 100; $i++) {
            if (! (proc_get_status($this->process)['running'] ?? false)) {
                return false;
            }

            $socket = @fsockopen('127.0.0.1', $this->port, $errno, $errstr, 0.2);

            if ($socket !== false) {
                stream_set_timeout($socket, 1);
                fwrite($socket, "GET /__ready HTTP/1.0\r\nHost: 127.0.0.1\r\n\r\n");
                $response = (string) stream_get_contents($socket);
                fclose($socket);

                return str_ends_with($response, self::READY_BODY)
                    && (proc_get_status($this->process)['running'] ?? false);
            }

            usleep(50_000);
        }

        return false;
    }

    public static function freePort(): int
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        $name = (string) stream_socket_get_name($server, false);
        fclose($server);

        return (int) substr($name, strrpos($name, ':') + 1);
    }
}
