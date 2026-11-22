<?php

declare(strict_types=1);

namespace App\Tests\Unit\Income\Application;

use App\Income\Application\Command\AddPaymentMethod\AddPaymentMethodCommand;
use App\Income\Application\Command\AddPaymentMethod\AddPaymentMethodHandler;
use App\Income\Application\EventHandler\AddDefaultPaymentMethodsOnOrganizationCreated;
use App\Income\Domain\Model\PaymentMethod;
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

/** The command bus runs the real AddPaymentMethodHandler and notes which organization was selected for each command. */
#[CoversClass(AddDefaultPaymentMethodsOnOrganizationCreated::class)]
final class AddDefaultPaymentMethodsOnOrganizationCreatedTest extends TestCase
{
    private const string NOW = '2026-11-13 10:00:00';

    private InMemoryOrganizationRepository $organizations;
    private InMemoryPaymentMethodRepository $paymentMethods;
    private InMemoryTenantContext $tenantContext;
    /** @var list<?string> the selected organization's id when each command ran */
    private array $selectedWhileHandling = [];
    private User $owner;

    protected function setUp(): void
    {
        $this->organizations = new InMemoryOrganizationRepository();
        $this->paymentMethods = new InMemoryPaymentMethodRepository();
        $this->tenantContext = new InMemoryTenantContext();
        $this->owner = UserMother::any('olivia@example.com');
    }

    public function test_it_adds_the_countrys_defaults_in_order_as_the_owner_in_the_new_organization(): void
    {
        $organization = $this->organization('PL');

        $this->handle($organization);

        self::assertSame(['Gotówka', 'Karta', 'Przelew', 'Bon'], $this->names());
        self::assertSame('Gotówka', $this->paymentMethods->findActiveCash()?->getName());
        self::assertSame(array_fill(0, 4, $organization->getId()->toRfc4122()), $this->selectedWhileHandling);
        foreach ($this->paymentMethods->findAll() as $method) {
            self::assertEquals(new \DateTimeImmutable(self::NOW), $method->getCreatedAt());
            self::assertTrue($method->isActive());
        }
        self::assertFalse($this->tenantContext->hasOrganization());
    }

    public function test_it_adds_english_defaults_for_a_country_without_a_list(): void
    {
        $this->handle($this->organization('DE'));

        self::assertSame(['Cash', 'Card', 'Transfer', 'Voucher'], $this->names());
        self::assertSame('Cash', $this->paymentMethods->findActiveCash()?->getName());
    }

    public function test_it_selects_the_previously_selected_organization_again(): void
    {
        $previous = OrganizationMother::ownedBy($this->owner, 'Other Bar');
        $this->tenantContext->switchTo($previous);
        $organization = $this->organization('PL');

        $this->handle($organization);

        self::assertSame($previous, $this->tenantContext->getOrganization());
        self::assertSame($organization->getId()->toRfc4122(), $this->selectedWhileHandling[0]);
    }

    public function test_it_skips_names_the_organization_already_has_so_handling_twice_adds_nothing(): void
    {
        $organization = $this->organization('PL');
        $this->paymentMethods->add(PaymentMethod::create(Uuid::v7(), 'Karta', null, 0, false, null, $this->owner->getId(), MembershipRole::Owner, new \DateTimeImmutable('2026-11-01')));

        $this->handle($organization);
        $this->handle($organization);

        self::assertSame(['Karta', 'Gotówka', 'Przelew', 'Bon'], $this->names());
    }

    public function test_it_restores_the_selection_when_a_command_fails(): void
    {
        $organization = $this->organization('PL');
        $handler = new AddDefaultPaymentMethodsOnOrganizationCreated($this->organizations, $this->tenantContext, new class implements CommandBusInterface {
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

    private function organization(string $countryCode): Organization
    {
        $organization = new Organization(Uuid::v7(), 'Corner Pub', $this->owner, new \DateTimeImmutable('2026-11-13 09:59:00'), countryCode: $countryCode);
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
        $addMethod = new AddPaymentMethodHandler($this->paymentMethods, $queryBus, new MockClock(self::NOW));
        $commandBus = new class($addMethod, $this->tenantContext) implements CommandBusInterface {
            /** @var list<?string> */
            public array $selected = [];

            public function __construct(
                private readonly AddPaymentMethodHandler $addMethod,
                private readonly InMemoryTenantContext $tenantContext,
            ) {
            }

            public function handle(CommandInterface $command): void
            {
                \assert($command instanceof AddPaymentMethodCommand);
                $this->selected[] = $this->tenantContext->hasOrganization() ? $this->tenantContext->getOrganization()->getId()->toRfc4122() : null;
                ($this->addMethod)($command);
            }
        };

        (new AddDefaultPaymentMethodsOnOrganizationCreated($this->organizations, $this->tenantContext, $commandBus))(
            new OrganizationCreated($organization->getId()->toRfc4122(), $this->owner->getId()->toRfc4122()),
        );
        $this->selectedWhileHandling = [...$this->selectedWhileHandling, ...$commandBus->selected];
    }

    /** @return list<string> */
    private function names(): array
    {
        return array_map(static fn (PaymentMethod $method): string => $method->getName(), $this->paymentMethods->findAll());
    }
}
