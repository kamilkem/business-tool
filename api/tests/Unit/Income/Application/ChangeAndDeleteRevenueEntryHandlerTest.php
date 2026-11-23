<?php

declare(strict_types=1);

namespace App\Tests\Unit\Income\Application;

use App\Income\Application\Command\ChangeRevenueEntry\ChangeRevenueEntryCommand;
use App\Income\Application\Command\ChangeRevenueEntry\ChangeRevenueEntryHandler;
use App\Income\Application\Command\DeleteRevenueEntry\DeleteRevenueEntryCommand;
use App\Income\Application\Command\DeleteRevenueEntry\DeleteRevenueEntryHandler;
use App\Income\Domain\Exception\IncomeManagementForbiddenException;
use App\Income\Domain\Exception\InvalidPaymentLinesException;
use App\Income\Domain\Exception\InvalidVatLinesException;
use App\Income\Domain\Exception\RevenueEntryNotFoundException;
use App\Income\Domain\Model\PaymentAmount;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Organization\Domain\Model\MembershipRole;
use App\Tests\Fixtures\OrganizationMother;
use App\Tests\Fixtures\UserMother;
use App\User\Domain\Model\User;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Uid\Uuid;

#[CoversClass(ChangeRevenueEntryHandler::class)]
#[CoversClass(DeleteRevenueEntryHandler::class)]
final class ChangeAndDeleteRevenueEntryHandlerTest extends IncomeHandlerTestCase
{
    public function test_it_replaces_the_lines_and_note_and_records_who_changed_them(): void
    {
        $entry = $this->recorded('2026-11-10');

        $this->change($this->manager, $entry->getId()->toRfc4122(), [self::line($this->card, 5_000)], [], 'Fixed');

        self::assertEquals([new PaymentAmount($this->card, 5_000)], $entry->getPaymentLines());
        self::assertSame([], $entry->getVatLines());
        self::assertSame('Fixed', $entry->getNote());
        self::assertTrue($this->manager->getId()->equals($entry->getUpdatedBy() ?? Uuid::v7()));
        self::assertEquals(new \DateTimeImmutable(self::NOW), $entry->getUpdatedAt());
        self::assertSame(1, $this->entries->saveCallCount);
    }

    public function test_it_corrects_an_entry_of_a_deactivated_register(): void
    {
        $entry = $this->recorded('2026-11-10');
        $this->bar->deactivate($this->owner->getId(), MembershipRole::Owner, new \DateTimeImmutable('2026-11-11'));

        $this->change($this->owner, $entry->getId()->toRfc4122(), [self::line($this->cash, 1)]);

        self::assertSame(1, $entry->getTotal());
    }

    public function test_it_keeps_a_line_of_a_deactivated_payment_method_but_adds_none(): void
    {
        $entry = $this->recorded('2026-11-10');
        $this->cash->deactivate($this->owner->getId(), MembershipRole::Owner, new \DateTimeImmutable('2026-11-11'));

        $this->change($this->owner, $entry->getId()->toRfc4122(), [self::line($this->cash, 2_000), self::line($this->card, 500)]);
        self::assertEquals([new PaymentAmount($this->card, 500), new PaymentAmount($this->cash, 2_000)], $entry->getPaymentLines());

        $this->change($this->owner, $entry->getId()->toRfc4122(), [self::line($this->card, 500)]);
        $this->expectException(InvalidPaymentLinesException::class);
        $this->change($this->owner, $entry->getId()->toRfc4122(), [self::line($this->cash, 2_000), self::line($this->card, 500)]);
    }

    public function test_it_checks_vat_lines_against_the_organizations_current_country(): void
    {
        $entry = $this->recorded('2026-11-10');
        $this->organization->changeSettings($this->owner->getId(), 'Corner Pub', null, 'DE', 'EUR', 'Europe/Berlin');

        $this->expectException(InvalidVatLinesException::class);

        $this->change($this->owner, $entry->getId()->toRfc4122(), [self::line($this->cash, 1)], [['rate' => '23', 'gross' => 1, 'vat' => 0]]);
    }

    public function test_it_refuses_a_change_by_an_employee(): void
    {
        $entry = $this->recorded('2026-11-10');

        $this->expectException(IncomeManagementForbiddenException::class);

        $this->change($this->employee, $entry->getId()->toRfc4122(), [self::line($this->cash, 1)]);
    }

    public function test_it_answers_not_found_for_a_change_of_an_unknown_entry(): void
    {
        $this->expectException(RevenueEntryNotFoundException::class);

        $this->change($this->owner, Uuid::v7()->toRfc4122(), [self::line($this->cash, 1)]);
    }

    public function test_it_deletes_an_entry(): void
    {
        $entry = $this->recorded('2026-11-10');
        $kept = $this->recorded('2026-11-11');

        $this->delete($this->manager, $entry->getId()->toRfc4122());

        self::assertSame([$kept], $this->entries->all());
    }

    public function test_it_refuses_a_delete_by_an_employee_or_a_deactivated_owner(): void
    {
        $entry = $this->recorded('2026-11-10');
        $secondOwner = UserMother::any('oscar@example.com');
        OrganizationMother::addMember($this->organization, $secondOwner, MembershipRole::Owner, new \DateTimeImmutable('2026-01-05'));
        OrganizationMother::deactivateMember($this->organization, $secondOwner, new \DateTimeImmutable('2026-11-01'));

        foreach ([[$this->employee, IncomeManagementForbiddenException::class], [$secondOwner, OrganizationNotFoundException::class]] as [$actor, $expected]) {
            try {
                $this->delete($actor, $entry->getId()->toRfc4122());
                self::fail('The entry was deleted.');
            } catch (\Throwable $e) {
                self::assertInstanceOf($expected, $e);
            }
        }

        self::assertSame([$entry], $this->entries->all());
    }

    public function test_it_answers_not_found_for_a_delete_of_an_unknown_entry(): void
    {
        $this->expectException(RevenueEntryNotFoundException::class);

        $this->delete($this->owner, 'not-a-uuid');
    }

    /**
     * @param list<array{paymentMethodId: string, amount: int}> $payments
     * @param list<array{rate: string, gross: int, vat: int}>          $vatLines
     */
    private function change(User $actor, string $entryId, array $payments, array $vatLines = [], ?string $note = null): void
    {
        (new ChangeRevenueEntryHandler($this->entries, $this->paymentMethods, $this->months, $this->history, $this->queryBus, $this->clock))(
            new ChangeRevenueEntryCommand($this->organizationId(), $actor->getId()->toRfc4122(), $entryId, $payments, $vatLines, $note),
        );
    }

    private function delete(User $actor, string $entryId): void
    {
        (new DeleteRevenueEntryHandler($this->entries, $this->queryBus, $this->months, $this->history, $this->clock))(
            new DeleteRevenueEntryCommand($this->organizationId(), $actor->getId()->toRfc4122(), $entryId),
        );
    }
}
