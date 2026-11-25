<?php

declare(strict_types=1);

namespace App\Ksef\Application\Job\ImportKsefInvoices;

use App\Ksef\Domain\Model\KsefErrorCode;
use App\Ksef\Domain\Model\KsefImportMethod;

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

    /** Where the cursor stands: every invoice stored in KSeF before it is done. */
    public \DateTimeImmutable $cursor;

    /** How the run fetches the invoices; it switches to the export once more than 64 wait (ADR 0039 §1). */
    public KsefImportMethod $method = KsefImportMethod::Single;

    /** Waiting invoices the run has seen (FA(3), issued from the start date, not in Expenses yet). */
    public int $waiting = 0;

    /** Of those, the ones it is done with: imported, or skipped as unreadable. */
    public int $handled = 0;

    /** The run reached KSeF's high-water mark or its own start: nothing else is waiting. */
    public bool $caughtUp = false;

    /** The run ends with a package KSeF has not finished yet: what is left is not known. */
    public bool $exportPending = false;

    public function __construct(
        public readonly \DateTimeImmutable $startedAt,
        public readonly \DateTimeImmutable $deadline,
        \DateTimeImmutable $from,
    ) {
        $this->cursor = $from;
    }

    /**
     * How many waiting invoices are left as far as the run knows (ADR 0039 §7): exact when it caught
     * up, else at least that many; null when it cannot tell (nothing counted, or a package pending).
     */
    public function backlogCount(): ?int
    {
        if ($this->caughtUp) {
            return 0;
        }
        $left = max(0, $this->waiting - $this->handled);

        return $this->exportPending || 0 === $left ? null : $left;
    }

    public function backlogIsMinimum(): bool
    {
        return !$this->caughtUp;
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
