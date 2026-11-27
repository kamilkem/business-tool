<?php

declare(strict_types=1);

namespace Ui\Http\Purchasing;

use App\Purchasing\Application\Command\ApplyPackageSize\ApplyPackageSizeCommand;
use App\Purchasing\Domain\Model\PackageSize;
use App\Purchasing\Domain\Model\PurchasedItem;
use Symfony\Component\Validator\Constraints as Assert;

/** Whether the product is the organization's is the domain's (404, nothing changes). */
final readonly class ApplyPackageSizeInput
{
    /** @param list<ApplyPackageSizeGroupInput> $groups */
    public function __construct(
        /** The package size the items of the chosen groups get. */
        #[Assert\NotBlank]
        #[Assert\Regex(pattern: PurchasedItem::PACKAGE_SIZE_PATTERN, message: 'This is not a decimal number with at most 6 decimals.')]
        #[Assert\Positive]
        public string $packageSize,
        /** The groups to set it on: at least one. */
        #[Assert\Count(min: 1, max: ApplyPackageSizeCommand::MAX_TARGETS)]
        #[Assert\Valid]
        public array $groups,
        /** Single packages per unit of the quantity, with the package size of one single package; left out or null: no bundle (ADR 0048). */
        #[Assert\Range(min: PackageSize::MIN_BUNDLE_COUNT, max: PackageSize::MAX_BUNDLE_COUNT)]
        public ?int $bundleCount = null,
    ) {
    }
}
