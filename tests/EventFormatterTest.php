<?php

declare(strict_types=1);

namespace Sereno\Tests;

use DateTimeImmutable;
use Exception;
use JsonSerializable;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;
use Sereno\EventFormatter;
use Stringable;
use Throwable;

final class FixtureObject {}

final class EventFormatterTest extends TestCase
{
    /**
     * @return array<string, array{array<string, mixed>, array<string, mixed>}>
     */
    public static function vectors(): array
    {
        $vectors = json_decode((string) file_get_contents(__DIR__.'/fixtures/vectores.json'), true, 512, JSON_THROW_ON_ERROR);
        $cases = [];

        foreach ($vectors as $vector) {
            $cases[$vector['name']] = [$vector['input'], $vector['expected']];
        }

        return $cases;
    }

    public function test_there_are_vectors(): void
    {
        $this->assertGreaterThan(20, count(self::vectors()));
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $expected
     */
    #[DataProvider('vectors')]
    public function test_formats_vector(array $input, array $expected): void
    {
        $record = new LogRecord(
            datetime: new DateTimeImmutable($input['time']),
            channel: 'test',
            level: Level::fromName(ucfirst($input['level'])),
            message: $this->resolve($input['message']),
            context: $this->resolve($input['context'] ?? []),
        );

        $event = (new EventFormatter)->format($record);

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $event['id'],
        );
        unset($event['id']);

        $this->assertSame($this->resolveExpected($this->resolve($expected), $event), $event);
    }

    public function test_ids_are_unique(): void
    {
        $record = new LogRecord(new DateTimeImmutable, 'test', Level::Info, 'm');
        $formatter = new EventFormatter;

        $this->assertNotSame($formatter->format($record)['id'], $formatter->format($record)['id']);
    }

    public function test_invalid_utf8_in_message_becomes_question_mark(): void
    {
        $this->assertSame('a?b', $this->formatRecord("a\xFFb")['message']);
    }

    public function test_trim_handles_long_whitespace_runs(): void
    {
        $inner = 'a'.str_repeat(' ', 8000).'a';
        $leading = str_repeat(' ', 8000).'a';

        $this->assertSame($inner, $this->formatRecord($inner)['message']);
        $this->assertSame($leading, $this->formatRecord($leading)['message']);
        $this->assertSame('-', $this->formatRecord(str_repeat(' ', 8192))['message']);
    }

    public function test_trim_falls_back_when_pcre_fails(): void
    {
        $jit = ini_get('pcre.jit');
        $limit = ini_get('pcre.backtrack_limit');
        ini_set('pcre.jit', '0');
        ini_set('pcre.backtrack_limit', '1');

        try {
            $message = $this->formatRecord("  hola  mundo  \n")['message'];
        } finally {
            ini_set('pcre.jit', (string) $jit);
            ini_set('pcre.backtrack_limit', (string) $limit);
        }

        $this->assertSame("  hola  mundo  \n", $message);
    }

    public function test_invalid_utf8_in_context_key_becomes_question_mark(): void
    {
        $this->assertSame(['k?' => 'v'], $this->formatRecord('m', ["k\xFF" => 'v'])['context']);
    }

    public function test_json_serializable_is_expanded(): void
    {
        $value = new class implements JsonSerializable
        {
            public function jsonSerialize(): array
            {
                return ['a' => 1, 'b' => ['c' => "x\xFF"]];
            }
        };

        $this->assertSame(['v' => ['a' => 1, 'b' => ['c' => 'x?']]], $this->formatRecord('m', ['v' => $value])['context']);
    }

    /**
     * @return array<string, array{mixed, mixed}>
     */
    public static function scalarJsonSerializables(): array
    {
        return [
            'string with invalid utf-8' => ["x\xFF", 'x?'],
            'int' => [42, 42],
            'bool' => [true, true],
        ];
    }

