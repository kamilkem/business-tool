<?php

declare(strict_types=1);

namespace App\Ksef\Domain;

use App\Ksef\Domain\Exception\KsefCredentialsRejectedException;
use App\Ksef\Domain\Exception\KsefRateLimitedException;
use App\Ksef\Domain\Exception\KsefRequestFailedException;
use App\Ksef\Domain\Exception\KsefUnavailableException;
use App\Ksef\Domain\Model\KsefInvoiceExportStatus;
use App\Ksef\Domain\Model\KsefInvoiceMetadataPage;
use App\Ksef\Domain\Model\KsefInvoicePackage;
use App\Ksef\Domain\Model\KsefPurchaseInvoiceQuery;
use App\Ksef\Domain\Model\KsefSession;

/**
 * The KSeF API 2.0, reduced to what the purchase invoice import needs (ADR 0023 §1, ADR 0024 §3,
 * ADR 0039).
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
     * Requests a package export of the invoices bought by the context's NIP (`Subject2`) in the
     * query's permanent-storage range, restricted to the high-water mark (ADR 0039 §2). The package is
     * encrypted with a key the client derives from `$keyId` and the NIP (ADR 0039 §3); the same key id
     * opens it later. Returns the export's reference number.
     *
     * @throws KsefCredentialsRejectedException the token lacks the InvoiceRead permission
     * @throws KsefRateLimitedException
     * @throws KsefUnavailableException
     * @throws KsefRequestFailedException
     */
    public function startPurchaseInvoiceExport(KsefSession $session, KsefPurchaseInvoiceQuery $range, string $keyId): string;

    /**
     * The export's status, waiting up to `$waitSeconds` while KSeF is still building the package
     * (ADR 0039 §4). Expected outcomes (expired, nothing to export, failed) are states, not exceptions.
     *
     * @throws KsefCredentialsRejectedException the token lacks the InvoiceRead permission
     * @throws KsefRateLimitedException
     * @throws KsefUnavailableException
     * @throws KsefRequestFailedException
     */
    public function getPurchaseInvoiceExport(KsefSession $session, string $referenceNumber, int $waitSeconds): KsefInvoiceExportStatus;

    /**
     * Downloads, checks and decrypts the package's parts and opens the archive (ADR 0039 §5). The
     * caller closes the contents.
     *
     * @throws KsefUnavailableException
     * @throws KsefRequestFailedException a part does not match its hash, does not decrypt, or the archive is unreadable
     */
    public function openInvoicePackage(KsefSession $session, string $keyId, KsefInvoicePackage $package): KsefInvoicePackageContentsInterface;

    /**
     * Revokes the session and its refresh token (`DELETE /auth/sessions/current`).
     *
     * @throws KsefRateLimitedException
     * @throws KsefUnavailableException
     * @throws KsefRequestFailedException
     */
    public function endSession(KsefSession $session): void;
}
