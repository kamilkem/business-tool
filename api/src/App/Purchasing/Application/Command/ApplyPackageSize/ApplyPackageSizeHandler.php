<?php

declare(strict_types=1);

namespace App\Purchasing\Application\Command\ApplyPackageSize;

use App\Expenses\Domain\Model\DecimalString;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Purchasing\Application\PurchasingActor;
use App\Purchasing\Domain\Event\PurchasedItemsConfirmed;
use App\Purchasing\Domain\Exception\InvalidPackageSizeException;
use App\Purchasing\Domain\Exception\ProductNotFoundException;
use App\Purchasing\Domain\Exception\PurchasingManagementForbiddenException;
use App\Purchasing\Domain\Model\PurchasedItem;
use App\Purchasing\Domain\Model\PurchasedItemStatus;
use App\Purchasing\Domain\PackageSize\PackageSizeGroup;
use App\Purchasing\Domain\Repository\ProductRepositoryInterface;
use App\Purchasing\Domain\Repository\PurchasedItemRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Event\EventPublisherInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Writes one package size on the product's items of the chosen groups, in one flush (ADR 0048). The items are one
 * action of the person, not one aggregate each, as for AssignPurchasedItemsHandler. A group that holds no item any
 * more (an item changed since the person was asked) changes nothing. The items that were `confirmed` are published
 * again, so that the aliases remember the size the person gave them (ADR 0042 §3); the others stay as matching
 * left them and are not confirmed by this.
 */
final readonly class ApplyPackageSizeHandler implements CommandHandlerInterface
{
    public function __construct(
        private PurchasedItemRepositoryInterface $items,
        private ProductRepositoryInterface $products,
        private QueryBusInterface $queryBus,
        private EventPublisherInterface $events,
    ) {
    }

    /**
     * @throws OrganizationNotFoundException          when the actor is not an active member of the organization
     * @throws PurchasingManagementForbiddenException when the actor is not an owner or manager
     * @throws ProductNotFoundException               when the product is not one of the organization's
     * @throws InvalidPackageSizeException            when a package size is not a positive decimal
     * @throws \InvalidArgumentException              when there are more targets than ApplyPackageSizeCommand::MAX_TARGETS
     */
    public function __invoke(ApplyPackageSizeCommand $command): void
    {
        $actor = PurchasingActor::resolve($this->queryBus, $command->organizationId, $command->actorId);
        if (\count($command->targets) > ApplyPackageSizeCommand::MAX_TARGETS) {
            throw new \InvalidArgumentException(\sprintf('At most %d groups are changed at once.', ApplyPackageSizeCommand::MAX_TARGETS));
        }

        $product = Uuid::isValid($command->productId) ? $this->products->findById(Uuid::fromString($command->productId)) : null;
        $product ??= throw ProductNotFoundException::withId($command->productId);

        $targets = array_map(static fn (array $target): PackageSizeGroup => new PackageSizeGroup($target['unitKey'], null, self::normalized($target['packageSize']), 0, 0), $command->targets);

        $changed = [];
        foreach ($this->items->findByProduct($product->getId()) as $item) {
            foreach ($targets as $target) {
                if ($target->holds($item)) {
                    $item->changePackageSize($command->packageSize, $actor->id, $actor->role);
                    $changed[] = $item;
                    break;
                }
            }
        }
        if ([] === $changed) {
            return;
        }

        $this->items->saveAll($changed);
        $confirmed = array_values(array_filter($changed, static fn (PurchasedItem $item): bool => PurchasedItemStatus::Confirmed === $item->getStatus()));
        if ([] !== $confirmed) {
            $this->events->publish(new PurchasedItemsConfirmed($command->organizationId, array_map(static fn (PurchasedItem $item): string => $item->getId()->toRfc4122(), $confirmed)));
        }
    }

    /** @throws InvalidPackageSizeException */
    private static function normalized(?string $value): ?string
    {
        if (null === $value) {
            return null;
        }

        return DecimalString::normalized($value, PurchasedItem::PACKAGE_SIZE_INTEGER_DIGITS, PurchasedItem::PACKAGE_SIZE_SCALE) ?? throw InvalidPackageSizeException::of($value);
    }
}
