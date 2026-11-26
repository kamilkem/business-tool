<?php

declare(strict_types=1);

namespace App\Tests\Unit\Purchasing\Domain;

use App\Organization\Domain\Model\MembershipRole;
use App\Purchasing\Domain\Exception\InvalidPackageSizeException;
use App\Purchasing\Domain\Exception\PurchasingManagementForbiddenException;
use App\Purchasing\Domain\Matching\MatchCaveat;
use App\Purchasing\Domain\Matching\MatchSource;
use App\Purchasing\Domain\Model\Product;
use App\Purchasing\Domain\Model\ProductCategory;
use App\Purchasing\Domain\Model\ProductMeasure;
use App\Purchasing\Domain\Model\PurchasedItem;
use App\Purchasing\Domain\Model\PurchasedItemDetails;
use App\Purchasing\Domain\Model\PurchasedItemStatus;
use App\Shared\Domain\Time\BusinessDate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/** How a person links an item to a product, and the status each change leaves it in (ADR 0041 §3). */
#[CoversClass(PurchasedItem::class)]
final class PurchasedItemTest extends TestCase
{
    private Uuid $actorId;

    protected function setUp(): void
    {
        $this->actorId = Uuid::v7();
    }

    public function test_it_starts_unassigned_with_no_product_and_no_package_size(): void
    {
        $item = self::item();

        self::assertSame(PurchasedItemStatus::Unassigned, $item->getStatus());
        self::assertNull($item->getProduct());
        self::assertNull($item->getProductId());
        self::assertNull($item->getPackageSize());
    }

    public function test_it_is_confirmed_once_a_person_links_it_to_a_product(): void
    {
        $item = self::item();
        $jack = self::product('Jack Daniel\'s');

        $item->link($jack, '0.70', $this->actorId, MembershipRole::Manager);

        self::assertSame(PurchasedItemStatus::Confirmed, $item->getStatus());
        self::assertSame($jack, $item->getProduct());
        self::assertSame($jack->getId(), $item->getProductId());
        self::assertSame('0.7', $item->getPackageSize());
    }

    /** @return iterable<string, array{PurchasedItemStatus}> */
    public static function statusesBeforeAPersonChangesTheLink(): iterable
    {
        yield 'unassigned' => [PurchasedItemStatus::Unassigned];
        yield 'suggested by matching' => [PurchasedItemStatus::Suggested];
        yield 'assigned by matching' => [PurchasedItemStatus::Assigned];
        yield 'confirmed' => [PurchasedItemStatus::Confirmed];
    }

    #[DataProvider('statusesBeforeAPersonChangesTheLink')]
    public function test_it_is_confirmed_by_any_change_of_a_person_to_a_product_even_one_that_changes_nothing(PurchasedItemStatus $before): void
    {
        $item = self::item();
        $jack = self::product('Jack Daniel\'s');
        $item->link($jack, '0.7', $this->actorId, MembershipRole::Owner);
        self::arrangeStatus($item, $before);

        $item->link($jack, '0.7', $this->actorId, MembershipRole::Owner);

        self::assertSame(PurchasedItemStatus::Confirmed, $item->getStatus());
        self::assertSame($jack, $item->getProduct());
        self::assertSame('0.7', $item->getPackageSize());
    }

    #[DataProvider('statusesBeforeAPersonChangesTheLink')]
    public function test_it_is_unassigned_when_a_person_takes_it_off_its_product(PurchasedItemStatus $before): void
    {
        $item = self::item();
        $item->link(self::product('Jack Daniel\'s'), '0.7', $this->actorId, MembershipRole::Owner);
        self::arrangeStatus($item, $before);

        $item->link(null, '0.7', $this->actorId, MembershipRole::Owner);

        self::assertSame(PurchasedItemStatus::Unassigned, $item->getStatus());
        self::assertNull($item->getProduct());
        // What the person entered stays for the next time the item is linked.
        self::assertSame('0.7', $item->getPackageSize());
    }

    public function test_it_moves_to_another_product_and_stays_confirmed(): void
    {
        $item = self::item();
        $item->link(self::product('Jack Daniel\'s'), '0.7', $this->actorId, MembershipRole::Owner);
        $cola = self::product('Cola');

        $item->link($cola, '1', $this->actorId, MembershipRole::Owner);

        self::assertSame($cola, $item->getProduct());
        self::assertSame(PurchasedItemStatus::Confirmed, $item->getStatus());
        self::assertSame('1', $item->getPackageSize());
    }

