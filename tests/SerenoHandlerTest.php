<?php

declare(strict_types=1);

namespace Sereno\Tests;

use DateTimeImmutable;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;
use Sereno\DeliveryResult;
use Sereno\EventFormatter;
use Sereno\SerenoHandler;

final class FakeTransport
{
    /** @var list<list<array<string, mixed>>> */
    public array $batches = [];

    public ?DeliveryResult $result = null;

    public ?\Closure $onSend = null;

    public ?\Throwable $throw = null;

    public function send(array $batch): DeliveryResult
    {
        $this->batches[] = $batch;

        if ($this->onSend !== null) {
            ($this->onSend)();
        }

        if ($this->throw !== null) {
            throw $this->throw;
        }

        return $this->result ?? DeliveryResult::sent(202, 1);
    }

    public function events(): int
    {
        return array_sum(array_map('count', $this->batches));
    }
}

final class SerenoHandlerTest extends TestCase
{
    private FakeTransport $transport;

    /** @var list<string> */
    private array $notices = [];

    /** @var list<\Closure> */
    private array $shutdowns = [];

    protected function setUp(): void
    {
        $this->transport = new FakeTransport();
        $this->notices = [];
        $this->shutdowns = [];
    }

    private function handler(?string $url = 'http://sereno.test', ?string $key = 'wsk_x', ?EventFormatter $formatter = null, Level $level = Level::Debug): SerenoHandler
    {
        return new SerenoHandler(
            $url,
            $key,
            $level,
            true,
            null,
            fn () => $this->transport,
            $formatter,
            function (\Closure $flush): void {
                $this->shutdowns[] = $flush;
            },
            function (string $message): void {
                $this->notices[] = $message;
            },
        );
    }

    private function record(string $message = 'hello', Level $level = Level::Error): LogRecord
    {
        return new LogRecord(new DateTimeImmutable(), 'test', $level, $message);
    }

    public function testFlushesWhenBatchReaches100(): void
    {
        $handler = $this->handler();

        for ($i = 0; $i < 99; $i++) {
            $handler->handle($this->record());
        }
        $this->assertSame([], $this->transport->batches);

        $handler->handle($this->record());
        $this->assertCount(1, $this->transport->batches);
        $this->assertCount(100, $this->transport->batches[0]);
        $this->assertSame('error', $this->transport->batches[0][0]['level']);

        $handler->close();
        $this->assertCount(1, $this->transport->batches);
    }

    public function testFlushesOnCloseAndReset(): void
    {
        $handler = $this->handler();
        $handler->handle($this->record());
        $handler->close();
        $this->assertSame(1, $this->transport->events());

        $handler->handle($this->record());
        $handler->reset();
        $this->assertSame(2, $this->transport->events());
    }

    public function testFlushesOnShutdownAndWeakReferenceDoesNotKeepHandlerAlive(): void
    {
        $handler = $this->handler();
        $handler->handle($this->record());
        $this->assertCount(1, $this->shutdowns);

        ($this->shutdowns[0])();
        $this->assertSame(1, $this->transport->events());

        $handler2 = $this->handler();
        $handler2->handle($this->record());
        $shutdown = $this->shutdowns[1];
        $ref = \WeakReference::create($handler2);
        unset($handler2);
        $this->assertNull($ref->get());
        $shutdown();
        $this->assertSame(2, $this->transport->events());
    }

    public function testFailingTransportFactoryLeavesHandlerInertAndReportsOnce(): void
    {
        $handler = new SerenoHandler(
            'http://sereno.test',
            'wsk_secret',
            Level::Debug,
            true,
            null,
            function (): never {
                throw new \RuntimeException('boom wsk_secret');
            },
            null,
            function (Closure $flush): void {
                $this->shutdowns[] = $flush;
            },
            function (string $message): void {
                $this->notices[] = $message;
            },
        );

        $this->assertFalse($handler->isHandling($this->record()));
        $this->assertFalse($handler->handle($this->record()));
        $handler->handle($this->record());
        $handler->close();

        $this->assertSame([], $this->shutdowns);
        $this->assertSame([], $this->transport->batches);
        $this->assertCount(1, $this->notices);
        $this->assertStringContainsString('boom', $this->notices[0]);
        $this->assertStringNotContainsString('wsk_secret', $this->notices[0]);
    }

    public function testInertWithoutUrlOrKey(): void
    {
        foreach ([[null, 'k'], ['http://x', null], ['', ''], ['  ', 'k']] as [$url, $key]) {
            $handler = $this->handler($url, $key);
            $this->assertFalse($handler->isHandling($this->record()));
            $handler->handle($this->record());
            $handler->close();
        }

        $this->assertSame([], $this->transport->batches);
        $this->assertSame([], $this->shutdowns);
    }

    public function testRespectsMinimumLevel(): void
    {
        $handler = $this->handler(level: Level::Warning);
        $handler->handle($this->record('x', Level::Info));
        $handler->close();

        $this->assertSame([], $this->transport->batches);
    }