    #[DataProvider('scalarJsonSerializables')]
    public function test_json_serializable_returning_scalar_is_kept(mixed $returned, mixed $expected): void
    {
        $value = new class($returned) implements JsonSerializable
        {
            public function __construct(private mixed $returned) {}

            public function jsonSerialize(): mixed
            {
                return $this->returned;
            }
        };

        $this->assertSame(['v' => $expected], $this->formatRecord('m', ['v' => $value])['context']);
    }

    public function test_stringable_is_cast_to_string(): void
    {
        $value = new class implements Stringable
        {
            public function __toString(): string
            {
                return 'text';
            }
        };

        $this->assertSame(['v' => 'text'], $this->formatRecord('m', ['v' => $value])['context']);
    }

    public function test_nested_exception_is_serialized(): void
    {
        $context = $this->formatRecord('m', ['wrapper' => ['error' => new RuntimeException('boom')]])['context'];

        $this->assertSame(RuntimeException::class, $context['wrapper']['error']['class']);
        $this->assertSame('boom', $context['wrapper']['error']['message']);
        $this->assertArrayHasKey('trace', $context['wrapper']['error']);
    }

    public function test_nested_exception_has_location(): void
    {
        $exception = $this->makeException(['class' => RuntimeException::class, 'message' => 'boom', 'file' => '/app/Service.php', 'line' => 42]);

        $context = $this->formatRecord('m', ['wrapper' => ['error' => $exception]])['context'];

        $this->assertSame('/app/Service.php:42', $context['wrapper']['error']['location']);
    }

    public function test_invalid_utf8_in_nested_exception_message_becomes_question_mark(): void
    {
        $context = $this->formatRecord('m', ['wrapper' => ['error' => new RuntimeException("boom\xFF")]])['context'];

        $this->assertSame('boom?', $context['wrapper']['error']['message']);
    }

    /**
     * @param  array<mixed>  $context
     * @return array<string, mixed>
     */
    private function formatRecord(string $message, array $context = []): array
    {
        return (new EventFormatter)->format(new LogRecord(new DateTimeImmutable, 'test', Level::Info, $message, $context));
    }

    private function resolve(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (count($value) === 1) {
            switch (array_key_first($value)) {
                case '$repeat':
                    return str_repeat($value['$repeat'][0], $value['$repeat'][1]);
                case '$float':
                    return match ($value['$float']) {
                        'NaN' => NAN,
                        'Infinity' => INF,
                        '-Infinity' => -INF,
                    };
                case '$date':
                    return new DateTimeImmutable($value['$date']);
                case '$object':
                    return new FixtureObject;
                case '$exception':
                    return $this->makeException($value['$exception']);
            }
        }

        return array_map(fn ($item) => $this->resolve($item), $value);
    }

    /**
     * @param  array{class: string, message: string, file?: string, line?: int, trace?: string}  $spec
     */
    private function makeException(array $spec): Throwable
    {
        $exception = new RuntimeException($spec['message']);

        foreach (['file' => $spec['file'] ?? '', 'line' => $spec['line'] ?? 0] as $property => $value) {
            (new ReflectionProperty(Exception::class, $property))->setValue($exception, $value);
        }

        if (isset($spec['trace'])) {
            $pattern = '/\A#0 \[internal function\]: (.*)\(\)\n#1 \{main\}\z/su';
            $this->assertSame(1, preg_match($pattern, $spec['trace'], $parts));
            (new ReflectionProperty(Exception::class, 'trace'))->setValue($exception, [['function' => $parts[1]]]);
        }

        return $exception;
    }

    private function resolveExpected(mixed $expected, mixed $actual): mixed
    {
        if (is_array($expected)) {
            foreach ($expected as $key => $value) {
                $expected[$key] = $this->resolveExpected($value, is_array($actual) ? ($actual[$key] ?? null) : null);
            }

            return $expected;
        }

        if ($expected === '<trace>') {
            $this->assertIsString($actual);
            $this->assertLessThanOrEqual(2000, mb_strlen($actual));

            return $actual;
        }

        if (is_string($expected)) {
            return str_replace('[object <Clase>]', '[object '.FixtureObject::class.']', $expected);
        }

        return $expected;
    }
}
