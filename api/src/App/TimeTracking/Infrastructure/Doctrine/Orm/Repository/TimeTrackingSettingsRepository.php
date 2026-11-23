<?php

declare(strict_types=1);

namespace App\TimeTracking\Infrastructure\Doctrine\Orm\Repository;

use App\TimeTracking\Domain\Model\TimeTrackingSettings;
use App\TimeTracking\Domain\Repository\TimeTrackingSettingsRepositoryInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<TimeTrackingSettings> */
final class TimeTrackingSettingsRepository extends ServiceEntityRepository implements TimeTrackingSettingsRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TimeTrackingSettings::class);
    }

    /** The TenantFilter leaves the current organization's row only, if any. */
    public function findCurrent(): ?TimeTrackingSettings
    {
        return $this->findOneBy([]);
    }

    public function add(TimeTrackingSettings $settings): void
    {
        $this->getEntityManager()->persist($settings);
        $this->getEntityManager()->flush();
    }

    public function save(TimeTrackingSettings $settings): void
    {
        $this->getEntityManager()->flush();
    }
}
