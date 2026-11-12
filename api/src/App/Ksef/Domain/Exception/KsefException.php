<?php

declare(strict_types=1);

namespace App\Ksef\Domain\Exception;

/** Base of the KSeF module's exceptions (ADR 0024: KSeF is its own module). */
abstract class KsefException extends \DomainException
{
}
