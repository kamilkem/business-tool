<?php

declare(strict_types=1);

namespace App\Ksef\Domain\Exception;

/**
 * "Import now" came within 15 minutes of the last request, or while KSeF's Retry-After after a 429 is
 * running (ADR 0023 §5).
 */
final class KsefImportTooSoonException extends KsefException
{
    private function __construct(string $message, public readonly \DateTimeImmutable $retryAt, int $minutes)
    {
        parent::__construct($message, ['retryAt' => $retryAt->format(\DATE_ATOM), 'minutes' => $minutes]);
    }

    public static function until(\DateTimeImmutable $retryAt, \DateTimeImmutable $now): self
    {
        $minutes = max(1, (int) ceil(($retryAt->getTimestamp() - $now->getTimestamp()) / 60));

        return new self(\sprintf('A KSeF import can be started again in %d min.', $minutes), $retryAt, $minutes);
    }
}
