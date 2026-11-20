<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Application;

use Psr\Log\AbstractLogger;

/** Fake logger: keeps every entry for assertions. */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: array<mixed>}> */
    public array $records = [];

    /** @param array<mixed> $context */
    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }

    /** @return list<string> the messages logged at this level */
    public function messages(string $level): array
    {
        return array_values(array_map(
            static fn (array $record): string => $record['message'],
            array_filter($this->records, static fn (array $record): bool => $record['level'] === $level),
        ));
    }

    /** Everything logged, as one string: to check that a value never appears. */
    public function dump(): string
    {
        return (string) json_encode($this->records);
    }
}
