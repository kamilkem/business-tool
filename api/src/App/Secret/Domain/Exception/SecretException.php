<?php

declare(strict_types=1);

namespace App\Secret\Domain\Exception;

/** Base of the Secret module's exceptions. Messages carry ids and kinds, never a value (ADR 0026 §6). */
abstract class SecretException extends \DomainException
{
}
