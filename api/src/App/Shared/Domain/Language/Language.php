<?php

declare(strict_types=1);

namespace App\Shared\Domain\Language;

/**
 * A language the product speaks (ADR 0028): the user's UI language, the language of the e-mails
 * they get and of the API's validation messages. English is the source and the fallback. Adding a
 * language means adding its case here, in `framework.enabled_locales`, in the frontend's `LANGUAGES`
 * (`@business-tool/ui/i18n/languages`) and its catalogs.
 */
enum Language: string
{
    case English = 'en';
    case Polish = 'pl';
}
