<?php

declare(strict_types=1);

namespace App\Tests\Api\Income;

use App\Income\Domain\Model\Register;
use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Model\Organization;
use App\Tests\Factory\OrganizationFactory;
use App\Tests\Fixtures\OrganizationMother;
use App\User\Domain\Model\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * POST, GET, PATCH and DELETE /revenue-entries (ADR 0022). The server's time is mocked at NOW (UTC),
 * which is already TODAY in the organizations' time zone, Europe/Warsaw.
 */
final class RevenueEntryApiTest extends IncomeApiTestCase
{
    private const string NOW = '2026-11-12 23:30:00';
    private const string TODAY = '2026-11-13';
    private const array MERGE_PATCH = ['Content-Type' => 'application/merge-patch+json'];

    /** @return iterable<string, array{MembershipRole}> */
    public static function managingRoles(): iterable
    {
        yield 'owner' => [MembershipRole::Owner];
        yield 'manager' => [MembershipRole::Manager];
    }

    #[DataProvider('managingRoles')]
    public function test_it_lets_owners_and_managers_record_an_entry(MembershipRole $role): void
    {
        [$actor, $organization] = self::memberWithRole($role);
        $register = self::addRegister($organization, 'Bar');
        self::mockTime(self::NOW);

        $response = self::record($actor, $organization, [
            'registerId' => $register->getId()->toRfc4122(),
            'businessDate' => '2026-11-12',
            'payments' => [self::pay($organization, 'Card', 250_000), self::pay($organization, 'Cash', 120_050), self::pay($organization, 'Transfer', 0)],
            'vatLines' => [['rate' => 'zw', 'gross' => 1_000, 'vat' => 0], ['rate' => '23', 'gross' => 300_000, 'vat' => 56_098]],
            'note' => '  Busy Thursday  ',
        ]);

        self::assertResponseStatusCodeSame(201);
        $entry = $response->toArray();
        self::assertTrue(Uuid::isValid($entry['id']));
        self::assertSame([
            'id' => $entry['id'],
            'registerId' => $register->getId()->toRfc4122(),
            'registerName' => 'Bar',
            'businessDate' => '2026-11-12',
            'currency' => 'PLN',
            'payments' => [self::paid($organization, 'Cash', 120_050), self::paid($organization, 'Card', 250_000), self::paid($organization, 'Transfer', 0)],
            'vatLines' => [['rate' => '23', 'gross' => 300_000, 'vat' => 56_098, 'net' => 243_902], ['rate' => 'zw', 'gross' => 1_000, 'vat' => 0, 'net' => 1_000]],
            'paymentsTotal' => 370_050,
            'vatGrossTotal' => 301_000,
            'vatTotal' => 56_098,
            'note' => 'Busy Thursday',
            'dayCloseId' => null,
            'monthClosed' => false,
            'createdBy' => $actor->getId()->toRfc4122(),
            'createdAt' => '2026-11-12T23:30:00+00:00',
            'updatedBy' => null,
            'updatedAt' => null,
        ], $entry);

        self::assertSame($entry, self::get($actor, $organization, $entry['id'])->toArray());
        self::assertResponseStatusCodeSame(200);
    }

    public function test_it_takes_the_currency_from_the_organization_not_the_client(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $register = self::addRegister($organization);
        self::mockTime(self::NOW);

        $entry = self::record($owner, $organization, self::body($register, '2026-11-12') + ['currency' => 'USD'])->toArray();

        self::assertResponseStatusCodeSame(201);
        self::assertSame('PLN', $entry['currency']);
    }

    public function test_it_takes_today_in_the_organizations_time_zone(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $register = self::addRegister($organization);
        self::mockTime(self::NOW);

        self::record($owner, $organization, self::body($register, self::TODAY));
        self::assertResponseStatusCodeSame(201);

        self::record($owner, $organization, self::body($register, '2026-11-14'));
        self::assertResponseStatusCodeSame(422);
    }