    public function test_it_keeps_a_package_size_without_a_product_and_clears_it_with_null(): void
    {
        $item = self::item();

        $item->link(null, '0.5', $this->actorId, MembershipRole::Owner);
        self::assertSame(PurchasedItemStatus::Unassigned, $item->getStatus());
        self::assertSame('0.5', $item->getPackageSize());

        $item->link(self::product('Beer'), null, $this->actorId, MembershipRole::Owner);
        self::assertNull($item->getPackageSize());
        self::assertSame(PurchasedItemStatus::Confirmed, $item->getStatus());
    }

    /** @return iterable<string, array{string, string}> */
    public static function packageSizes(): iterable
    {
        yield 'integer' => ['6', '6'];
        yield 'trailing zeros' => ['0.700000', '0.7'];
        yield 'leading zeros' => ['007.5', '7.5'];
        yield 'six decimals' => ['0.000001', '0.000001'];
        yield 'sixteen integer digits' => ['9999999999999999', '9999999999999999'];
        yield 'with a plus sign' => ['+2', '2'];
    }

    #[DataProvider('packageSizes')]
    public function test_it_stores_a_package_size_in_normal_form(string $given, string $stored): void
    {
        $item = self::item();

        $item->link(self::product('Beer'), $given, $this->actorId, MembershipRole::Owner);

        self::assertSame($stored, $item->getPackageSize());
    }

    /** @return iterable<string, array{string}> */
    public static function invalidPackageSizes(): iterable
    {
        yield 'zero' => ['0'];
        yield 'zero with decimals' => ['0.000'];
        yield 'negative' => ['-1'];
        yield 'empty' => [''];
        yield 'text' => ['big'];
        yield 'exponent' => ['1e3'];
        yield 'comma' => ['0,7'];
        yield 'seven decimals' => ['0.0000001'];
        yield 'seventeen integer digits' => ['10000000000000000'];
    }

    #[DataProvider('invalidPackageSizes')]
    public function test_it_refuses_a_package_size_that_is_not_a_positive_decimal_and_changes_nothing(string $given): void
    {
        $item = self::item();
        $jack = self::product('Jack Daniel\'s');
        $item->link($jack, '0.7', $this->actorId, MembershipRole::Owner);
        self::arrangeStatus($item, PurchasedItemStatus::Suggested);

        try {
            $item->link(self::product('Cola'), $given, $this->actorId, MembershipRole::Owner);
            self::fail('A package size that is not a positive decimal is refused.');
        } catch (InvalidPackageSizeException $e) {
            self::assertSame(['packageSize' => $given], $e->errorParameters());
        }

        self::assertSame($jack, $item->getProduct());
        self::assertSame('0.7', $item->getPackageSize());
        self::assertSame(PurchasedItemStatus::Suggested, $item->getStatus());
    }

    public function test_it_refuses_an_employee_and_changes_nothing(): void
    {
        $item = self::item();

        $this->expectException(PurchasingManagementForbiddenException::class);
        try {
            $item->link(self::product('Jack Daniel\'s'), '0.7', $this->actorId, MembershipRole::Employee);
        } finally {
            self::assertNull($item->getProduct());
            self::assertSame(PurchasedItemStatus::Unassigned, $item->getStatus());
        }
    }

    public function test_it_is_matched_as_assigned_or_suggested_without_being_confirmed(): void
    {
        $item = self::item();
        $jack = self::product('Jack Daniel\'s');
        $now = new \DateTimeImmutable('2026-11-26 03:00:00');

        $item->match($jack, '0.70', PurchasedItemStatus::Assigned, $now);

        self::assertSame(PurchasedItemStatus::Assigned, $item->getStatus());
        self::assertSame($jack, $item->getProduct());
        self::assertSame('0.7', $item->getPackageSize());
        self::assertEquals($now, $item->getMatchedAt());
        self::assertTrue($item->isMatchable());
    }

    public function test_it_notes_that_matching_found_nothing_and_keeps_what_it_had(): void
    {
        $item = self::item();
        $now = new \DateTimeImmutable('2026-11-26 03:00:00');

        $item->match(null, null, PurchasedItemStatus::Unassigned, $now);

        self::assertSame(PurchasedItemStatus::Unassigned, $item->getStatus());
        self::assertNull($item->getProduct());
        self::assertEquals($now, $item->getMatchedAt());
    }

