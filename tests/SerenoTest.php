<?php

declare(strict_types=1);

namespace Sereno\Tests;

use PHPUnit\Framework\TestCase;

final class SerenoTest extends TestCase
{
    private const KEY = 'wsk_0123456789012345678901234567890123456789';

    /** @var resource|null */
    private $process = null;

    private string $dir = '';

    private int $port = 0;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/sereno-global-'.bin2hex(random_bytes(6));
        mkdir($this->dir);
        $this->port = $this->freePort();
        $this->startServer();
    }

    protected function tearDown(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }

        foreach (glob($this->dir.'/*') ?: [] as $file) {
            unlink($file);
        }

        @rmdir($this->dir);
    }

    public function test_uncaught_exception_is_sent_as_error_and_the_previous_handler_runs(): void
    {
        $script = $this->script(<<<'PHP'
            set_exception_handler(function (Throwable $e): void {
                echo 'PREVIOUS:'.$e->getMessage();
            });
            \Sereno\Sereno::init(%KEY%, %URL%);
            throw new RuntimeException('boom');
            PHP);

        [$out, , $code] = $this->runScript($script);

        $this->assertSame('PREVIOUS:boom', $out);

        $events = $this->events();
        $this->assertCount(1, $events);
        $this->assertSame('error', $events[0]['level']);
        $this->assertSame('boom', $events[0]['message']);
        $this->assertSame('RuntimeException|'.$script.':7', $events[0]['fingerprint']);
    }

    public function test_uncaught_exception_without_previous_handler_logs_to_error_log_and_exits_255(): void
    {
        $script = $this->script(<<<'PHP'
            \Sereno\Sereno::init(%KEY%, %URL%);
            throw new LogicException('lonely');
            PHP);

        [, $err, $code] = $this->runScript($script);

        $this->assertSame(255, $code);
        $this->assertStringContainsString('PHP Fatal error:  Uncaught LogicException: lonely', $err);
        $this->assertCount(1, $this->events());
    }

    public function test_warnings_are_sent_and_chained_to_the_previous_error_handler(): void
    {
        $script = $this->script(<<<'PHP'
            set_error_handler(function (int $no, string $str): bool {
                echo 'PREVIOUS:'.$str.';';

                return true;
            });
            \Sereno\Sereno::init(%KEY%, %URL%);
            trigger_error('careful', E_USER_WARNING);
            trigger_error('just a note', E_USER_NOTICE);
            @trigger_error('silenced', E_USER_WARNING);
            echo 'RETURN:'.var_export(\Sereno\Sereno::handleError(E_USER_WARNING, 'direct', 'f.php', 1), true);
            PHP);

        [$out, , $code] = $this->runScript($script);

        $this->assertSame(0, $code);
        $this->assertSame('PREVIOUS:careful;PREVIOUS:just a note;PREVIOUS:silenced;PREVIOUS:direct;RETURN:true', $out);

        $events = $this->events();
        $this->assertSame(['careful', 'direct'], array_column($events, 'message'));
        $this->assertSame('warning', $events[0]['level']);
    }

    public function test_warning_without_previous_handler_falls_through_to_php(): void
    {
        $script = $this->script(<<<'PHP'
            \Sereno\Sereno::init(%KEY%, %URL%);
            echo 'RETURN:'.var_export(\Sereno\Sereno::handleError(E_WARNING, 'plain', 'f.php', 1), true);
            PHP);

        [$out] = $this->runScript($script);

        $this->assertSame('RETURN:false', $out);
        $this->assertSame(['plain'], array_column($this->events(), 'message'));
    }

    public function test_error_level_option_filters_what_is_sent(): void
    {
        $script = $this->script(<<<'PHP'
            \Sereno\Sereno::init(%KEY%, %URL%, ['errorLevel' => E_USER_NOTICE]);
            trigger_error('careful', E_USER_WARNING);
            trigger_error('note', E_USER_NOTICE);
            PHP);

        $this->runScript($script);

        $this->assertSame(['note'], array_column($this->events(), 'message'));
    }

    public function test_fatal_error_is_sent_as_critical_on_shutdown(): void
    {
        $script = $this->script(<<<'PHP'
            \Sereno\Sereno::init(%KEY%, %URL%);
            ini_set('memory_limit', '8M');
            $a = [];
            while (true) { $a[] = str_repeat('x', 1000000); }
            PHP);

        [, , $code] = $this->runScript($script);

        $this->assertSame(255, $code);
        $events = $this->events();
        $this->assertCount(1, $events);
        $this->assertSame('critical', $events[0]['level']);
        $this->assertStringContainsString('Allowed memory size', $events[0]['message']);
    }

    public function test_user_error_handled_by_the_sdk_is_not_reported_again_on_shutdown(): void
    {
        $script = $this->script(<<<'PHP'
            \Sereno\Sereno::init(%KEY%, %URL%);
            trigger_error('fatal user', E_USER_ERROR);
            PHP);

        [, , $code] = $this->runScript($script);

        $this->assertSame(255, $code);
        $this->assertSame(['fatal user'], array_column($this->events(), 'message'));
    }

    public function test_previous_error_handler_that_throws_is_reported_once_and_the_exception_propagates(): void
    {
        $script = $this->script(<<<'PHP'
            set_error_handler(function (int $no, string $str): bool {
                throw new ErrorException($str, 0, $no);
            });
            \Sereno\Sereno::init(%KEY%, %URL%);
            try {
                trigger_error('caught one', E_USER_WARNING);
            } catch (ErrorException $e) {
                echo 'CAUGHT:'.$e->getMessage().';';
            }
            trigger_error('uncaught one', E_USER_WARNING);
            PHP);

        [$out, $err, $code] = $this->runScript($script);

        $this->assertSame('CAUGHT:caught one;', $out);
        $this->assertSame(255, $code);
        $this->assertStringContainsString('Uncaught ErrorException: uncaught one', $err);
        $this->assertSame(['caught one', 'uncaught one'], array_column($this->events(), 'message'));
    }

    public function test_previous_exception_handler_that_throws_reports_the_original_once_and_propagates(): void
    {
        $script = $this->script(<<<'PHP'
            set_exception_handler(function (Throwable $e): void {
                throw new DomainException('from previous');
            });
            \Sereno\Sereno::init(%KEY%, %URL%);
            throw new RuntimeException('original');
            PHP);

        [, , $code] = $this->runScript($script);

        $this->assertSame(255, $code);
        $this->assertSame(['original'], array_column($this->events(), 'message'));
    }

    public function test_without_key_or_url_nothing_is_registered(): void
    {
        $script = $this->script(<<<'PHP'
            $logger = \Sereno\Sereno::init('', %URL%);
            echo get_class($logger).';';
            echo var_export(set_exception_handler(null), true).';';
            echo var_export(set_error_handler(null), true);
            PHP);

        [$out] = $this->runScript($script);

        $this->assertSame('Monolog\Logger;NULL;NULL', $out);
        $this->assertSame([], $this->events());
    }

    public function test_key_and_url_fall_back_to_the_environment(): void
    {
        $script = $this->script(<<<'PHP'
            \Sereno\Sereno::init();
            \Sereno\Sereno::logger()->warning('from env');
            PHP);

        $this->runScript($script, ['SERENO_KEY' => self::KEY, 'SERENO_URL' => 'http://127.0.0.1:'.$this->port]);

        $this->assertSame(['from env'], array_column($this->events(), 'message'));
    }

    public function test_handlers_can_be_disabled_and_init_is_idempotent(): void
    {
        $script = $this->script(<<<'PHP'
            $off = \Sereno\Sereno::init(%KEY%, %URL%, ['handlers' => false]);
            $prev = set_error_handler(null);
            echo var_export($prev, true).';';
            $a = \Sereno\Sereno::init(%KEY%, %URL%);
            $b = \Sereno\Sereno::init(%KEY%, %URL%);
            echo var_export($a === $b, true).';';
            $a->warning('once');
            trigger_error('chained once', E_USER_WARNING);
            PHP);

        [$out] = $this->runScript($script);

        $this->assertSame('NULL;true;', $out);
        $this->assertSame(['once', 'chained once'], array_column($this->events(), 'message'));
    }

    public function test_level_and_timeout_fall_back_to_the_environment_and_options_win(): void
    {
        $script = $this->script(<<<'PHP'
            $logger = \Sereno\Sereno::init(%KEY%, %URL%, ['handlers' => false]);
            $logger->info('dropped');
            $logger->warning('kept');
            PHP);

        $this->runScript($script, ['SERENO_LEVEL' => 'warning', 'SERENO_TIMEOUT' => '3']);
        $this->assertSame(['kept'], array_column($this->events(), 'message'));

        $script = $this->script(<<<'PHP'
            $logger = \Sereno\Sereno::init(%KEY%, %URL%, ['handlers' => false, 'level' => 'debug']);
            $logger->info('option wins');
            PHP);

        $this->runScript($script, ['SERENO_LEVEL' => 'error']);
        $this->assertSame(['kept', 'option wins'], array_column($this->events(), 'message'));
    }

    public function test_invalid_level_in_the_environment_falls_back_to_debug(): void
    {
        $script = $this->script(<<<'PHP'
            $logger = \Sereno\Sereno::init(%KEY%, %URL%, ['handlers' => false]);
            $logger->debug('still sent');
            PHP);

        [, , $code] = $this->runScript($script, ['SERENO_LEVEL' => 'nonsense']);

        $this->assertSame(0, $code);
        $this->assertSame(['still sent'], array_column($this->events(), 'message'));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function events(): array
    {
        $file = $this->dir.'/requests.log';
        $events = [];

        foreach (is_file($file) ? file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [] as $line) {
            $request = json_decode($line, true);
            array_push($events, ...json_decode($request['body'], true)['events']);
        }

        return $events;
    }

    private function script(string $body): string
    {
        $code = strtr($body, [
            '%KEY%' => var_export(self::KEY, true),
            '%URL%' => var_export('http://127.0.0.1:'.$this->port, true),
        ]);
        $path = $this->dir.'/script.php';
        file_put_contents($path, "<?php\nrequire ".var_export(dirname(__DIR__).'/vendor/autoload.php', true).";\n".$code."\n");

        return $path;
    }

    /**
     * @param  array<string, string>  $env
     * @return array{string, string, int}
     */
    private function runScript(string $script, array $env = []): array
    {
        $process = proc_open(
            [PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=0', $script],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            array_merge(getenv(), $env),
        );
        $this->assertIsResource($process);

        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [$out, $err, proc_close($process)];
    }

    private function startServer(): void
    {
        $this->process = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:'.$this->port, __DIR__.'/server/router.php'],
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            null,
            array_merge(getenv(), ['SERENO_TEST_DIR' => $this->dir, 'PHP_CLI_SERVER_WORKERS' => '1']),
        );
        $this->assertIsResource($this->process);

        for ($i = 0; $i < 100; $i++) {
            $socket = @fsockopen('127.0.0.1', $this->port, $errno, $errstr, 0.1);

            if ($socket !== false) {
                fclose($socket);

                return;
            }

            usleep(50_000);
        }

        $this->fail('Test server did not start');
    }

    private function freePort(): int
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        $name = (string) stream_socket_get_name($server, false);
        fclose($server);

        return (int) substr($name, strrpos($name, ':') + 1);
    }
}
