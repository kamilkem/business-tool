<?php

declare(strict_types=1);

namespace App\Ksef\Domain\Exception;

/**
 * The date the KSeF import starts at is after today in the organization's time zone, or before
 * KSeF API 2.0 became mandatory (ADR 0038 §3).
 */
final class InvalidSyncStartDateException extends KsefException
{
    public static function inTheFuture(string $startDate, string $today): self
    {
        return new self(\sprintf('The KSeF import cannot start on %s: the date is after today (%s).', $startDate, $today), ['startDate' => $startDate, 'today' => $today], variant: __FUNCTION__);
    }

    public static function beforeKsef(string $startDate, string $earliest): self
    {
        return new self(\sprintf('The KSeF import cannot start on %s: KSeF holds invoices from %s.', $startDate, $earliest), ['startDate' => $startDate, 'earliest' => $earliest], variant: __FUNCTION__);
    }
}
