<?php

declare(strict_types=1);

namespace App\Tests\Unit\CashFlow\Application;

use App\CashFlow\Application\Command\AddDenomination\AddDenominationCommand;
use App\CashFlow\Application\Command\AddDenomination\AddDenominationHandler;
use App\CashFlow\Application\EventHandler\AddDefaultDenominationsOnOrganizationCreated;
use App\CashFlow\Domain\Model\Denomination;
use App\CashFlow\Domain\Model\DenominationKind;
use App\Organization\Application\Query\GetMember\GetMemberHandler;
use App\Organization\Application\Query\GetMember\GetMemberQuery;
use App\Organization\Application\Query\GetOrganization\GetOrganizationHandler;
use App\Organization\Application\Query\GetOrganization\GetOrganizationQuery;
use App\Organization\Domain\Event\OrganizationCreated;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Model\Organization;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Command\CommandInterface;
use App\Shared\Application\Query\QueryInterface;
use App\Tests\Fixtures\OrganizationMother;
use App\Tests\Fixtures\UserMother;
use App\Tests\Unit\Organization\Application\InMemoryOrganizationRepository;
use App\Tests\Unit\Organization\Application\InMemoryTenantContext;
use App\Tests\Unit\Shared\Application\StubQueryBus;
use App\User\Domain\Model\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

/** The command bus runs the real AddDenominationHandler and notes which organization was selected for each command. */
#[CoversClass(AddDefaultDenominationsOnOrganizationCreated::class)]
final class AddDefaultDenominationsOnOrganizationCreatedTest extends TestCase
{
    private const string NOW = '2026-11-22 10:00:00';

    private InMemoryOrganizationRepository $organizations;
    private InMemoryDenominationRepository $denominations;
    private InMemoryTenantContext $tenantContext;
    /** @var list<?string> the selected organization's id when each command ran */
    private array $selectedWhileHandling = [];
    private User $owner;

    protected function setUp(): void
    {
        $this->organizations = new InMemoryOrganizationRepository();
        $this->denominations = new InMemoryDenominationRepository();
        $this->tenantContext = new InMemoryTenantContext();
        $this->owner = UserMother::any('olivia@example.com');
    }

    public function test_it_adds_the_currencys_defaults_as_the_owner_in_the_new_organization(): void
    {
        $organization = $this->organization('PLN');

        $this->handle($organization);

        self::assertSame([1, 2, 5, 10, 20, 50, 100, 200, 500, 1_000, 2_000, 5_000, 10_000, 20_000, 50_000], $this->values('PLN'));
        self::assertSame(array_fill(0, 15, $organization->getId()->toRfc4122()), $this->selectedWhileHandling);
        foreach ($this->denominations->findByCurrency('PLN') as $denomination) {
            self::assertEquals(new \DateTimeImmutable(self::NOW), $denomination->getCreatedAt());
            self::assertSame($denomination->getValue() >= 1_000 ? DenominationKind::Note : DenominationKind::Coin, $denomination->getKind());
        }
        self::assertFalse($this->tenantContext->hasOrganization());
    }

    public function test_it_adds_none_for_a_currency_without_defaults(): void
    {
        $this->handle($this->organization('EUR'));

        self::assertSame([], $this->values('EUR'));
        self::assertSame([], $this->selectedWhileHandling);
    }

    public function test_it_selects_the_previously_selected_organization_again(): void
    {
        $previous = OrganizationMother::ownedBy($this->owner, 'Other Bar');
        $this->tenantContext->switchTo($previous);
        $organization = $this->organization('PLN');

        $this->handle($organization);

        self::assertSame($previous, $this->tenantContext->getOrganization());
        self::assertSame($organization->getId()->toRfc4122(), $this->selectedWhileHandling[0]);
    }

    public function test_it_skips_values_the_organization_already_has_so_handling_twice_adds_nothing(): void
    {
        $organization = $this->organization('PLN');
        $this->denominations->add(Denomination::create(Uuid::v7(), 'PLN', 500, DenominationKind::Coin, null, $this->owner->getId(), MembershipRole::Owner, new \DateTimeImmutable('2026-11-01')));

        $this->handle($organization);
        $this->handle($organization);

        self::assertCount(15, $this->values('PLN'));
    }

    public function test_it_restores_the_selection_when_a_command_fails(): void
    {
        $organization = $this->organization('PLN');
        $handler = new AddDefaultDenominationsOnOrganizationCreated($this->organizations, $this->tenantContext, new class implements CommandBusInterface {
            public function handle(CommandInterface $command): void
            {
                throw new \RuntimeException('Database down.');
            }
        });

        try {
            $handler(new OrganizationCreated($organization->getId()->toRfc4122(), $this->owner->getId()->toRfc4122()));
            self::fail('The failure should propagate.');
        } catch (\RuntimeException) {
        }

        self::assertFalse($this->tenantContext->hasOrganization());
    }

    public function test_it_reports_an_unknown_organization(): void
    {
        $this->expectException(OrganizationNotFoundException::class);

        $this->handle(OrganizationMother::ownedBy($this->owner));
    }

    private function organization(string $currency): Organization
    {
        $organization = new Organization(Uuid::v7(), 'Corner Pub', $this->owner, new \DateTimeImmutable('2026-11-22 09:59:00'), currency: $currency);
        $this->organizations->add($organization);

        return $organization;
    }

    private function handle(Organization $organization): void
    {
        $organizations = $this->organizations;
        $queryBus = new StubQueryBus(static fn (QueryInterface $query): mixed => match (true) {
            $query instanceof GetMemberQuery => (new GetMemberHandler($organizations))($query),
            $query instanceof GetOrganizationQuery => (new GetOrganizationHandler($organizations))($query),
            default => throw new \LogicException(\sprintf('Unexpected query %s.', $query::class)),
        });
        $add = new AddDenominationHandler($this->denominations, $queryBus, new MockClock(self::NOW));
        $commandBus = new class($add, $this->tenantContext) implements CommandBusInterface {
            /** @var list<?string> */
            public array $selected = [];

            public function __construct(
                private readonly AddDenominationHandler $add,
                private readonly InMemoryTenantContext $tenantContext,
            ) {
            }

            public function handle(CommandInterface $command): void
            {
                \assert($command instanceof AddDenominationCommand);
                $this->selected[] = $this->tenantContext->hasOrganization() ? $this->tenantContext->getOrganization()->getId()->toRfc4122() : null;
                ($this->add)($command);
            }
        };

        (new AddDefaultDenominationsOnOrganizationCreated($this->organizations, $this->tenantContext, $commandBus))(
            new OrganizationCreated($organization->getId()->toRfc4122(), $this->owner->getId()->toRfc4122()),
        );
        $this->selectedWhileHandling = [...$this->selectedWhileHandling, ...$commandBus->selected];
    }

    /** @return list<int> */
    private function values(string $currency): array
    {
        return array_map(static fn (Denomination $d): int => $d->getValue(), $this->denominations->findByCurrency($currency));
    }
}
