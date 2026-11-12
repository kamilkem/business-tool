<?php

declare(strict_types=1);

namespace App\Ksef\Domain;

use App\Ksef\Domain\Exception\KsefCredentialsRejectedException;
use App\Ksef\Domain\Exception\KsefRateLimitedException;
use App\Ksef\Domain\Exception\KsefRequestFailedException;
use App\Ksef\Domain\Exception\KsefUnavailableException;
use App\Ksef\Domain\Model\KsefInvoiceMetadataPage;
use App\Ksef\Domain\Model\KsefPurchaseInvoiceQuery;
use App\Ksef\Domain\Model\KsefSession;

/**
 * The KSeF API 2.0, reduced to what the purchase invoice import needs (ADR 0023 §1, ADR 0024 §3).
 * The environment (TEST, DEMO, PROD) is one per deployment. Every method may throw
 * KsefRateLimitedException (429, never retried), KsefUnavailableException (network or 5xx after the
 * retries) or KsefRequestFailedException (an answer the client does not expect).
 *
 * The client refreshes the session's access token by itself when it is about to expire.
 */
interface KsefClientInterface
{
    /**
     * Authenticates with a KSeF token for the context `$nip`: challenge, token encrypted with KSeF's
     * public key, status polling, token redemption.
     *
     * @throws KsefCredentialsRejectedException the token is wrong, revoked, inactive or not allowed for the NIP
     * @throws KsefRateLimitedException
     * @throws KsefUnavailableException
     * @throws KsefRequestFailedException
     */
    public function authenticateWithKsefToken(string $nip, #[\SensitiveParameter] string $ksefToken): KsefSession;

    /**
     * Invoices bought by the context's NIP (`Subject2`), one page of at most 250.
     *
     * @throws KsefCredentialsRejectedException the token lacks the InvoiceRead permission
     * @throws KsefRateLimitedException
     * @throws KsefUnavailableException
     * @throws KsefRequestFailedException
     */
    public function queryPurchaseInvoiceMetadata(KsefSession $session, KsefPurchaseInvoiceQuery $query): KsefInvoiceMetadataPage;

    /**
     * The invoice's XML exactly as KSeF stores it.
     *
     * @throws KsefCredentialsRejectedException the token lacks the InvoiceRead permission
     * @throws KsefRateLimitedException
     * @throws KsefUnavailableException
     * @throws KsefRequestFailedException
     */
    public function downloadInvoice(KsefSession $session, string $ksefNumber): string;

    /**
     * Revokes the session and its refresh token (`DELETE /auth/sessions/current`).
     *
     * @throws KsefRateLimitedException
     * @throws KsefUnavailableException
     * @throws KsefRequestFailedException
     */
    public function endSession(KsefSession $session): void;
}
