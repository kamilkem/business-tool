<?php

declare(strict_types=1);

namespace App\Purchasing\Application\Command\RecordPurchasedItems;

use App\Organization\Domain\TenantContextInterface;
use App\Purchasing\Domain\Model\PurchasedItem;
use App\Purchasing\Domain\Model\PurchasedItemDetails;
use App\Purchasing\Domain\Repository\PurchasedItemRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Domain\Time\BusinessDate;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

final readonly class RecordPurchasedItemsHandler implements CommandHandlerInterface
{
    public function __construct(
        private PurchasedItemRepositoryInterface $items,
        private TenantContextInterface $tenantContext,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Adds the items Purchasing does not have for the invoice, in one flush; the ones it has are left
     * alone, so recording the same invoice again changes nothing. Two writers recording the same
     * invoice at once are stopped by the unique constraint on (invoice, item index): one fails and
     * the copy job, which is idempotent, completes it.
     *
     * @throws \InvalidArgumentException when the data is not what Expenses publishes (an id, a date, an index, a repeated index)
     * @throws \LogicException           when the organization is not the one the writer runs in
     */
    public function __invoke(RecordPurchasedItemsCommand $command): void
    {
        if ($this->tenantContext->getOrganization()->getId()->toRfc4122() !== $command->organizationId) {
            throw new \LogicException('Purchased items are recorded only in the organization the writer runs in.');
        }

        $invoiceId = Uuid::fromString($command->invoiceId);
        $issueDate = BusinessDate::fromString($command->issueDate);
        $now = $this->clock->now();

        $known = [];
        foreach ($this->items->findByInvoice($invoiceId) as $item) {
            $known[$item->getItemIndex()] = true;
        }

        $new = [];
        $indexes = [];
        foreach ($command->items as $item) {
            if (isset($indexes[$item['index']])) {
                throw new \InvalidArgumentException(\sprintf('Item %d of the invoice is given twice.', $item['index']));
            }
            $indexes[$item['index']] = true;
            if (isset($known[$item['index']])) {
                continue;
            }

            $new[] = PurchasedItem::record(Uuid::fromString($item['id']), new PurchasedItemDetails(
                $invoiceId,
                $item['index'],
                $issueDate,
                $command->invoiceKind,
                $command->currency,
                $command->sellerTaxId,
                $command->sellerName,
                $item['position'],
                $item['name'],
                $item['sellerCode'],
                $item['gtin'],
                $item['unit'],
                $item['quantity'],
                $item['unitNetPrice'],
                $item['unitGrossPrice'],
                $item['discount'],
                $item['netAmount'],
                $item['grossAmount'],
                $item['vatAmount'],
                $item['vatRate'],
                $item['beforeCorrection'],
            ), $now);
        }

        if ([] !== $new) {
            $this->items->addAll($new);
        }
    }
}
