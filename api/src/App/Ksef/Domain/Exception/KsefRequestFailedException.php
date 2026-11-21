<?php

declare(strict_types=1);

namespace App\Ksef\Domain\Exception;

/**
 * KSeF answered in a way the client does not expect: a 4xx other than 403 and 429 (a rejected
 * request, an expired session, an unknown invoice) or a body it cannot read. It points at a bug or an
 * API change rather than at the organization's credentials.
 */
final class KsefRequestFailedException extends KsefException
{
    /** @param list<int> $ksefErrorCodes */
    private function __construct(string $message, public readonly ?int $httpStatus, public readonly array $ksefErrorCodes, ?string $operation, string $variant)
    {
        parent::__construct($message, ['operation' => $operation, 'httpStatus' => $httpStatus], $variant);
    }

    /** @param list<int> $ksefErrorCodes */
    public static function unexpectedStatus(string $what, int $httpStatus, string $detail, array $ksefErrorCodes): self
    {
        return new self(\sprintf('KSeF %s answered %d: %s', $what, $httpStatus, $detail), $httpStatus, $ksefErrorCodes, $what, __FUNCTION__);
    }

    public static function malformedResponse(string $what, string $reason): self
    {
        return new self(\sprintf('KSeF %s returned an unreadable response: %s', $what, $reason), null, [], $what, __FUNCTION__);
    }

    public static function noTokenEncryptionKey(): self
    {
        return new self('KSeF offers no valid public key with the usage KsefTokenEncryption.', null, [], null, __FUNCTION__);
    }
}
