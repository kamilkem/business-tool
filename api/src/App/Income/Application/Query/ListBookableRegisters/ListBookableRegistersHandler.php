<?php

declare(strict_types=1);

namespace App\Income\Application\Query\ListBookableRegisters;

use App\Income\Application\Query\GetRegister\RegisterView;
use App\Income\Domain\Model\Register;
use App\Income\Domain\Repository\RegisterRepositoryInterface;
use App\Income\Domain\Repository\RevenueEntryRepositoryInterface;
use App\Shared\Application\Query\QueryHandlerInterface;

final readonly class ListBookableRegistersHandler implements QueryHandlerInterface
{
    public function __construct(
        private RegisterRepositoryInterface $registers,
        private RevenueEntryRepositoryInterface $entries,
    ) {
    }

    /** @return list<RegisterView> active only, by name */
    public function __invoke(ListBookableRegistersQuery $query): array
    {
        $used = array_flip($this->entries->usedRegisterIds());

        return array_map(
            static fn (Register $register): RegisterView => RegisterView::fromRegister($register, isset($used[$register->getId()->toRfc4122()])),
            $this->registers->findAll(),
        );
    }
}
