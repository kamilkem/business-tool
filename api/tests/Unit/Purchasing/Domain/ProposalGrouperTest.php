<?php

declare(strict_types=1);

namespace App\Tests\Unit\Purchasing\Domain;

use App\Purchasing\Domain\Model\ProductMeasure;
use App\Purchasing\Domain\Proposal\ProposalGroup;
use App\Purchasing\Domain\Proposal\ProposalGrouper;
use App\Purchasing\Domain\Proposal\ProposedItem;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/** One decision covers every spelling: items are grouped by the proposed name's key (ADR 0044). */
#[CoversClass(ProposalGrouper::class)]
#[CoversClass(ProposalGroup::class)]
final class ProposalGrouperTest extends TestCase
{
    private const string BEER = '018f0000-0000-7000-8000-0000000000c1';
    private const string WHISKY = '018f0000-0000-7000-8000-0000000000c2';

    public function test_it_puts_the_spellings_of_one_name_into_one_group(): void
    {
        $groups = ProposalGrouper::group([
            $this->item('JACK DANIELS 0,7L', 'Jack Daniel\'s', self::WHISKY),
            $this->item('J.D. 1L', 'JACK DANIELS', self::WHISKY),
            $this->item('Whiskey Jack Daniel\'s', 'jack  daniel\'s', self::WHISKY),
        ]);

        self::assertCount(1, $groups);
        self::assertSame(['jack daniels', 3], [$groups[0]->key, $groups[0]->itemCount]);
    }

    public function test_it_shows_what_most_items_propose_and_breaks_ties_alphabetically(): void
    {
        $groups = ProposalGrouper::group([
            $this->item('a', 'Tyskie', null, ProductMeasure::Count),
            $this->item('b', 'TYSKIE', self::BEER),
            $this->item('c', 'TYSKIE', self::BEER),
            $this->item('d', 'Tyskie Gold', self::WHISKY, ProductMeasure::Volume),
        ]);

        self::assertCount(2, $groups);
        self::assertSame(['TYSKIE', ProductMeasure::Volume, self::BEER], [$groups[0]->name, $groups[0]->measure, $groups[0]->categoryId], 'Two of three say TYSKIE, volume and beer.');
        self::assertSame(['Tyskie Gold', self::WHISKY], [$groups[1]->name, $groups[1]->categoryId]);
    }

    public function test_it_prefers_a_category_over_none_on_a_tie_and_shows_none_when_most_say_so(): void
    {
        $tie = ProposalGrouper::group([$this->item('a', 'Cola', null), $this->item('b', 'Cola', self::BEER)]);
        $mostlyNone = ProposalGrouper::group([$this->item('a', 'Cola', null), $this->item('b', 'Cola', null), $this->item('c', 'Cola', self::BEER)]);

        self::assertSame(self::BEER, $tie[0]->categoryId);
        self::assertNull($mostlyNone[0]->categoryId);
    }

    public function test_it_orders_groups_largest_first_then_by_name_and_lists_a_few_distinct_examples(): void
    {
        $groups = ProposalGrouper::group([
            $this->item('Zubr 0,5', 'Zubr'),
            $this->item('Cola 1l', 'Cola'),
            $this->item('Cola 1l', 'Cola'),
            $this->item('Cola 0,5l', 'Cola'),
            $this->item('Cola 2l', 'Cola'),
            $this->item('Cola 0,33l', 'Cola'),
            $this->item('Ale 0,5', 'Ale'),
        ]);

        self::assertSame(['Cola', 'Ale', 'Zubr'], array_map(static fn (ProposalGroup $group): string => $group->name, $groups));
        self::assertSame(['Cola 1l', 'Cola 0,5l', 'Cola 2l'], $groups[0]->exampleNames, 'Three distinct names, in the order given.');
        self::assertSame(5, $groups[0]->itemCount);
    }

    public function test_it_has_no_groups_without_items(): void
    {
        self::assertSame([], ProposalGrouper::group([]));
    }

    public function test_it_turns_a_key_into_an_id_for_urls_and_back(): void
    {
        self::assertSame('jack-daniels-honey', ProposalGroup::idOf('jack daniels honey'));
        self::assertSame('jack daniels honey', ProposalGroup::keyOf('jack-daniels-honey'));
    }

    private function item(string $name, string $proposed, ?string $categoryId = null, ProductMeasure $measure = ProductMeasure::Volume): ProposedItem
    {
        static $id = 0;
        $key = trim(preg_replace('/[^a-z0-9]+/', ' ', str_replace("'", '', strtolower($proposed))) ?? '');

        return new ProposedItem('item-'.++$id, $name, $proposed, $key, $measure, $categoryId);
    }
}
