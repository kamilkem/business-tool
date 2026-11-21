<?php

declare(strict_types=1);

namespace App\Ksef\Domain\Exception;

/**
 * KSeF refused the organization's credentials: the KSeF token is wrong, revoked or inactive, cannot
 * be used for the NIP, lacks the InvoiceRead permission, or authentication was blocked. Retrying does
 * not help; the owner has to save a new token (ADR 0023 §5: the connection becomes
 * `credentials_rejected`).
 */
final class KsefCredentialsRejectedException extends KsefException
{
    /** @param list<string> $details */
    private function __construct(string $message, public readonly ?int $statusCode, public readonly array $details, string $variant)
    {
        parent::__construct($message, ['statusCode' => $statusCode], $variant);
    }

    /**
     * A final status of the authentication operation (`GET /auth/{referenceNumber}`): 415, 425,
     * 450, 460, 470 or 480.
     *
     * @param list<string> $details
     */
    public static function authenticationFailed(int $statusCode, string $description, array $details): self
    {
        return new self(
            \sprintf('KSeF rejected the authentication (status %d: %s%s).', $statusCode, $description, [] === $details ? '' : '; '.implode('; ', $details)),
            $statusCode,
            $details,
            __FUNCTION__,
        );
    }

    /** HTTP 403 on a call that needs a permission the token does not have (InvoiceRead). */
    public static function missingPermission(string $detail): self
    {
        return new self(\sprintf('The KSeF token lacks a required permission: %s', $detail), null, [], __FUNCTION__);
    }
}
