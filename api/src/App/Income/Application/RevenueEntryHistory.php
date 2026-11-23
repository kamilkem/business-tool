<?php

declare(strict_types=1);

namespace App\Income\Application;

use App\Income\Domain\Model\RevenueEntry;
use App\Income\Domain\Model\RevenueEntryChange;
use App\Income\Domain\Model\RevenueEntryChangeKind;
use App\Income\Domain\Model\RevenueEntrySnapshot;
use App\Income\Domain\Repository\RevenueEntryChangeRepositoryInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Records a write of a revenue entry in its change history (ADR 0034 §4), right after the command
 * handler saved it. A write that changed nothing (the same lines, amounts and note) leaves no row.
 */
final readonly class RevenueEntryHistory
{
    public function __construct(
        private RevenueEntryChangeRepositoryInterface $changes,
    ) {
    }

    public function created(RevenueEntry $entry, Uuid $actorId, \DateTimeImmutable $now): void
    {
        $this->add($entry, RevenueEntryChangeKind::Created, null, RevenueEntrySnapshot::of($entry), null, $actorId, $now);
    }

    public function changed(RevenueEntrySnapshot $before, RevenueEntry $entry, Uuid $actorId, \DateTimeImmutable $now): void
    {
        $after = RevenueEntrySnapshot::of($entry);
        if (!$before->sameAs($after)) {
            $this->add($entry, RevenueEntryChangeKind::Changed, $before, $after, null, $actorId, $now);
        }
    }

    /** @param RevenueEntry $entry the entry as it was before it was removed */
    public function deleted(RevenueEntry $entry, ?Uuid $dayCloseId, Uuid $actorId, \DateTimeImmutable $now): void
    {
        $this->add($entry, RevenueEntryChangeKind::Deleted, RevenueEntrySnapshot::of($entry), null, $dayCloseId, $actorId, $now);
    }

    /**
     * @param ?RevenueEntrySnapshot $before   null when the close booked a new entry
     * @param bool                  $takeover the close took over a manual entry: recorded even with the same lines
     */
    public function booked(?RevenueEntrySnapshot $before, RevenueEntry $entry, Uuid $dayCloseId, Uuid $actorId, \DateTimeImmutable $now, bool $takeover = false): void
    {
        $after = RevenueEntrySnapshot::of($entry);
        if (null === $before || $takeover || !$before->sameAs($after)) {
            $this->add($entry, RevenueEntryChangeKind::Booked, $before, $after, $dayCloseId, $actorId, $now);
        }
    }

    private function add(RevenueEntry $entry, RevenueEntryChangeKind $kind, ?RevenueEntrySnapshot $before, ?RevenueEntrySnapshot $after, ?Uuid $dayCloseId, Uuid $actorId, \DateTimeImmutable $now): void
    {
        $this->changes->add(new RevenueEntryChange(Uuid::v7(), $entry, $kind, $before, $after, $dayCloseId, $actorId, $now));
    }
}
