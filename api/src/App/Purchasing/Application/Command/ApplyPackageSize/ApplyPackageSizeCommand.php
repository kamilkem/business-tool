<?php

declare(strict_types=1);

namespace App\Purchasing\Application\Command\ApplyPackageSize;

use App\Shared\Application\Command\CommandInterface;

/**
 * An owner or manager says that the items of a product with these units and package sizes have this package size
 * (ADR 0049), after being asked: the answer to "the same size on the other items?". Only the package (size and bundle count) of those
 * items is written; their product, status and the rest stay as they are. Each target is a group the query listed:
 * the unit's key (null: no unit) and the package the items have now (null: none).
 */
final readonly class ApplyPackageSizeCommand implements CommandInterface
{
    public const int MAX_TARGETS = 100;

    /** @param ?int $bundleCount single packages per unit of the quantity, 2 or more, null for no bundle (ADR 0048)
     * @param list<array{unitKey: ?string, packageSize: ?string, bundleCount: ?int}> $targets at most MAX_TARGETS */
    public function __construct(
        public string $organizationId,
        public string $actorId,
        public string $productId,
        public string $packageSize,
        public ?int $bundleCount,
        public array $targets,
    ) {
    }
}