    public function testBufferCapDropsExcessAndNextSendNotifiesOnce(): void
    {
        $handler = $this->handler();

        $buffer = new \ReflectionProperty(SerenoHandler::class, 'buffer');
        $buffer->setValue($handler, array_fill(0, 1000, ['id' => 'x']));

        $handler->handle($this->record());
        $handler->handle($this->record());
        $this->assertCount(1000, $buffer->getValue($handler));
        $this->assertSame([], $this->transport->batches);

        $handler->close();
        $this->assertSame(1000, $this->transport->events());
        $this->assertCount(1, $this->notices);
        $this->assertStringContainsString('dropped 2', $this->notices[0]);

        $handler->handle($this->record());
        $handler->close();
        $this->assertCount(1, $this->notices);
    }

    public function testRejectionNotifiesWithCodeEventsAndBody(): void
    {
        $this->transport->result = DeliveryResult::rejected(422, str_repeat('a', 900), 1);
        $handler = $this->handler();
        $handler->handle($this->record());
        $handler->handle($this->record());
        $handler->close();

        $this->assertCount(1, $this->notices);
        $this->assertStringContainsString('status=422', $this->notices[0]);
        $this->assertStringContainsString('events=2', $this->notices[0]);
        $this->assertStringContainsString(str_repeat('a', 500), $this->notices[0]);
        $this->assertStringNotContainsString(str_repeat('a', 501), $this->notices[0]);

        $handler->handle($this->record());
        $handler->close();
        $this->assertSame(3, $this->transport->events());
    }

    public function testErrorNotifiesWithMessage(): void
    {
        $this->transport->result = DeliveryResult::error('Connection refused', true, 2);
        $handler = $this->handler();
        $handler->handle($this->record());
        $handler->close();

        $this->assertCount(1, $this->notices);
        $this->assertStringContainsString('error=Connection refused', $this->notices[0]);
        $this->assertStringContainsString('events=1', $this->notices[0]);
    }

    public function testThrownTransportMessageIsRedactedBeforeNotifying(): void
    {
        $this->transport->throw = new \RuntimeException('bad http://user:secret@sereno.test wsk_x');
        $handler = $this->handler();
        $handler->handle($this->record());
        $handler->close();

        $this->assertCount(1, $this->notices);
        $this->assertStringContainsString('error=bad http://sereno.test *** events=1', $this->notices[0]);
    }

    public function testTransportThatThrowsNeverThrows(): void
    {
        $this->transport->throw = new \RuntimeException('boom');
        $handler = $this->handler();
        $handler->handle($this->record());
        $handler->close();

        $this->assertCount(1, $this->notices);
        $this->assertStringContainsString('boom', $this->notices[0]);
    }

    public function testFormatterThatThrowsDiscardsAndNotifies(): void
    {
        $formatter = new class () extends EventFormatter {
            public function format(LogRecord $record): array
            {
                throw new \RuntimeException('cannot format');
            }
        };
        $handler = $this->handler(formatter: $formatter);

        $this->assertFalse($handler->handle($this->record()));
        $handler->close();

        $this->assertSame([], $this->transport->batches);
        $this->assertCount(1, $this->notices);
        $this->assertStringContainsString('cannot format', $this->notices[0]);
    }

    public function testGuardIgnoresRecordsLoggedWhileSending(): void
    {
        $handler = $this->handler();
        $other = $this->handler();
        $this->transport->onSend = function () use ($handler, $other): void {
            $handler->handle($this->record('inner'));
            $other->handle($this->record('inner'));
        };

        $handler->handle($this->record());
        $handler->close();
        $other->close();
        $handler->close();

        $this->assertSame(1, $this->transport->events());
    }

    public function testGuardIgnoresRecordsLoggedWhileReportingAFailure(): void
    {
        $handler = new SerenoHandler(
            'http://sereno.test',
            'k',
            Level::Debug,
            true,
            null,
            fn () => $this->transport,
            null,
            fn (\Closure $c) => null,
            function (string $m) use (&$handler): void {
                $handler->handle($this->record('from notice'));
            },
        );
        $this->transport->result = DeliveryResult::rejected(500, 'x', 2);

        $handler->handle($this->record());
        $handler->close();
        $handler->close();

        $this->assertSame(1, $this->transport->events());
    }

    public function testDefaultNotifierUsesErrorLogWithoutThrowing(): void
    {
        $log = tempnam(sys_get_temp_dir(), 'sereno');
        $previous = ini_set('error_log', $log);
        $this->transport->result = DeliveryResult::rejected(401, 'nope', 1);

        $handler = new SerenoHandler('http://x', 'k', Level::Debug, true, null, fn () => $this->transport, null, fn () => null);
        $handler->handle($this->record());
        $handler->close();

        ini_set('error_log', (string) $previous);
        $this->assertStringContainsString('status=401', (string) file_get_contents($log));
        unlink($log);
    }
}
