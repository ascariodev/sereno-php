<?php

declare(strict_types=1);

namespace Sereno;

use Closure;
use Monolog\Handler\AbstractHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Throwable;
use WeakReference;

class SerenoHandler extends AbstractHandler
{
    public const BATCH_SIZE = 100;

    public const MAX_BUFFER = 1000;

    /** Shared by every instance: while one handler sends or reports a failure, records reaching any Sereno handler are not buffered. */
    private static bool $sending = false;

    /** @var list<array<string, mixed>> */
    private array $buffer = [];

    private int $dropped = 0;

    private readonly bool $configured;

    private readonly string $url;

    private readonly string $key;

    private readonly int|float|string|null $timeout;

    private readonly EventFormatter $formatter;

    private readonly Closure $notifier;

    private ?object $transport = null;

    /**
     * @param  Transport|Closure|null  $transport  A Transport, or a factory (url, key, timeout) returning an object with send(array): DeliveryResult.
     * @param  Closure|null  $notifier  Receives the failure message; defaults to error_log.
     * @param  Closure|null  $shutdownRegistrar  Receives the flush callback; defaults to register_shutdown_function.
     */
    public function __construct(
        ?string $url,
        ?string $key,
        int|string|Level $level = Level::Debug,
        bool $bubble = true,
        int|float|string|null $timeout = null,
        Transport|Closure|null $transport = null,
        ?EventFormatter $formatter = null,
        ?Closure $shutdownRegistrar = null,
        ?Closure $notifier = null,
    ) {
        parent::__construct($level, $bubble);

        $this->url = trim((string) $url);
        $this->key = trim((string) $key);
        $this->configured = $this->url !== '' && $this->key !== '';
        $this->timeout = $timeout;
        $this->formatter = $formatter ?? new EventFormatter();
        $this->notifier = $notifier ?? static function (string $message): void {
            error_log($message);
        };

        if ($transport instanceof Transport) {
            $this->transport = $transport;
        } elseif ($transport instanceof Closure) {
            $this->transport = $transport($this->url, $this->key, $this->timeout);
        }

        if ($this->configured) {
            $this->registerShutdown($shutdownRegistrar);
        }
    }

    public function isHandling(LogRecord $record): bool
    {
        return $this->configured && ! self::$sending && parent::isHandling($record);
    }

    public function handle(LogRecord $record): bool
    {
        try {
            if (! $this->isHandling($record)) {
                return false;
            }

            if (count($this->buffer) >= self::MAX_BUFFER) {
                $this->dropped++;

                return $this->bubble === false;
            }

            try {
                $this->buffer[] = $this->formatter->format($record);
            } catch (Throwable $e) {
                $this->report('Sereno log event could not be formatted: '.$e->getMessage());

                return $this->bubble === false;
            }

            if (count($this->buffer) >= self::BATCH_SIZE) {
                $this->flush();
            }
        } catch (Throwable) {
        }

        return $this->bubble === false;
    }

    public function flush(): void
    {
        try {
            if (self::$sending || ($this->buffer === [] && $this->dropped === 0)) {
                return;
            }

            $events = $this->buffer;
            $dropped = $this->dropped;
            $this->buffer = [];
            $this->dropped = 0;

            self::$sending = true;

            try {
                if ($dropped > 0) {
                    $this->report(sprintf('Sereno dropped %d log events: buffer limit of %d reached', $dropped, self::MAX_BUFFER));
                }

                foreach (array_chunk($events, self::BATCH_SIZE) as $batch) {
                    $this->send($batch);
                }
            } finally {
                self::$sending = false;
            }
        } catch (Throwable) {
        }
    }

    public function close(): void
    {
        $this->flush();
    }

    public function reset(): void
    {
        $this->flush();
    }

    /**
     * @param  list<array<string, mixed>>  $batch
     */
    private function send(array $batch): void
    {
        try {
            $this->transport ??= new Transport($this->url, $this->key, $this->timeout);
            $result = $this->transport->send($batch);
        } catch (Throwable $e) {
            $this->report(sprintf('Sereno log delivery failed: error=%s events=%d', Redactor::redact($e->getMessage(), $this->key), count($batch)));

            return;
        }

        if ($result->isSent()) {
            return;
        }

        if ($result->isRejected()) {
            $this->report(sprintf(
                'Sereno log delivery failed: status=%d events=%d body=%s',
                $result->code,
                count($batch),
                mb_substr((string) $result->body, 0, 500),
            ));

            return;
        }

        $this->report(sprintf('Sereno log delivery failed: error=%s events=%d', (string) $result->error, count($batch)));
    }

    private function report(string $message): void
    {
        $wasSending = self::$sending;
        self::$sending = true;

        try {
            ($this->notifier)($message);
        } catch (Throwable) {
        } finally {
            self::$sending = $wasSending;
        }
    }

    private function registerShutdown(?Closure $registrar): void
    {
        $ref = WeakReference::create($this);
        $flush = static function () use ($ref): void {
            try {
                $ref->get()?->flush();
            } catch (Throwable) {
            }
        };

        try {
            ($registrar ?? register_shutdown_function(...))($flush);
        } catch (Throwable) {
        }
    }
}