    public function test_it_remembers_what_matched_it_and_why_it_is_only_suggested(): void
    {
        $item = self::item();
        $jack = self::product('Jack Daniel\'s');
        $now = new \DateTimeImmutable('2026-11-26 03:00:00');

        $item->match($jack, '0.7', PurchasedItemStatus::Suggested, $now, MatchSource::Model, MatchCaveat::ModelUnsure);
        self::assertSame([MatchSource::Model, MatchCaveat::ModelUnsure], [$item->getMatchSource(), $item->getMatchCaveat()]);

        $item->match($jack, '0.7', PurchasedItemStatus::Assigned, $now, MatchSource::Gtin, MatchCaveat::ModelUnsure);
        self::assertSame([MatchSource::Gtin, null], [$item->getMatchSource(), $item->getMatchCaveat()], 'An assigned item has no reason against it.');
    }

    public function test_it_keeps_its_reasons_when_matching_finds_nothing_and_loses_them_when_a_person_links_it(): void
    {
        $item = self::item();
        $jack = self::product('Jack Daniel\'s');
        $now = new \DateTimeImmutable('2026-11-26 03:00:00');
        $item->match($jack, '0.7', PurchasedItemStatus::Suggested, $now, MatchSource::Name, MatchCaveat::SizeUnknown);

        $item->match(null, null, PurchasedItemStatus::Unassigned, $now);
        self::assertSame([MatchSource::Name, MatchCaveat::SizeUnknown], [$item->getMatchSource(), $item->getMatchCaveat()]);

        $item->link($jack, '0.7', $this->actorId, MembershipRole::Owner);
        self::assertSame([null, null], [$item->getMatchSource(), $item->getMatchCaveat()]);
    }

    public function test_it_is_never_changed_by_matching_once_a_person_confirmed_it(): void
    {
        $item = self::item();
        $item->link(self::product('Jack Daniel\'s'), '0.7', $this->actorId, MembershipRole::Owner);

        self::assertFalse($item->isMatchable());
        $this->expectException(\LogicException::class);
        $item->match(self::product('Other'), '1', PurchasedItemStatus::Assigned, new \DateTimeImmutable('2026-11-26 03:00:00'));
    }

    public function test_it_stays_out_of_matching_after_a_person_took_its_product_away_until_a_person_links_it_again(): void
    {
        $item = self::item();
        $jack = self::product('Jack Daniel\'s');
        $item->link($jack, '0.7', $this->actorId, MembershipRole::Owner);

        $item->link(null, '0.7', $this->actorId, MembershipRole::Owner);

        self::assertTrue($item->isMatchingSkipped());
        self::assertFalse($item->isMatchable());
        $item->link($jack, '0.7', $this->actorId, MembershipRole::Owner);
        self::assertFalse($item->isMatchingSkipped());
    }

    public function test_it_refuses_matching_that_links_with_a_status_a_person_alone_gives(): void
    {
        $this->expectException(\LogicException::class);
        self::item()->match(self::product('Jack Daniel\'s'), '0.7', PurchasedItemStatus::Confirmed, new \DateTimeImmutable('2026-11-26 03:00:00'));
    }

    public function test_it_carries_a_proposal_keyed_by_the_proposed_names_normal_form(): void
    {
        $item = self::item();
        $category = ProductCategory::create(Uuid::v7(), 'Whisky', null, null, Uuid::v7(), MembershipRole::Owner, new \DateTimeImmutable('2026-11-01 10:00:00'));

        $item->propose("  Jack   Daniel's ", ProductMeasure::Volume, $category);

        self::assertTrue($item->hasProposal());
        self::assertSame(["Jack Daniel's", 'jack daniels', ProductMeasure::Volume, $category], [$item->getProposedName(), $item->getProposedNameKey(), $item->getProposedMeasure(), $item->getProposedCategory()]);
        self::assertSame(PurchasedItemStatus::Unassigned, $item->getStatus(), 'A proposal links nothing.');
        self::assertNull($item->getProduct());
    }

    /** @return iterable<string, array{?string, ?ProductMeasure}> */
    public static function noProposals(): iterable
    {
        yield 'no name' => [null, ProductMeasure::Volume];
        yield 'a blank name' => ['   ', ProductMeasure::Volume];
        yield 'no letters or digits' => ['***', ProductMeasure::Volume];
        yield 'no measure' => ['Jack', null];
        yield 'too long' => [str_repeat('a', Product::NAME_MAX_LENGTH + 1), ProductMeasure::Volume];
    }

    #[DataProvider('noProposals')]
    public function test_it_replaces_a_proposal_with_none_when_matching_has_nothing_usable(?string $name, ?ProductMeasure $measure): void
    {
        $item = self::item();
        $item->propose('Jack', ProductMeasure::Volume, null);

        $item->propose($name, $measure, null);

        self::assertFalse($item->hasProposal());
        self::assertNull($item->getProposedName());
        self::assertNull($item->getProposedNameKey());
        self::assertNull($item->getProposedMeasure());
    }

