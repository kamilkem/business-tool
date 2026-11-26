<?php

declare(strict_types=1);

namespace App\Purchasing\Domain\Exception;

/**
 * The interpreter cannot answer now. It never reaches an HTTP response (matching runs in jobs, ADR
 * 0025): the job records it and stops, and the items stay as they were, to be matched by a later run.
 */
final class ItemInterpreterUnavailableException extends \RuntimeException
{
    public static function because(string $reason, ?\Throwable $previous = null): self
    {
        return new self('The item interpreter is unavailable: '.$reason, 0, $previous);
    }
}
