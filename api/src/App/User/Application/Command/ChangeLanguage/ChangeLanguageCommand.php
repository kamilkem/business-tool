<?php

declare(strict_types=1);

namespace App\User\Application\Command\ChangeLanguage;

use App\Shared\Application\Command\CommandInterface;

/** A user changes their own UI language (ADR 0028 §2). */
final readonly class ChangeLanguageCommand implements CommandInterface
{
    public function __construct(
        public string $userId,
        /** A Language code (`en`, `pl`); null when the user goes back to the browser's language. */
        public ?string $language,
    ) {
    }
}
