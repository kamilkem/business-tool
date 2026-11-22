<?php

declare(strict_types=1);

namespace App\CashFlow\Application;

use App\CashFlow\Application\Query\GetDayClose\DayCloseLineView;
use App\CashFlow\Application\Query\GetDayClose\DayClosePaymentView;
use App\CashFlow\Application\Query\GetDayClose\DayCloseStorageView;
use App\CashFlow\Application\Query\GetDayClose\DayCloseView;
use App\CashFlow\Domain\Model\CashStorage;
use App\CashFlow\Domain\Model\DayClose;
use App\CashFlow\Domain\Model\DayCloseStorage;
use App\Income\Application\Query\GetPaymentMethod\PaymentMethodView;
use App\Income\Application\Query\GetRegister\RegisterView;
use App\Income\Application\Query\ListBookablePaymentMethods\ListBookablePaymentMethodsQuery;
use App\Income\Application\Query\ListBookableRegisters\ListBookableRegistersQuery;
use App\Income\Application\Query\ListPaymentMethods\ListPaymentMethodsQuery;
use App\Income\Application\Query\ListRegisters\ListRegistersQuery;
use App\Organization\Application\Query\GetMemberName\GetMemberNameQuery;
use App\Organization\Application\Query\GetMemberName\MemberNameView;
use App\Shared\Application\Query\QueryBusInterface;

/**
 * Builds the views of end-of-day closes (ADR 0033 §6), with the names of the member who closed
 * (Organization's queries) and of the register and payment methods (Income's).
 *
 * - For owners and managers (details): everything, the expected openings included; Income's names
 *   of deactivated registers and methods too.
 * - For a receipt (the response to the person closing): what they entered and the cash income, with
 *   no expected opening; only active registers' and methods' names (Income's queries for Cash flow).
 */
final readonly class DayCloseViews
{
    public function __construct(
        private QueryBusInterface $queryBus,
    ) {
    }

    /**
     * @param list<DayClose> $closes
     *
     * @return list<DayCloseView> in the same order
     */
    public function details(array $closes, string $organizationId, string $actorId): array
    {
        if ([] === $closes) {
            return [];
        }

        /** @var list<RegisterView> $registers */
        $registers = $this->queryBus->ask(new ListRegistersQuery($organizationId, $actorId, includeDeactivated: true)) ?? [];
        /** @var list<PaymentMethodView> $methods */
        $methods = $this->queryBus->ask(new ListPaymentMethodsQuery($organizationId, $actorId, includeDeactivated: true)) ?? [];

        return array_map(fn (DayClose $close): DayCloseView => $this->view($close, $organizationId, $actorId, $registers, $methods, true), $closes);
    }

    public function receipt(DayClose $close, string $organizationId, string $actorId): DayCloseView
    {
        /** @var list<RegisterView> $registers */
        $registers = $this->queryBus->ask(new ListBookableRegistersQuery());
        /** @var list<PaymentMethodView> $methods */
        $methods = $this->queryBus->ask(new ListBookablePaymentMethodsQuery());

        return $this->view($close, $organizationId, $actorId, $registers, $methods, false);
    }

    /**
     * @param list<RegisterView>      $registers
     * @param list<PaymentMethodView> $methods   in the organization's order
     */
    private function view(DayClose $close, string $organizationId, string $actorId, array $registers, array $methods, bool $withLedger): DayCloseView
    {
        $registerName = null;
        foreach ($registers as $register) {
            if ($register->id === $close->getRegisterId()->toRfc4122()) {
                $registerName = $register->name;
            }
        }

        /** @var MemberNameView|null $closedBy */
        $closedBy = $this->queryBus->ask(new GetMemberNameQuery($organizationId, $actorId, $close->getClosedBy()->toRfc4122()));
        $updatedById = $close->getUpdatedBy()?->toRfc4122();
        /** @var MemberNameView|null $updatedBy */
        $updatedBy = null === $updatedById ? null : $this->queryBus->ask(new GetMemberNameQuery($organizationId, $actorId, $updatedById));

        return new DayCloseView(
            $close->getId()->toRfc4122(),
            $close->getBusinessDate()->toString(),
            $close->getRegisterId()->toRfc4122(),
            $registerName,
            $close->getCurrency(),
            $close->getCashIncome(),
            $close->isShortage(),
            $this->storages($close, $withLedger),
            self::payments($close, $methods),
            $close->getClosedBy()->toRfc4122(),
            // Memberships are deactivated, never deleted (ADR 0014), so every close has its member.
            $closedBy->firstName ?? '',
            $closedBy->lastName ?? '',
            $close->getClosedAt(),
            $updatedById,
            null === $updatedById ? null : $updatedBy->firstName ?? '',
            null === $updatedById ? null : $updatedBy->lastName ?? '',
            $close->getUpdatedAt(),
        );
    }

    /** @return list<DayCloseStorageView> */
    private function storages(DayClose $close, bool $withLedger): array
    {
        $summaries = $close->getStorages();
        usort($summaries, static fn (DayCloseStorage $a, DayCloseStorage $b): int => CashStorage::compare($a->getStorage(), $b->getStorage()));

        $views = [];
        foreach ($summaries as $summary) {
            $storage = $summary->getStorage();
            $lines = [];
            foreach ($close->getLines() as $line) {
                if (!$line->getStorage()->getId()->equals($storage->getId())) {
                    continue;
                }
                $denomination = $line->getDenomination();
                $lines[] = new DayCloseLineView(
                    $denomination->getId()->toRfc4122(),
                    $denomination->getValue(),
                    $denomination->getKind()->value,
                    $line->getPieces(),
                    $line->getPieces() * $denomination->getValue(),
                );
            }
            usort($lines, static fn (DayCloseLineView $a, DayCloseLineView $b): int => [$a->value, $a->denominationId] <=> [$b->value, $b->denominationId]);

            $views[] = new DayCloseStorageView(
                $storage->getId()->toRfc4122(),
                $storage->getName(),
                $summary->getMode()->value,
                $summary->getTotal(),
                $withLedger ? $summary->getExpectedOpening() : null,
                $withLedger ? $summary->cashIncome() : null,
                $lines,
            );
        }

        return $views;
    }

    /**
     * @param list<PaymentMethodView> $methods in the organization's order
     *
     * @return list<DayClosePaymentView>
     */
    private static function payments(DayClose $close, array $methods): array
    {
        $order = [];
        $names = [];
        foreach ($methods as $position => $method) {
            $order[$method->id] = $position;
            $names[$method->id] = $method->name;
        }

        $views = [];
        foreach ($close->nonCashAmounts() as $methodId => $amount) {
            $views[] = new DayClosePaymentView((string) $methodId, $names[$methodId] ?? null, $amount);
        }
        usort($views, static fn (DayClosePaymentView $a, DayClosePaymentView $b): int => [$order[$a->paymentMethodId] ?? \PHP_INT_MAX, $a->paymentMethodId] <=> [$order[$b->paymentMethodId] ?? \PHP_INT_MAX, $b->paymentMethodId]);

        return $views;
    }
}
