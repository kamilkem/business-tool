<?php

declare(strict_types=1);

namespace App\Income\Domain\Model;

use App\Organization\Domain\Model\TenantAwareInterface;
use App\Organization\Domain\Model\TenantAwareTrait;
use Doctrine\ORM\Mapping as ORM;

/**
 * One payment line of a RevenueEntry, created and changed only through the entry. The primary key
 * (entry, payment method) keeps each method once per entry. The foreign key to the method has no
 * ON DELETE: a used method cannot be deleted (ADR 0030 §4). Tenant-aware like every business row,
 * so a query on lines alone is restricted to the current organization too.
 *
 * @internal
 */
#[ORM\Entity]
#[ORM\Table(name: 'revenue_entry_payment_line')]
#[ORM\Index(name: 'revenue_entry_payment_line_payment_method', columns: ['payment_method_id'])]
class RevenuePaymentLine implements TenantAwareInterface
{
    use TenantAwareTrait;

    public function __construct(
        #[ORM\Id]
        #[ORM\ManyToOne(inversedBy: 'paymentLines')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private RevenueEntry $revenueEntry,
        #[ORM\Id]
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false)]
        private PaymentMethod $paymentMethod,
        #[ORM\Column]
        private int $amount,
    ) {
    }

    public function changeAmount(int $amount): void
    {
        $this->amount = $amount;
    }

    public function getPaymentMethod(): PaymentMethod
    {
        return $this->paymentMethod;
    }

    public function toPaymentAmount(): PaymentAmount
    {
        return new PaymentAmount($this->paymentMethod, $this->amount);
    }
}
