<?php

declare(strict_types=1);

namespace App\Income\Application\Query\ListRevenueEntryChanges;

use App\Income\Domain\Model\RevenueEntryChange;
use App\Income\Domain\Model\RevenueEntrySnapshot;

final readonly class RevenueEntryChangeView
{
    public function __construct(
        public string $id,
        public string $entryId,
        public string $registerId,
        public string $registerName,
        public string $businessDate,
        public string $currency,
        /** created | changed | deleted | booked */
        public string $kind,
        public ?string $dayCloseId,
        public ?RevenueEntrySnapshot $before,
        public ?RevenueEntrySnapshot $after,
        public string $changedBy,
        public \DateTimeImmutable $changedAt,
    ) {
    }

    public static function of(RevenueEntryChange $change): self
    {
        return new self(
            $change->getId()->toRfc4122(),
            $change->getEntryId()->toRfc4122(),
            $change->getRegisterId()->toRfc4122(),
            $change->getRegisterName(),
            $change->getBusinessDate()->toString(),
            $change->getCurrency(),
            $change->getKind()->value,
            $change->getDayCloseId()?->toRfc4122(),
            $change->getBefore(),
            $change->getAfter(),
            $change->getChangedBy()->toRfc4122(),
            $change->getChangedAt(),
        );
    }
}
