<?php

declare(strict_types=1);

namespace App\Tests\Unit\Secret\Application;

use App\Organization\Domain\Model\Organization;
use App\Secret\Application\Command\ReEncryptOrganizationSecret\ReEncryptOrganizationSecretCommand;
use App\Secret\Application\Query\ListOrganizationSecretsToReEncrypt\ListOrganizationSecretsToReEncryptQuery;
use App\Secret\Application\Rotation\ReEncryptionReport;
use App\Secret\Application\Rotation\ReEncryptSecrets;
use App\Secret\Domain\Exception\SecretUnreadableException;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Command\CommandInterface;
use App\Shared\Application\Query\QueryInterface;
use App\Tests\Fixtures\OrganizationMother;
use App\Tests\Fixtures\UserMother;
use App\Tests\Unit\Organization\Application\InMemoryOrganizationRepository;
use App\Tests\Unit\Organization\Application\InMemoryTenantContext;
use App\Tests\Unit\Shared\Application\StubQueryBus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/** The rotation's fan-out (adr/0026 §4): per organization, switch to it, ask, send one command per secret. */
#[CoversClass(ReEncryptSecrets::class)]
#[CoversClass(ReEncryptionReport::class)]
final class ReEncryptSecretsTest extends TestCase
{
    private InMemoryOrganizationRepository $organizations;
    private InMemoryTenantContext $tenantContext;
    private Organization $bar;
    private Organization $cafe;

    /** @var array<string, list<string>> organization id => ids of its secrets under an old key */
    private array $stale = [];

    /** @var list<string> */
    private array $unreadable = [];

    /** @var list<array{string, string, string}> [tenant at the time, organization id, secret id] */
    private array $sent = [];

    protected function setUp(): void
    {
        $this->organizations = new InMemoryOrganizationRepository();
        $this->tenantContext = new InMemoryTenantContext();
        $this->bar = OrganizationMother::ownedBy(UserMother::any('bar@example.com'), 'Bar');
        $this->cafe = OrganizationMother::ownedBy(UserMother::any('cafe@example.com'), 'Cafe');
        $this->organizations->add($this->bar);
        $this->organizations->add($this->cafe);
    }

    public function test_it_re_encrypts_each_organizations_stale_secrets_with_that_organization_selected(): void
    {
        $barId = $this->bar->getId()->toRfc4122();
        $cafeId = $this->cafe->getId()->toRfc4122();
        $this->stale = [$barId => ['s1', 's2'], $cafeId => ['s3']];

        $report = $this->rotate();

        self::assertEquals(new ReEncryptionReport(2, 3, []), $report);
        self::assertEqualsCanonicalizing([[$barId, $barId, 's1'], [$barId, $barId, 's2'], [$cafeId, $cafeId, 's3']], $this->sent);
        self::assertFalse($this->tenantContext->hasOrganization());
    }

    public function test_it_goes_on_after_a_secret_that_does_not_decrypt_and_reports_it(): void
    {
        $this->stale = [$this->bar->getId()->toRfc4122() => ['broken', 's2'], $this->cafe->getId()->toRfc4122() => ['s3']];
        $this->unreadable = ['broken'];

        $report = $this->rotate();

        self::assertSame(2, $report->reEncrypted);
        self::assertSame(['broken'], $report->unreadableSecretIds);
    }

    public function test_it_restores_the_previously_selected_organization(): void
    {
        $this->tenantContext->switchTo($this->cafe);

        $this->rotate();

        self::assertSame($this->cafe, $this->tenantContext->getOrganization());
    }

    private function rotate(): ReEncryptionReport
    {
        $queryBus = new StubQueryBus(fn (QueryInterface $query): mixed => match (true) {
            $query instanceof ListOrganizationSecretsToReEncryptQuery => $this->stale[$query->organizationId] ?? [],
            default => throw new \LogicException(\sprintf('Unexpected query %s.', $query::class)),
        });
        $test = $this;
        $commandBus = new class($test) implements CommandBusInterface {
            public function __construct(private readonly ReEncryptSecretsTest $test)
            {
            }

            public function handle(CommandInterface $command): void
            {
                \assert($command instanceof ReEncryptOrganizationSecretCommand);
                $this->test->received($command);
            }
        };

        return (new ReEncryptSecrets($this->organizations, $this->tenantContext, $queryBus, $commandBus))();
    }

    /** @internal for the command bus above */
    public function received(ReEncryptOrganizationSecretCommand $command): void
    {
        if (\in_array($command->secretId, $this->unreadable, true)) {
            throw SecretUnreadableException::unknownKey('gone');
        }

        $this->sent[] = [$this->tenantContext->getOrganization()->getId()->toRfc4122(), $command->organizationId, $command->secretId];
    }
}
