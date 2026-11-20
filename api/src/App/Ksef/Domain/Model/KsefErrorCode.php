<?php

declare(strict_types=1);

namespace App\Ksef\Domain\Model;

/**
 * Why the last check (test connection) or sync run against KSeF did not succeed. Expected outcomes,
 * recorded on the connection rather than thrown at the caller (ADR 0023 §5).
 */
enum KsefErrorCode: string
{
    /** KSeF refused the token: wrong, revoked, inactive, not for this NIP or without InvoiceRead. */
    case CredentialsRejected = 'credentials_rejected';

    /** The stored token does not decrypt or is gone (ADR 0026 §7). */
    case TokenUnreadable = 'token_unreadable';

    /** KSeF answered 429; a later run continues. */
    case RateLimited = 'rate_limited';

    /** Network errors or 5xx answers that persisted through the retries. */
    case Unavailable = 'unavailable';

    /** An answer the client does not expect: a bug or an API change. */
    case RequestFailed = 'request_failed';

    /**
     * The run completed, but some FA(3) invoices could not be read or stored (the parser or Expenses
     * refused them). They were skipped; the message names their KSeF numbers.
     */
    case InvoiceUnreadable = 'invoice_unreadable';

    /** The run stopped because of it; otherwise (unreadable invoices) the run still completed. */
    public function endsRun(): bool
    {
        return self::InvoiceUnreadable !== $this;
    }

    /** The token cannot work any more: the owner has to save a new one. */
    public function rejectsCredentials(): bool
    {
        return self::CredentialsRejected === $this || self::TokenUnreadable === $this;
    }
}
