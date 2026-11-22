<?php

declare(strict_types=1);

namespace App\CashFlow\Infrastructure\Doctrine\Orm\Repository;

use App\CashFlow\Domain\Model\DayCloseSettings;
use App\CashFlow\Domain\Repository\DayCloseSettingsRepositoryInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<DayCloseSettings> */
final class DayCloseSettingsRepository extends ServiceEntityRepository implements DayCloseSettingsRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DayCloseSettings::class);
    }

    /** The TenantFilter leaves the current organization's row only, if any. */
    public function findCurrent(): ?DayCloseSettings
    {
        return $this->findOneBy([]);
    }

    public function add(DayCloseSettings $settings): void
    {
        $this->getEntityManager()->persist($settings);
        $this->getEntityManager()->flush();
    }

    public function save(DayCloseSettings $settings): void
    {
        $this->getEntityManager()->flush();
    }
}
