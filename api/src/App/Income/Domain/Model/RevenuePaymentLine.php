<?php

declare(strict_types=1);

namespace App\Income\Domain\Model;

use App\Organization\Domain\Model\TenantAwareInterface;
use App\Organization\Domain\Model\TenantAwareTrait;
use Doctrine\ORM\Mapping as ORM;

/**
 * One payment line of a RevenueEntry, created and changed only through the entry. The primary key
 * (entry, method) keeps each method once per entry. Tenant-aware like every business row, so a
 * query on lines alone is restricted to the current organization too.
 *
 * @internal
 */
#[ORM\Entity]
#[ORM\Table(name: 'revenue_entry_payment_line')]
class RevenuePaymentLine implements TenantAwareInterface
{
    use TenantAwareTrait;

    public function __construct(
        #[ORM\Id]
        #[ORM\ManyToOne(inversedBy: 'paymentLines')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private RevenueEntry $revenueEntry,
        #[ORM\Id]
        #[ORM\Column(length: 16, enumType: PaymentMethod::class)]
        private PaymentMethod $method,
        #[ORM\Column]
        private int $amount,
    ) {
    }

    public function changeAmount(int $amount): void
    {
        $this->amount = $amount;
    }

    public function getMethod(): PaymentMethod
    {
        return $this->method;
    }

    public function toPaymentAmount(): PaymentAmount
    {
        return new PaymentAmount($this->method, $this->amount);
    }
}
