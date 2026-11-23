<?php

declare(strict_types=1);

namespace Ui\Http\Income;

use App\Income\Application\Query\ListRevenueEntryChanges\ListRevenueEntryChangesQuery;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Ui\Http\Shared\Validator\BusinessPeriodRules;

/**
 * The query string of GET /revenue-entry-changes: one entry's history (entryId), or the changes of
 * the entries dated in a period (from and to). RevenueEntryChangeCollectionProvider validates it.
 */
final readonly class ListRevenueEntryChangesInput
{
    public function __construct(
        #[Assert\Uuid]
        public ?string $entryId = null,
        #[Assert\Date]
        public ?string $from = null,
        #[Assert\Date]
        public ?string $to = null,
    ) {
    }

    #[Assert\Callback]
    public function validateScope(ExecutionContextInterface $context): void
    {
        if (null === $this->entryId && (null === $this->from || null === $this->to)) {
            $context->buildViolation('Give an entryId, or both from and to.')->atPath(null === $this->from ? 'from' : 'to')->addViolation();

            return;
        }
        if ((null === $this->from) !== (null === $this->to)) {
            $context->buildViolation('Give both from and to, or neither.')->atPath(null === $this->from ? 'from' : 'to')->addViolation();

            return;
        }

        BusinessPeriodRules::validate($context, $this->from, $this->to, ListRevenueEntryChangesQuery::MAX_DAYS);
    }
}
