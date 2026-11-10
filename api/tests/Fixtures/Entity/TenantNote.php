<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Entity;

use App\Organization\Domain\Model\TenantAwareInterface;
use App\Organization\Domain\Model\TenantAwareTrait;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Test-only tenant-aware entity, used to verify tenant isolation independently of any business
 * module. Mapped in the test env only (config/packages/doctrine.yaml).
 */
#[ORM\Entity]
class TenantNote implements TenantAwareInterface
{
    use TenantAwareTrait;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    public Uuid $id;

    public function __construct(
        #[ORM\Column]
        public string $text = '',
    ) {
        $this->id = Uuid::v7();
    }
}
