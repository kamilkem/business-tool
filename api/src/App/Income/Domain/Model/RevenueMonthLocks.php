<?php

declare(strict_types=1);

namespace App\Income\Domain\Model;

use App\Income\Domain\Exception\RevenueMonthClosedException;
use App\Shared\Domain\Time\BusinessDate;

/**
 * The closed Income months of the organization (ADR 0034 §2): every write of a revenue entry dated in
 * one of them is refused. Command handlers load it from the repository and ask it before they write.
 */
final readonly class RevenueMonthLocks
{
    /** @var array<string, true> by YYYY-MM */
    private array $closed;

    /** @param list<string> $closedMonths YYYY-MM */
    public function __construct(array $closedMonths)
    {
        $this->closed = array_fill_keys($closedMonths, true);
    }

    public static function none(): self
    {
        return new self([]);
    }

    public function any(): bool
    {
        return [] !== $this->closed;
    }

    public function isClosed(BusinessDate $date): bool
    {
        return isset($this->closed[self::monthOf($date)]);
    }

    /** @throws RevenueMonthClosedException when the date is in a closed month */
    public function assertOpen(BusinessDate $date): void
    {
        if ($this->isClosed($date)) {
            throw RevenueMonthClosedException::of(self::monthOf($date), $date->toString());
        }
    }

    private static function monthOf(BusinessDate $date): string
    {
        return substr($date->toString(), 0, 7);
    }
}
