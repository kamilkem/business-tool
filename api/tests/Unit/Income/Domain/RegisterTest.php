<?php

declare(strict_types=1);

namespace App\Tests\Unit\Income\Domain;

use App\Income\Domain\Exception\IncomeManagementForbiddenException;
use App\Income\Domain\Exception\InvalidRegisterNameException;
use App\Income\Domain\Exception\RegisterInUseException;
use App\Income\Domain\Exception\RegisterNameTakenException;
use App\Income\Domain\Model\IncomeAccess;
use App\Income\Domain\Model\Register;
use App\Organization\Domain\Model\MembershipRole;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(Register::class)]
#[CoversClass(IncomeAccess::class)]
final class RegisterTest extends TestCase
{
    private const string NOW = '2026-11-12 10:00:00';

    private Uuid $actorId;

    protected function setUp(): void
    {
        $this->actorId = Uuid::v7();
    }

    public function test_it_creates_an_active_register_with_a_trimmed_name(): void
    {
        $id = Uuid::v7();
        $now = new \DateTimeImmutable(self::NOW);

        $register = Register::create($id, '  Bar  ', null, $this->actorId, MembershipRole::Manager, $now);

        self::assertSame($id, $register->getId());
        self::assertSame('Bar', $register->getName());
        self::assertSame($now, $register->getCreatedAt());
        self::assertTrue($register->isActive());
        self::assertNull($register->getDeactivatedAt());
    }

    public function test_it_lets_an_employee_create_no_register(): void
    {
        $this->expectException(IncomeManagementForbiddenException::class);

        Register::create(Uuid::v7(), 'Bar', null, $this->actorId, MembershipRole::Employee, new \DateTimeImmutable(self::NOW));
    }

    #[DataProvider('invalidNames')]
    public function test_it_rejects_a_blank_or_too_long_name(string $name): void
    {
        $this->expectException(InvalidRegisterNameException::class);

        Register::create(Uuid::v7(), $name, null, $this->actorId, MembershipRole::Owner, new \DateTimeImmutable(self::NOW));
    }

    /** @return iterable<string, array{string}> */
    public static function invalidNames(): iterable
    {
        yield 'empty' => [''];
        yield 'spaces' => ['   '];
        yield 'too long' => [str_repeat('a', Register::NAME_MAX_LENGTH + 1)];
    }

    public function test_it_accepts_a_name_of_the_maximum_length_in_characters(): void
    {
        $name = str_repeat('ż', Register::NAME_MAX_LENGTH);

        self::assertSame($name, $this->register($name)->getName());
    }

    public function test_it_rejects_a_name_another_register_has(): void
    {
        $existing = $this->register('Bar');

        $this->expectException(RegisterNameTakenException::class);

        Register::create(Uuid::v7(), 'Bar', $existing, $this->actorId, MembershipRole::Owner, new \DateTimeImmutable(self::NOW));
    }

    public function test_it_renames_a_register(): void
    {
        $register = $this->register('Bar');

        $register->rename(' Main bar ', null, $this->actorId, MembershipRole::Manager);

        self::assertSame('Main bar', $register->getName());
    }

    public function test_it_renames_a_register_to_its_own_name(): void
    {
        $register = $this->register('Bar');

        $register->rename('Bar', $register, $this->actorId, MembershipRole::Owner);

        self::assertSame('Bar', $register->getName());
    }

    public function test_it_refuses_to_rename_a_register_to_another_registers_name(): void
    {
        $register = $this->register('Bar');

        $this->expectException(RegisterNameTakenException::class);

        $register->rename('Kitchen', $this->register('Kitchen'), $this->actorId, MembershipRole::Owner);
    }

    public function test_it_lets_an_employee_rename_no_register(): void
    {
        $register = $this->register('Bar');

        $this->expectException(IncomeManagementForbiddenException::class);

        $register->rename('Main bar', null, $this->actorId, MembershipRole::Employee);
    }

    public function test_it_deactivates_a_register_once(): void
    {
        $register = $this->register('Bar');
        $first = new \DateTimeImmutable('2026-11-12 11:00:00');

        $register->deactivate($this->actorId, MembershipRole::Manager, $first);
        $register->deactivate($this->actorId, MembershipRole::Manager, new \DateTimeImmutable('2026-11-13 11:00:00'));

        self::assertFalse($register->isActive());
        self::assertSame($first, $register->getDeactivatedAt());
    }

    public function test_it_lets_an_employee_deactivate_no_register(): void
    {
        $register = $this->register('Bar');

        $this->expectException(IncomeManagementForbiddenException::class);

        $register->deactivate($this->actorId, MembershipRole::Employee, new \DateTimeImmutable(self::NOW));
    }

    public function test_it_renames_a_deactivated_register(): void
    {
        $register = $this->register('Terrace');
        $register->deactivate($this->actorId, MembershipRole::Owner, new \DateTimeImmutable(self::NOW));

        $register->rename('Summer terrace', null, $this->actorId, MembershipRole::Owner);

        self::assertSame('Summer terrace', $register->getName());
    }

    public function test_it_is_deletable_only_without_entries(): void
    {
        $register = $this->register('Terrace');

        $register->assertDeletable(false, $this->actorId, MembershipRole::Manager);

        try {
            $register->assertDeletable(true, $this->actorId, MembershipRole::Owner);
            self::fail('A register with entries was deletable.');
        } catch (RegisterInUseException $e) {
            self::assertSame('income.register_in_use', $e->errorCode());
            self::assertSame(['registerId' => $register->getId()->toRfc4122(), 'name' => 'Terrace'], $e->errorParameters());
        }
    }

    public function test_it_lets_an_employee_delete_no_register(): void
    {
        $register = $this->register('Terrace');

        $this->expectException(IncomeManagementForbiddenException::class);

        $register->assertDeletable(false, $this->actorId, MembershipRole::Employee);
    }

    private function register(string $name): Register
    {
        return Register::create(Uuid::v7(), $name, null, $this->actorId, MembershipRole::Owner, new \DateTimeImmutable(self::NOW));
    }
}
