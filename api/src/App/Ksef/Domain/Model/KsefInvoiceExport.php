<?php

declare(strict_types=1);

namespace App\Ksef\Domain\Model;

/**
 * A package export requested from KSeF and not imported yet (ADR 0039 §2–3): its reference number,
 * the id its encryption key is derived from (not a secret), and the permanent-storage range it covers.
 * Instants are kept to the whole second, rounded down, as the connection stores them: a range that
 * starts a fraction earlier only repeats invoices the KSeF number passes over.
 */
final readonly class KsefInvoiceExport
{
    public \DateTimeImmutable $from;
    public \DateTimeImmutable $to;
    public \DateTimeImmutable $startedAt;

    public function __construct(
        public string $referenceNumber,
        public string $keyId,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        \DateTimeImmutable $startedAt,
    ) {
        if ('' === $referenceNumber || '' === $keyId) {
            throw new \InvalidArgumentException('A KSeF export needs a reference number and a key id.');
        }
        $this->from = self::wholeSecond($from);
        $this->to = self::wholeSecond($to);
        $this->startedAt = self::wholeSecond($startedAt);
        if ($this->to <= $this->from) {
            throw new \InvalidArgumentException('The end of a KSeF export range must be after its start.');
        }
    }

    /** Whether the import can go on with this export from the cursor: the cursor lies in its range. */
    public function covers(\DateTimeImmutable $cursor): bool
    {
        return $this->from <= $cursor && $cursor < $this->to;
    }

    public static function wholeSecond(\DateTimeImmutable $instant): \DateTimeImmutable
    {
        $utc = $instant->setTimezone(new \DateTimeZone('UTC'));

        return $utc->setTime((int) $utc->format('H'), (int) $utc->format('i'), (int) $utc->format('s'));
    }
}
