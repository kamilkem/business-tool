<?php

declare(strict_types=1);

namespace App\Ksef\Domain\Model;

/**
 * One part of an export package: an encrypted slice of the ZIP, downloaded from its URL without the
 * access token (ADR 0039 §5). Hashes are SHA-256, Base64.
 */
final readonly class KsefInvoicePackagePart
{
    public function __construct(
        public int $ordinalNumber,
        public string $url,
        public string $partHash,
        public string $encryptedPartHash,
    ) {
    }
}
