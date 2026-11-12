<?php

declare(strict_types=1);

namespace App\Ksef\Domain\Model;

/**
 * A query for invoices bought by the context's NIP (`Subject2`), by permanent-storage date in
 * ascending order and restricted to the high-water mark, as the incremental sync requires
 * (ADR 0023 §4). KSeF accepts a range of at most 100 days.
 */
final readonly class KsefPurchaseInvoiceQuery
{
    public const int MAX_RANGE_DAYS = 100;
    public const int PAGE_SIZE = 250;

    public \DateTimeImmutable $from;
    public \DateTimeImmutable $to;

    public function __construct(\DateTimeImmutable $from, \DateTimeImmutable $to, public int $pageOffset = 0)
    {
        $utc = new \DateTimeZone('UTC');
        $this->from = $from->setTimezone($utc);
        $this->to = $to->setTimezone($utc);

        if ($this->to <= $this->from) {
            throw new \InvalidArgumentException('The end of a KSeF query range must be after its start.');
        }
        if ($this->to > $this->from->modify(\sprintf('+%d days', self::MAX_RANGE_DAYS))) {
            throw new \InvalidArgumentException(\sprintf('A KSeF query range spans at most %d days.', self::MAX_RANGE_DAYS));
        }
        if ($pageOffset < 0) {
            throw new \InvalidArgumentException('The page offset must not be negative.');
        }
    }

    public function nextPage(): self
    {
        return new self($this->from, $this->to, $this->pageOffset + 1);
    }
}