    public function test_it_answers_409_for_a_second_entry_of_the_register_and_date(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $bar = self::addRegister($organization, 'Bar');
        $kitchen = self::addRegister($organization, 'Kitchen');
        self::mockTime(self::NOW);
        self::record($owner, $organization, self::body($bar, '2026-11-12'));

        self::record($owner, $organization, self::body($bar, '2026-11-12'));
        self::assertResponseStatusCodeSame(409);

        self::record($owner, $organization, self::body($kitchen, '2026-11-12'));
        self::assertResponseStatusCodeSame(201);
        self::record($owner, $organization, self::body($bar, '2026-11-11'));
        self::assertResponseStatusCodeSame(201);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidNewEntries(): iterable
    {
        $valid = ['registerId' => 'BAR', 'businessDate' => '2026-11-12', 'payments' => [['paymentMethodId' => 'CASH', 'amount' => 100]]];

        yield 'no registerId' => [array_diff_key($valid, ['registerId' => true]), 'registerId'];
        yield 'registerId not a UUID' => [['registerId' => 'bar'] + $valid, 'registerId'];
        yield 'no businessDate' => [array_diff_key($valid, ['businessDate' => true]), 'businessDate'];
        yield 'businessDate with a time' => [['businessDate' => '2026-11-12T10:00:00Z'] + $valid, 'businessDate'];
        yield 'businessDate that does not exist' => [['businessDate' => '2026-02-30'] + $valid, 'businessDate'];
        yield 'businessDate relative' => [['businessDate' => 'yesterday'] + $valid, 'businessDate'];
        yield 'no payments' => [array_diff_key($valid, ['payments' => true]), 'payments'];
        yield 'empty payments' => [['payments' => []] + $valid, 'payments'];
        yield 'a method twice' => [['payments' => [['paymentMethodId' => 'CASH', 'amount' => 1], ['paymentMethodId' => 'CASH', 'amount' => 2]]] + $valid, 'payments'];
        yield 'a method id that is not a UUID' => [['payments' => [['paymentMethodId' => 'blik', 'amount' => 1]]] + $valid, 'payments[0].paymentMethodId'];
        yield 'a line without a method' => [['payments' => [['amount' => 1]]] + $valid, 'payments[0].paymentMethodId'];
        yield 'a negative amount' => [['payments' => [['paymentMethodId' => 'CASH', 'amount' => -1]]] + $valid, 'payments[0].amount'];
        yield 'an amount too large' => [['payments' => [['paymentMethodId' => 'CASH', 'amount' => 2_147_483_648]]] + $valid, 'payments[0].amount'];
        yield 'an amount with a fraction' => [['payments' => [['paymentMethodId' => 'CASH', 'amount' => 10.5]]] + $valid, 'payments[0].amount'];
        yield 'an amount as a string' => [['payments' => [['paymentMethodId' => 'CASH', 'amount' => '100']]] + $valid, 'payments[0].amount'];
        yield 'a line without an amount' => [['payments' => [['paymentMethodId' => 'CASH']]] + $valid, 'payments[0].amount'];
        yield 'a rate twice' => [['vatLines' => [['rate' => '23', 'gross' => 100, 'vat' => 19], ['rate' => '23', 'gross' => 1, 'vat' => 0]]] + $valid, 'vatLines'];
        yield 'a blank rate' => [['vatLines' => [['rate' => '', 'gross' => 100, 'vat' => 0]]] + $valid, 'vatLines[0].rate'];
        yield 'a negative gross' => [['vatLines' => [['rate' => '23', 'gross' => -1, 'vat' => 0]]] + $valid, 'vatLines[0].gross'];
        yield 'a line without VAT' => [['vatLines' => [['rate' => '23', 'gross' => 100]]] + $valid, 'vatLines[0].vat'];
        yield 'a VAT with a fraction' => [['vatLines' => [['rate' => '23', 'gross' => 100, 'vat' => 18.7]]] + $valid, 'vatLines[0].vat'];
        yield 'VAT above gross' => [['vatLines' => [['rate' => '23', 'gross' => 100, 'vat' => 101]]] + $valid, 'vatLines[0].vat'];
        yield 'a negative VAT' => [['vatLines' => [['rate' => '23', 'gross' => 100, 'vat' => -1]]] + $valid, 'vatLines[0].vat'];
        yield 'note too long' => [['note' => str_repeat('a', 1001)] + $valid, 'note'];
    }

    /** @param array<string, mixed> $body */
    #[DataProvider('invalidNewEntries')]
    public function test_it_answers_422_with_violations_for_an_invalid_new_entry(array $body, string $invalidField): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $register = self::addRegister($organization);
        self::mockTime(self::NOW);
        if ('BAR' === ($body['registerId'] ?? null)) {
            $body['registerId'] = $register->getId()->toRfc4122();
        }
        $body = self::withPaymentMethods($organization, $body);

        $response = self::record($owner, $organization, $body);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([$invalidField], array_values(array_unique(array_column($response->toArray(false)['violations'] ?? [], 'propertyPath'))));
    }

