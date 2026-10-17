<?php

declare(strict_types=1);

namespace Sereno;

use Monolog\Level;
use Monolog\Logger;
use Throwable;

final class Sereno
{
    private const FATAL_TYPES = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR;

    private const DEFAULT_ERROR_LEVEL = E_WARNING | E_USER_WARNING | E_CORE_WARNING | E_COMPILE_WARNING
        | E_USER_ERROR | E_RECOVERABLE_ERROR;

    private static ?Logger $logger = null;

    private static ?SerenoHandler $handler = null;

    private static bool $active = false;

    private static int $errorLevel = self::DEFAULT_ERROR_LEVEL;

    /** @var callable|null */
    private static $previousExceptionHandler = null;

    /** @var callable|null */
    private static $previousErrorHandler = null;

    /** @var array{int, string, string, int}|null */
    private static ?array $lastReportedError = null;

    private static ?Throwable $thrownByPreviousErrorHandler = null;

    private static bool $suppressShutdownReport = false;

    private function __construct() {}

    /**
     * Duplicates: a fatal already reported by handleError (E_USER_ERROR) is not reported again on shutdown.
     * If the previous error handler throws, the error is reported once, the exception is not reported again by
     * handleException, and it propagates as it would without the SDK. If the previous exception handler throws,
     * the original exception stays reported once, the new one propagates and its fatal is not reported on shutdown.
     *
     * @param  array{level?: int|string|Level, timeout?: int|float|string|null, errorLevel?: int, handlers?: bool}  $options
     */
    public static function init(?string $key = null, ?string $url = null, array $options = []): Logger
    {
        if (self::$active && self::$logger !== null) {
            return self::$logger;
        }

        $key = self::setting($key, 'SERENO_KEY');
        $url = self::setting($url, 'SERENO_URL');

        $level = self::level($options['level'] ?? self::setting(null, 'SERENO_LEVEL'));
        $timeoutSetting = self::setting(null, 'SERENO_TIMEOUT');
        $timeout = $options['timeout'] ?? ($timeoutSetting !== '' ? $timeoutSetting : null);

        $handler = new SerenoHandler($url, $key, $level, true, $timeout);
        $logger = new Logger('sereno', [$handler]);
        self::$logger = $logger;
        self::$handler = $handler;

        if ($key === '' || $url === '' || ($options['handlers'] ?? true) === false) {
            return $logger;
        }

        self::$active = true;
        self::$errorLevel = (int) ($options['errorLevel'] ?? self::DEFAULT_ERROR_LEVEL);
        self::$previousExceptionHandler = set_exception_handler(self::handleException(...));
        self::$previousErrorHandler = set_error_handler(self::handleError(...));
        register_shutdown_function(self::handleShutdown(...));

        return $logger;
    }

    public static function logger(): ?Logger
    {
        return self::$logger;
    }

    public static function flush(): void
    {
        self::$handler?->flush();
    }

    public static function handleException(Throwable $exception): void
    {
        try {
            if ($exception !== self::$thrownByPreviousErrorHandler) {
                self::$logger?->error($exception->getMessage(), ['exception' => $exception]);
            }
            self::$handler?->flush();
        } catch (Throwable) {
        }

        if (self::$previousExceptionHandler !== null) {
            try {
                (self::$previousExceptionHandler)($exception);
            } catch (Throwable $thrown) {
                self::$suppressShutdownReport = true;

                throw $thrown;
            }

            return;
        }

        error_log(sprintf(
            "PHP Fatal error:  Uncaught %s\n  thrown in %s on line %d",
            $exception,
            $exception->getFile(),
            $exception->getLine(),
        ));
        exit(255);
    }

    public static function handleError(int $errno, string $errstr, string $errfile = '', int $errline = 0): bool
    {
        try {
            if ((error_reporting() & $errno) !== 0 && (self::$errorLevel & $errno) !== 0) {
                self::$logger?->log(self::levelFor($errno), $errstr, ['file' => $errfile, 'line' => $errline, 'errno' => $errno]);
                self::$lastReportedError = [$errno, $errstr, $errfile, $errline];
            }
        } catch (Throwable) {
        }

        if (self::$previousErrorHandler === null) {
            return false;
        }

        try {
            return (self::$previousErrorHandler)($errno, $errstr, $errfile, $errline);
        } catch (Throwable $thrown) {
            self::$thrownByPreviousErrorHandler = $thrown;

            throw $thrown;
        }
    }

    public static function handleShutdown(): void
    {
        try {
            $error = error_get_last();

            $alreadyReported = self::$lastReportedError === [$error['type'] ?? null, $error['message'] ?? null, $error['file'] ?? null, $error['line'] ?? null];

            if ($error !== null && ! $alreadyReported && ! self::$suppressShutdownReport && ($error['type'] & self::FATAL_TYPES) !== 0) {
                self::$logger?->critical($error['message'], ['file' => $error['file'], 'line' => $error['line'], 'errno' => $error['type']]);
            }

            self::$handler?->flush();
        } catch (Throwable) {
        }
    }

    private static function levelFor(int $errno): Level
    {
        return match (true) {
            ($errno & (E_USER_ERROR | E_RECOVERABLE_ERROR)) !== 0 => Level::Error,
            ($errno & (E_WARNING | E_USER_WARNING | E_CORE_WARNING | E_COMPILE_WARNING)) !== 0 => Level::Warning,
            ($errno & (E_NOTICE | E_USER_NOTICE)) !== 0 => Level::Notice,
            default => Level::Info,
        };
    }

    private static function level(int|string|Level $value): Level
    {
        if ($value === '') {
            return Level::Debug;
        }

        try {
            return Logger::toMonologLevel($value);
        } catch (Throwable) {
            return Level::Debug;
        }
    }

    private static function setting(?string $value, string $name): string
    {
        if ($value !== null) {
            return trim($value);
        }

        $env = getenv($name);
        $candidate = $env !== false ? $env : ($_ENV[$name] ?? $_SERVER[$name] ?? '');

        return is_string($candidate) ? trim($candidate) : '';
    }
}
