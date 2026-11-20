<?php

declare(strict_types=1);

namespace App\Ksef\Application\Job\ImportKsefInvoices;

use App\Ksef\Domain\Model\KsefErrorCode;

/** What one import run did so far; recorded on the connection when it ends. */
final class ImportProgress
{
    public int $imported = 0;

    public int $skipped = 0;

    /** @var array<string, string> KSeF number => why the parser or Expenses refused it */
    public array $unreadable = [];

    public ?KsefErrorCode $error = null;

    public ?string $errorMessage = null;

    public ?\DateTimeImmutable $retryNotBefore = null;

    public function __construct(
        public readonly \DateTimeImmutable $startedAt,
        public readonly \DateTimeImmutable $deadline,
    ) {
    }

    public function fail(KsefErrorCode $error, string $message, ?\DateTimeImmutable $retryNotBefore = null): void
    {
        $this->error = $error;
        $this->errorMessage = $message;
        $this->retryNotBefore = $retryNotBefore;
    }

    public function skipUnreadable(string $ksefNumber, string $reason): void
    {
        ++$this->skipped;
        $this->unreadable[$ksefNumber] = $reason;
    }

    /** The error to record: the one that ended the run, else unreadable invoices, else none. */
    public function recordedError(): ?KsefErrorCode
    {
        return $this->error ?? ([] === $this->unreadable ? null : KsefErrorCode::InvoiceUnreadable);
    }

    public function recordedErrorMessage(): ?string
    {
        if (null !== $this->error) {
            return $this->errorMessage;
        }
        if ([] === $this->unreadable) {
            return null;
        }

        return \sprintf(
            'Skipped %d invoice(s) that could not be read: %s',
            \count($this->unreadable),
            implode('; ', array_map(static fn (string $number, string $reason): string => $number.' ('.$reason.')', array_keys($this->unreadable), $this->unreadable)),
        );
    }
}
