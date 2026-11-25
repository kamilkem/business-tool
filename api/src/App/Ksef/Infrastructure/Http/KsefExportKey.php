<?php

declare(strict_types=1);

namespace App\Ksef\Infrastructure\Http;

/**
 * The AES-256 key and IV of a package export, derived from the application secret, the context's NIP
 * and the export's key id (ADR 0039 §3): HMAC-SHA-256 with separate labels, so that nothing secret is
 * stored and the run that imports the package can compute them again. Never logged.
 */
final readonly class KsefExportKey
{
    private function __construct(
        #[\SensitiveParameter] public string $key,
        #[\SensitiveParameter] public string $iv,
    ) {
    }

    public static function derive(#[\SensitiveParameter] string $secret, string $nip, string $keyId): self
    {
        if ('' === $secret) {
            throw new \LogicException('The KSeF export key needs the application secret.');
        }
        $subject = $nip."\0".$keyId;

        return new self(
            hash_hmac('sha256', "ksef-invoice-export-key\0".$subject, $secret, true),
            substr(hash_hmac('sha256', "ksef-invoice-export-iv\0".$subject, $secret, true), 0, 16),
        );
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return [];
    }
}
