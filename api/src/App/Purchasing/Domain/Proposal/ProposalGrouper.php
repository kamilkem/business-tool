<?php

declare(strict_types=1);

namespace App\Purchasing\Domain\Proposal;

use App\Purchasing\Domain\Model\ProductMeasure;

/**
 * Groups the items that carry a proposal by the proposed name's key (case, diacritics and punctuation
 * do not matter), so one decision covers every spelling: forty items of "Jack Daniel's" are one row
 * (ADR 0044). Pure rules, no database.
 *
 * A group is shown with what most of its items propose (the name's spelling, the measure, the
 * category); a tie goes to the first in alphabetical order, a missing category last. Groups come
 * largest first, then by name.
 */
final class ProposalGrouper
{
    public const int EXAMPLES = 3;

    private function __construct()
    {
    }

    /**
     * @param list<ProposedItem> $items
     *
     * @return list<ProposalGroup>
     */
    public static function group(array $items): array
    {
        /** @var array<string, list<ProposedItem>> $byKey */
        $byKey = [];
        foreach ($items as $item) {
            $byKey[$item->key][] = $item;
        }

        $groups = [];
        foreach ($byKey as $key => $members) {
            $names = [];
            $examples = [];
            foreach ($members as $member) {
                $names[] = $member->proposedName;
                $examples[$member->itemName] = $member->itemName;
            }
            $measure = self::mostCommon(array_map(static fn (ProposedItem $m): string => $m->measure->value, $members));
            $categoryId = self::mostCommon(array_map(static fn (ProposedItem $m): string => $m->categoryId ?? '', $members));

            $groups[] = new ProposalGroup(
                (string) $key,
                self::mostCommon($names),
                ProductMeasure::from($measure),
                '' === $categoryId ? null : $categoryId,
                \count($members),
                \array_slice(array_values($examples), 0, self::EXAMPLES),
            );
        }
        usort($groups, static fn (ProposalGroup $a, ProposalGroup $b): int => [$b->itemCount, $a->name, $a->key] <=> [$a->itemCount, $b->name, $b->key]);

        return $groups;
    }

    /** @param list<string> $values */
    private static function mostCommon(array $values): string
    {
        $counts = array_count_values($values);
        uksort($counts, static fn (string|int $a, string|int $b): int => [-$counts[$a], '' === (string) $a ? 1 : 0, (string) $a] <=> [-$counts[$b], '' === (string) $b ? 1 : 0, (string) $b]);

        return (string) array_key_first($counts);
    }
}
