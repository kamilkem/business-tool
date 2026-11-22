<?php

declare(strict_types=1);

namespace Ui\Http\CashFlow;

use ApiPlatform\Metadata\ApiProperty;
use App\CashFlow\Domain\Model\CashStorage;
use App\CashFlow\Domain\Model\CashStorageMode;
use Symfony\Component\Validator\Constraints as Assert;

/** Uniqueness of the name in the organization is the domain's (409). */
final readonly class AddCashStorageInput
{
    public function __construct(
        /** Trimmed; unique in the organization, compared as written ("Safe" and "safe" are two names). */
        #[Assert\NotBlank(normalizer: 'trim')]
        #[Assert\Length(max: CashStorage::NAME_MAX_LENGTH, normalizer: 'trim')]
        public string $name,
        /** `counted`: the close counts everything in it; `deposit`: the close enters only what was put in. */
        #[ApiProperty(schema: ['type' => 'string', 'enum' => ['counted', 'deposit']])]
        #[Assert\NotBlank]
        #[Assert\Choice(callback: [self::class, 'modes'])]
        public string $mode,
    ) {
    }

    /** @return list<string> */
    public static function modes(): array
    {
        return array_map(static fn (CashStorageMode $mode): string => $mode->value, CashStorageMode::cases());
    }
}
