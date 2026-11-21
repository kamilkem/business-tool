<?php

declare(strict_types=1);

namespace App\Income\Domain\Exception;

/**
 * A new order lists exactly the organization's active payment methods. Otherwise the list changed
 * since the client read it (a method was added, deactivated or reactivated): it reads it again.
 */
final class PaymentMethodOrderMismatchException extends IncomeException
{
    public static function of(int $activeCount, int $givenCount): self
    {
        return new self(\sprintf('The order must list each of the %d active payment methods once, %d ids given.', $activeCount, $givenCount), ['activeCount' => $activeCount, 'givenCount' => $givenCount]);
    }
}
