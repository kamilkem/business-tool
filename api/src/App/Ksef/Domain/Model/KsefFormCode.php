<?php

declare(strict_types=1);

namespace App\Ksef\Domain\Model;

/** The structured form of an invoice in KSeF, e.g. FA(3): `FA (3)`, schema `1-0E`, value `FA`. */
final readonly class KsefFormCode
{
    public const string FA3_SYSTEM_CODE = 'FA (3)';

    public function __construct(
        public string $systemCode,
        public string $schemaVersion,
        public string $value,
    ) {
    }

    public function isFa3(): bool
    {
        return self::FA3_SYSTEM_CODE === $this->systemCode;
    }
}
