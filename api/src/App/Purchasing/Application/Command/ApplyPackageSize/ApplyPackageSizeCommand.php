<?php

declare(strict_types=1);

namespace App\Purchasing\Application\Command\ApplyPackageSize;

use App\Shared\Application\Command\CommandInterface;

/**
 * An owner or manager says that the items of a product with these units and package sizes have this package size
 * (ADR 0048), after being asked: the answer to "the same size on the other items?". Only the package size of those
 * items is written; their product, status and the rest stay as they are. Each target is a group the query listed:
 * the unit's key (null: no unit) and the size the items have now (null: none).
 */
final readonly class ApplyPackageSizeCommand implements CommandInterface
{
    public const int MAX_TARGETS = 100;

    /** @param list<array{unitKey: ?string, packageSize: ?string}> $targets at most MAX_TARGETS */
    public function __construct(
        public string $organizationId,
        public string $actorId,
        public string $productId,
        public string $packageSize,
        public array $targets,
    ) {
    }
}
