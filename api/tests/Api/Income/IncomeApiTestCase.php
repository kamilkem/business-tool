<?php

declare(strict_types=1);

namespace App\Tests\Api\Income;

use App\Income\Domain\Model\PaymentMethod;
use App\Income\Domain\Model\Register;
use App\Income\Domain\Repository\PaymentMethodRepositoryInterface;
use App\Income\Domain\Repository\RegisterRepositoryInterface;
use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Model\Organization;
use App\Organization\Domain\TenantContextInterface;
use App\Tests\Api\ApiTestCase;
use App\Tests\Factory\UserFactory;
use App\Tests\Fixtures\OrganizationMother;
use App\User\Domain\Model\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Uid\Uuid;

/**
 * Arranges income data for API tests: registers and payment methods are stored through their
 * repositories in the organization's tenant context.
 */
abstract class IncomeApiTestCase extends ApiTestCase
{
    /** The names of the default methods (ADR 0030 §2) by the placeholder data providers use for their ids. */
    protected const array DEFAULT_METHODS = ['CASH' => 'Cash', 'CARD' => 'Card', 'TRANSFER' => 'Transfer', 'VOUCHER' => 'Voucher'];

    /** @var array<string, array<string, string>> method ids by name, per organization id */
    private static array $paymentMethodIds = [];

    /**
     * The id of the organization's payment method with the name. The first call for an organization
     * stores Cash, Card, Transfer and Voucher in that order, as a new organization gets them; another
     * name is added at the end of the list.
     */
    protected static function paymentMethodId(Organization $organization, string $name = 'Cash'): string
    {
        $key = $organization->getId()->toRfc4122();
        if (!isset(self::$paymentMethodIds[$key])) {
            self::$paymentMethodIds[$key] = [];
            foreach (self::DEFAULT_METHODS as $default) {
                self::addPaymentMethod($organization, $default, 'Cash' === $default);
            }
        }

        return self::$paymentMethodIds[$key][$name] ?? self::addPaymentMethod($organization, $name)->getId()->toRfc4122();
    }

    /** @param bool $cash as the default "Cash" method is (ADR 0033 §9) */
    protected static function addPaymentMethod(Organization $organization, string $name, bool $cash = false): PaymentMethod
    {
        $owner = OrganizationMother::ownerOf($organization);
        $method = null;
        self::inTenant($organization, static function () use ($name, $cash, $owner, &$method): void {
            $methods = static::getContainer()->get(PaymentMethodRepositoryInterface::class);
            $method = PaymentMethod::create(Uuid::v7(), $name, null, $methods->nextPosition(), $cash, null, $owner->getId(), MembershipRole::Owner, Clock::get()->now());
            $methods->add($method);
        });
        \assert($method instanceof PaymentMethod);
        self::$paymentMethodIds[$organization->getId()->toRfc4122()][$name] = $method->getId()->toRfc4122();

        return $method;
    }

    protected static function deactivatePaymentMethod(Organization $organization, string $name): void
    {
        $id = self::paymentMethodId($organization, $name);
        $owner = OrganizationMother::ownerOf($organization);

        self::inTenant($organization, static function () use ($id, $owner): void {
            $methods = static::getContainer()->get(PaymentMethodRepositoryInterface::class);
            $managed = $methods->findById(Uuid::fromString($id)) ?? throw new \LogicException('Payment method expected.');
            $managed->deactivate($owner->getId(), MembershipRole::Owner, Clock::get()->now());
            $methods->save($managed);
        });
    }

    /**
     * A payment line of a request body.
     *
     * @return array{paymentMethodId: string, amount: int}
     */
    protected static function pay(Organization $organization, string $name, int $amount): array
    {
        return ['paymentMethodId' => self::paymentMethodId($organization, $name), 'amount' => $amount];
    }

    /**
     * A payment line of a response.
     *
     * @return array{paymentMethodId: string, paymentMethodName: string, amount: int}
     */
    protected static function paid(Organization $organization, string $name, int $amount): array
    {
        return ['paymentMethodId' => self::paymentMethodId($organization, $name), 'paymentMethodName' => $name, 'amount' => $amount];
    }

    /**
     * Replaces the placeholders of data providers (`'paymentMethodId' => 'CASH'`) by the
     * organization's ids.
     *
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    protected static function withPaymentMethods(Organization $organization, array $body): array
    {
        if (!\is_array($body['payments'] ?? null)) {
            return $body;
        }
        foreach ($body['payments'] as $index => $line) {
            if (\is_array($line) && \is_string($line['paymentMethodId'] ?? null) && isset(self::DEFAULT_METHODS[$line['paymentMethodId']])) {
                $body['payments'][$index]['paymentMethodId'] = self::paymentMethodId($organization, self::DEFAULT_METHODS[$line['paymentMethodId']]);
            }
        }

        return $body;
    }

    protected static function addRegister(Organization $organization, string $name = 'Bar'): Register
    {
        $owner = OrganizationMother::ownerOf($organization);
        $register = Register::create(Uuid::v7(), $name, null, $owner->getId(), MembershipRole::Owner, Clock::get()->now());

        self::inTenant($organization, static fn () => static::getContainer()->get(RegisterRepositoryInterface::class)->add($register));

        return $register;
    }

    protected static function deactivateRegister(Organization $organization, Register $register): void
    {
        $owner = OrganizationMother::ownerOf($organization);

        self::inTenant($organization, static function () use ($register, $owner): void {
            $registers = static::getContainer()->get(RegisterRepositoryInterface::class);
            $managed = $registers->findById($register->getId()) ?? throw new \LogicException('Register expected.');
            $managed->deactivate($owner->getId(), MembershipRole::Owner, Clock::get()->now());
            $registers->save($managed);
        });
    }

    protected static function addMember(Organization $organization, MembershipRole $role): User
    {
        $user = UserFactory::createOne();
        OrganizationMother::addMember($organization, $user, $role);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        return $user;
    }

    protected static function inTenant(Organization $organization, \Closure $action): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $tenantContext = static::getContainer()->get(TenantContextInterface::class);
        $tenantContext->switchTo($entityManager->find(Organization::class, $organization->getId()) ?? throw new \LogicException('Organization expected.'));
        try {
            $action();
        } finally {
            $tenantContext->reset();
            // A request starts with an empty identity map: an arranged entity must not be found by
            // id there without the TenantFilter (EntityManager::find() reads the map first).
            $entityManager->clear();
        }
    }
}