    public function test_it_drops_the_proposal_when_it_is_linked_by_a_person_or_matching(): void
    {
        $byPerson = self::item();
        $byMatching = self::item();
        $byPerson->propose('Jack', ProductMeasure::Volume, null);
        $byMatching->propose('Jack', ProductMeasure::Volume, null);

        $byPerson->link(self::product('Jack'), '0.7', $this->actorId, MembershipRole::Owner);
        $byMatching->match(self::product('Jack'), '0.7', PurchasedItemStatus::Suggested, new \DateTimeImmutable('2026-11-26 03:00:00'));

        self::assertFalse($byPerson->hasProposal());
        self::assertFalse($byMatching->hasProposal());
    }

    public function test_it_keeps_the_proposal_when_matching_found_no_product(): void
    {
        $item = self::item();
        $item->propose('Jack', ProductMeasure::Volume, null);

        $item->match(null, null, PurchasedItemStatus::Unassigned, new \DateTimeImmutable('2026-11-26 03:00:00'));

        self::assertTrue($item->hasProposal());
    }

    public function test_it_is_never_proposed_for_once_linked_confirmed_or_taken_off_its_product(): void
    {
        $linked = self::item();
        $linked->match(self::product('Jack'), '0.7', PurchasedItemStatus::Assigned, new \DateTimeImmutable('2026-11-26 03:00:00'));
        $confirmed = self::item();
        $confirmed->link(self::product('Jack'), '0.7', $this->actorId, MembershipRole::Owner);
        $removed = self::item();
        $removed->link(null, null, $this->actorId, MembershipRole::Owner);

        foreach ([$linked, $confirmed, $removed] as $item) {
            try {
                $item->propose('Jack', ProductMeasure::Volume, null);
                self::fail('Matching must not read this item.');
            } catch (\LogicException) {
            }
            self::assertFalse($item->hasProposal());
        }
    }

    public function test_it_remembers_a_dismissed_proposal_and_does_not_propose_that_name_again(): void
    {
        $item = self::item();
        $item->propose('Jack Daniel\'s', ProductMeasure::Volume, null);

        $item->dismissProposal($this->actorId, MembershipRole::Manager);

        self::assertFalse($item->hasProposal());
        self::assertTrue($item->isMatchable(), 'The item stays unassigned and open to matching.');
        $item->propose('JACK DANIELS', ProductMeasure::Volume, null);
        self::assertFalse($item->hasProposal(), 'The same name, spelled otherwise.');
        $item->propose('Jack Daniel\'s Honey', ProductMeasure::Volume, null);
        self::assertSame('Jack Daniel\'s Honey', $item->getProposedName(), 'Another name is a new proposal.');
    }

    public function test_it_refuses_an_employee_dismissing_a_proposal_and_ignores_dismissing_none(): void
    {
        $item = self::item();
        $item->propose('Jack', ProductMeasure::Volume, null);

        try {
            $item->dismissProposal($this->actorId, MembershipRole::Employee);
            self::fail('Employees decide nothing.');
        } catch (PurchasingManagementForbiddenException) {
        }
        self::assertTrue($item->hasProposal());

        $other = self::item();
        $other->dismissProposal($this->actorId, MembershipRole::Owner);
        $other->propose('Jack', ProductMeasure::Volume, null);
        self::assertTrue($other->hasProposal(), 'Dismissing nothing remembers nothing.');
    }

    private static function item(): PurchasedItem
    {
        return PurchasedItem::record(Uuid::v7(), new PurchasedItemDetails(
            Uuid::v7(), 1, BusinessDate::fromString('2026-11-10'), 'regular', 'PLN', '5265877635', 'Hurtownia Napojów',
            1, 'JACK DANIELS 0,7L', null, null, 'szt.', '6', '81.3', '100', null, 48_780, 60_000, 11_220, '23', false,
        ), new \DateTimeImmutable('2026-11-25 03:00:00'));
    }

    private static function product(string $name): Product
    {
        return Product::create(Uuid::v7(), $name, null, ProductMeasure::Volume, null, false, null, Uuid::v7(), MembershipRole::Owner, new \DateTimeImmutable('2026-11-01 10:00:00'));
    }

    /** What matching (#233) will do: an item is suggested or assigned only by it. */
    private static function arrangeStatus(PurchasedItem $item, PurchasedItemStatus $status): void
    {
        (new \ReflectionProperty(PurchasedItem::class, 'status'))->setValue($item, $status);
    }
}