    /** @return iterable<string, array{list<array<string, mixed>>}> */
    public static function vatLinesAgainstTheCountry(): iterable
    {
        yield 'a rate Poland does not have' => [[['rate' => '7', 'gross' => 100, 'vat' => 7]]];
        yield 'VAT at an exempt rate' => [[['rate' => 'zw', 'gross' => 100, 'vat' => 1]]];
        yield 'VAT at 0%' => [[['rate' => '0', 'gross' => 100, 'vat' => 1]]];
    }

    /** @param list<array<string, mixed>> $vatLines */
    #[DataProvider('vatLinesAgainstTheCountry')]
    public function test_it_answers_422_for_vat_lines_the_country_does_not_allow(array $vatLines): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $register = self::addRegister($organization);
        self::mockTime(self::NOW);

        self::record($owner, $organization, ['vatLines' => $vatLines] + self::body($register, '2026-11-12'));

        self::assertResponseStatusCodeSame(422);
    }

    public function test_it_takes_no_vat_lines_in_a_country_without_rates(): void
    {
        $organization = OrganizationFactory::createOne(['countryCode' => 'DE', 'currency' => 'EUR', 'timezone' => 'Europe/Berlin']);
        $owner = OrganizationMother::ownerOf($organization);
        $register = self::addRegister($organization);
        self::mockTime(self::NOW);

        self::record($owner, $organization, ['vatLines' => [['rate' => '19', 'gross' => 119, 'vat' => 19]]] + self::body($register, '2026-11-12'));
        self::assertResponseStatusCodeSame(422);

        $entry = self::record($owner, $organization, self::body($register, '2026-11-12'))->toArray();
        self::assertResponseStatusCodeSame(201);
        self::assertSame(['EUR', []], [$entry['currency'], $entry['vatLines']]);
    }

    public function test_it_answers_422_for_a_payment_method_that_is_not_the_organizations(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        [, $elsewhere] = self::memberWithRole(MembershipRole::Owner);
        $register = self::addRegister($organization);
        self::mockTime(self::NOW);

        foreach ([self::paymentMethodId($elsewhere, 'Cash'), Uuid::v7()->toRfc4122()] as $methodId) {
            $response = self::record($owner, $organization, ['payments' => [['paymentMethodId' => $methodId, 'amount' => 100]]] + self::body($register, '2026-11-12'));
            self::assertResponseStatusCodeSame(422);
            self::assertSame(['income.invalid_payment_lines.unknown_method', ['paymentMethodId' => $methodId]], [$response->toArray(false)['code'], $response->toArray(false)['parameters']]);
        }
    }

    public function test_it_takes_no_new_line_of_a_deactivated_payment_method_but_keeps_an_existing_one(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $register = self::addRegister($organization);
        self::mockTime(self::NOW);
        $id = self::record($owner, $organization, ['payments' => [self::pay($organization, 'Cash', 100), self::pay($organization, 'Voucher', 50)]] + self::body($register, '2026-11-11'))->toArray()['id'];
        self::deactivatePaymentMethod($organization, 'Voucher');
        self::deactivatePaymentMethod($organization, 'Transfer');

        $response = self::record($owner, $organization, ['payments' => [self::pay($organization, 'Voucher', 50)]] + self::body($register, '2026-11-12'));
        self::assertResponseStatusCodeSame(422);
        self::assertSame('income.invalid_payment_lines.method_deactivated', $response->toArray(false)['code']);

        $entry = self::correct($owner, $organization, $id, ['payments' => [self::pay($organization, 'Cash', 100), self::pay($organization, 'Voucher', 70)]])->toArray();
        self::assertResponseStatusCodeSame(200);
        self::assertSame([self::paid($organization, 'Cash', 100), self::paid($organization, 'Voucher', 70)], $entry['payments']);

        self::correct($owner, $organization, $id, ['payments' => [self::pay($organization, 'Cash', 100), self::pay($organization, 'Transfer', 1)]]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(170, self::get($owner, $organization, $id)->toArray()['paymentsTotal']);
    }

    public function test_it_answers_422_for_a_deactivated_register(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $register = self::addRegister($organization);
        self::deactivateRegister($organization, $register);
        self::mockTime(self::NOW);

        self::record($owner, $organization, self::body($register, '2026-11-12'));

        self::assertResponseStatusCodeSame(422);
    }

    public function test_it_answers_404_for_a_register_that_is_not_the_organizations(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        [, $elsewhere] = self::memberWithRole(MembershipRole::Owner);
        $registerElsewhere = self::addRegister($elsewhere);
        self::mockTime(self::NOW);

        foreach ([$registerElsewhere->getId()->toRfc4122(), Uuid::v7()->toRfc4122()] as $registerId) {
            self::record($owner, $organization, ['registerId' => $registerId] + self::body($registerElsewhere, '2026-11-12'));
            self::assertResponseStatusCodeSame(404, $registerId);
        }
    }

    public function test_it_corrects_only_what_is_sent_and_records_who_changed_it(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $manager = self::addMember($organization, MembershipRole::Manager);
        $register = self::addRegister($organization);
        self::mockTime('2026-11-12 21:00:00');
        $id = self::record($owner, $organization, [
            'payments' => [self::pay($organization, 'Cash', 1_000), self::pay($organization, 'Card', 2_000)],
            'vatLines' => [['rate' => '23', 'gross' => 3_000, 'vat' => 561]],
            'note' => 'Draft',
        ] + self::body($register, '2026-11-12'))->toArray()['id'];
        self::mockTime(self::NOW);

        $entry = self::correct($manager, $organization, $id, ['note' => 'Counted again'])->toArray();
        self::assertResponseStatusCodeSame(200);
        self::assertSame([[self::paid($organization, 'Cash', 1_000), self::paid($organization, 'Card', 2_000)], 3_000, 3_000, 'Counted again', $manager->getId()->toRfc4122(), '2026-11-12T23:30:00+00:00'],
            [$entry['payments'], $entry['paymentsTotal'], $entry['vatGrossTotal'], $entry['note'], $entry['updatedBy'], $entry['updatedAt']]);

        $entry = self::correct($owner, $organization, $id, ['payments' => [self::pay($organization, 'Voucher', 500)], 'vatLines' => null])->toArray();
        self::assertResponseStatusCodeSame(200);
        self::assertSame([[self::paid($organization, 'Voucher', 500)], [], 500, 0, 0, 'Counted again', $owner->getId()->toRfc4122()],
            [$entry['payments'], $entry['vatLines'], $entry['paymentsTotal'], $entry['vatGrossTotal'], $entry['vatTotal'], $entry['note'], $entry['updatedBy']]);

        $entry = self::correct($owner, $organization, $id, ['vatLines' => [['rate' => '8', 'gross' => 500, 'vat' => 37]], 'note' => ''])->toArray();
        self::assertSame([[['rate' => '8', 'gross' => 500, 'vat' => 37, 'net' => 463]], null], [$entry['vatLines'], $entry['note']]);
        self::assertSame($entry, self::get($manager, $organization, $id)->toArray());
    }

    public function test_it_keeps_the_register_and_date_of_a_corrected_entry(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $bar = self::addRegister($organization, 'Bar');
        $kitchen = self::addRegister($organization, 'Kitchen');
        self::mockTime(self::NOW);
        $id = self::record($owner, $organization, self::body($bar, '2026-11-12'))->toArray()['id'];

        $entry = self::correct($owner, $organization, $id, ['registerId' => $kitchen->getId()->toRfc4122(), 'businessDate' => '2026-11-01'])->toArray();

        self::assertSame([$bar->getId()->toRfc4122(), '2026-11-12', null], [$entry['registerId'], $entry['businessDate'], $entry['updatedBy']]);
    }

    public function test_it_changes_nothing_when_the_values_are_the_same(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $register = self::addRegister($organization);
        self::mockTime(self::NOW);
        $id = self::record($owner, $organization, self::body($register, '2026-11-12') + ['note' => 'Same'])->toArray()['id'];

        $entry = self::correct($owner, $organization, $id, ['payments' => [self::pay($organization, 'Cash', 10_000)], 'vatLines' => [], 'note' => 'Same'])->toArray();

        self::assertResponseStatusCodeSame(200);
        self::assertSame([null, null], [$entry['updatedBy'], $entry['updatedAt']]);
    }

    public function test_it_corrects_an_entry_of_a_deactivated_register(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $register = self::addRegister($organization);
        self::mockTime(self::NOW);
        $id = self::record($owner, $organization, self::body($register, '2026-11-12'))->toArray()['id'];
        self::deactivateRegister($organization, $register);

        $entry = self::correct($owner, $organization, $id, ['payments' => [self::pay($organization, 'Cash', 1)]])->toArray();

        self::assertResponseStatusCodeSame(200);
        self::assertSame(1, $entry['paymentsTotal']);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidCorrections(): iterable
    {
        yield 'payments null' => [['payments' => null], 'payments'];
        yield 'empty payments' => [['payments' => []], 'payments'];
        yield 'a method twice' => [['payments' => [['paymentMethodId' => 'CARD', 'amount' => 1], ['paymentMethodId' => 'CARD', 'amount' => 2]]], 'payments'];
        yield 'a method id that is not a UUID' => [['payments' => [['paymentMethodId' => 'meal', 'amount' => 1]]], 'payments[0].paymentMethodId'];
        yield 'a negative amount' => [['payments' => [['paymentMethodId' => 'CASH', 'amount' => -5]]], 'payments[0].amount'];
        yield 'a rate twice' => [['vatLines' => [['rate' => '5', 'gross' => 1, 'vat' => 0], ['rate' => '5', 'gross' => 2, 'vat' => 0]]], 'vatLines'];
        yield 'VAT above gross' => [['vatLines' => [['rate' => '5', 'gross' => 1, 'vat' => 2]]], 'vatLines[0].vat'];
        yield 'note too long' => [['note' => str_repeat('a', 1001)], 'note'];
    }

    /** @param array<string, mixed> $body */
    #[DataProvider('invalidCorrections')]
    public function test_it_answers_422_with_violations_for_an_invalid_correction(array $body, string $invalidField): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $register = self::addRegister($organization);
        self::mockTime(self::NOW);
        $id = self::record($owner, $organization, self::body($register, '2026-11-12'))->toArray()['id'];

        $response = self::correct($owner, $organization, $id, self::withPaymentMethods($organization, $body));

        self::assertResponseStatusCodeSame(422);
        self::assertSame([$invalidField], array_values(array_unique(array_column($response->toArray(false)['violations'] ?? [], 'propertyPath'))));
        self::assertSame(10_000, self::get($owner, $organization, $id)->toArray()['paymentsTotal']);
    }

    public function test_it_answers_422_for_a_correction_with_a_rate_the_country_does_not_have(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $register = self::addRegister($organization);
        self::mockTime(self::NOW);
        $id = self::record($owner, $organization, self::body($register, '2026-11-12'))->toArray()['id'];

        self::correct($owner, $organization, $id, ['vatLines' => [['rate' => '22', 'gross' => 122, 'vat' => 22]]]);

        self::assertResponseStatusCodeSame(422);
    }

    public function test_it_deletes_an_entry_so_the_date_can_be_entered_again(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $manager = self::addMember($organization, MembershipRole::Manager);
        $register = self::addRegister($organization);
        self::mockTime(self::NOW);
        $id = self::record($owner, $organization, self::body($register, '2026-11-12'))->toArray()['id'];

        $response = self::delete($manager, $organization, $id);
        self::assertResponseStatusCodeSame(204);
        self::assertSame('', $response->getContent());

        self::get($owner, $organization, $id);
        self::assertResponseStatusCodeSame(404);
        self::delete($owner, $organization, $id);
        self::assertResponseStatusCodeSame(404);

        self::record($owner, $organization, self::body($register, '2026-11-12'));
        self::assertResponseStatusCodeSame(201);
    }

    public function test_it_answers_404_for_an_unknown_entry(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $id = Uuid::v7()->toRfc4122();

        self::get($owner, $organization, $id);
        self::assertResponseStatusCodeSame(404);
        self::correct($owner, $organization, $id, ['note' => 'x']);
        self::assertResponseStatusCodeSame(404);
        self::delete($owner, $organization, $id);
        self::assertResponseStatusCodeSame(404);
        static::clientFor($owner, $organization)->request('GET', '/api/revenue-entries/not-a-uuid');
        self::assertResponseStatusCodeSame(404);
    }

    public function test_it_hides_another_organizations_entries(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        [$ownerElsewhere, $elsewhere] = self::memberWithRole(MembershipRole::Owner);
        $registerElsewhere = self::addRegister($elsewhere);
        OrganizationMother::addMember($elsewhere, $owner, MembershipRole::Employee);
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        self::mockTime(self::NOW);
        $entry = self::record($ownerElsewhere, $elsewhere, self::body($registerElsewhere, '2026-11-12'))->toArray();

        self::get($owner, $organization, $entry['id']);
        self::assertResponseStatusCodeSame(404);
        self::correct($owner, $organization, $entry['id'], ['note' => 'Mine now']);
        self::assertResponseStatusCodeSame(404);
        self::delete($owner, $organization, $entry['id']);
        self::assertResponseStatusCodeSame(404);

        self::assertSame($entry, self::get($ownerElsewhere, $elsewhere, $entry['id'])->toArray());
    }

    /** @return iterable<string, array{\Closure(User, Organization, string): ResponseInterface}> */
    public static function requests(): iterable
    {
        yield 'GET' => [static fn (User $user, Organization $organization, string $id): ResponseInterface => self::get($user, $organization, $id)];
        yield 'PATCH' => [static fn (User $user, Organization $organization, string $id): ResponseInterface => self::correct($user, $organization, $id, ['note' => 'x'])];
        yield 'DELETE' => [static fn (User $user, Organization $organization, string $id): ResponseInterface => self::delete($user, $organization, $id)];
    }

    /** @param \Closure(User, Organization, string): ResponseInterface $request */
    #[DataProvider('requests')]
    public function test_it_refuses_an_entry_to_employees_and_deactivated_members(\Closure $request): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $employee = self::addMember($organization, MembershipRole::Employee);
        $formerManager = self::addMember($organization, MembershipRole::Manager);
        OrganizationMother::deactivateMember($organization, $formerManager);
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        $register = self::addRegister($organization);
        self::mockTime(self::NOW);
        $entry = self::record($owner, $organization, self::body($register, '2026-11-12'))->toArray();

        foreach ([$employee, $formerManager] as $user) {
            $request($user, $organization, $entry['id']);
            self::assertResponseStatusCodeSame(403);
        }

        self::assertSame($entry, self::get($owner, $organization, $entry['id'])->toArray());
    }

    public function test_it_refuses_recording_to_employees_and_deactivated_members(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $employee = self::addMember($organization, MembershipRole::Employee);
        $formerOwner = self::addMember($organization, MembershipRole::Owner);
        OrganizationMother::deactivateMember($organization, $formerOwner);
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        $register = self::addRegister($organization);
        self::mockTime(self::NOW);

        foreach ([$employee, $formerOwner] as $user) {
            self::record($user, $organization, self::body($register, '2026-11-12'));
            self::assertResponseStatusCodeSame(403);
        }

        self::record($owner, $organization, self::body($register, '2026-11-12'));
        self::assertResponseStatusCodeSame(201);
    }

    public function test_it_refuses_an_employee_before_looking_at_the_register(): void
    {
        [, $organization] = self::memberWithRole(MembershipRole::Owner);
        $employee = self::addMember($organization, MembershipRole::Employee);
        self::mockTime(self::NOW);

        self::record($employee, $organization, ['registerId' => Uuid::v7()->toRfc4122(), 'businessDate' => '2026-11-12', 'payments' => [self::pay($organization, 'Cash', 1)]]);

        self::assertResponseStatusCodeSame(403);
    }

    /** @param array<string, mixed> $body */
    private static function record(User $user, Organization $organization, array $body): ResponseInterface
    {
        return static::clientFor($user, $organization)->request('POST', '/api/revenue-entries', ['json' => $body]);
    }

    private static function get(User $user, Organization $organization, string $id): ResponseInterface
    {
        return static::clientFor($user, $organization)->request('GET', '/api/revenue-entries/'.$id);
    }

    /** @param array<string, mixed> $body */
    private static function correct(User $user, Organization $organization, string $id, array $body): ResponseInterface
    {
        return static::clientFor($user, $organization)->request('PATCH', '/api/revenue-entries/'.$id, [
            'json' => $body,
            'headers' => self::MERGE_PATCH,
        ]);
    }

    private static function delete(User $user, Organization $organization, string $id): ResponseInterface
    {
        return static::clientFor($user, $organization)->request('DELETE', '/api/revenue-entries/'.$id);
    }

    /** @return array{registerId: string, businessDate: string, payments: list<array{paymentMethodId: string, amount: int}>} */
    private static function body(Register $register, string $businessDate): array
    {
        $organization = $register->getOrganization() ?? throw new \LogicException('A stored register expected.');

        return ['registerId' => $register->getId()->toRfc4122(), 'businessDate' => $businessDate, 'payments' => [self::pay($organization, 'Cash', 10_000)]];
    }
}
