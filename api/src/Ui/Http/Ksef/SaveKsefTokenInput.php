<?php

declare(strict_types=1);

namespace Ui\Http\Ksef;

use Symfony\Component\Validator\Constraints as Assert;

/** Write-only: the token goes to the Secret module encrypted and is never returned (ADR 0026 §5–6). */
final readonly class SaveKsefTokenInput
{
    public const int TOKEN_MAX_LENGTH = 1000;

    public function __construct(
        /** The KSeF token generated in KSeF for the organization's NIP, with the InvoiceRead permission. Stored as given. */
        #[Assert\NotBlank(normalizer: 'trim')]
        #[Assert\Length(max: self::TOKEN_MAX_LENGTH)]
        #[\SensitiveParameter]
        public string $token,
    ) {
    }

    /** @return array{token: string} */
    public function __debugInfo(): array
    {
        return ['token' => '***'];
    }
}
