<?php

declare(strict_types=1);

namespace Ui\Http\User;

use App\Shared\Domain\Language\Language;
use App\User\Application\Query\GetMe\MeView;

/**
 * Merge-patch input for the signed-in user's own settings. Mutable on purpose:
 * UpdateMeInputProvider pre-fills it from the current state and API Platform applies the request
 * body onto it, so fields the client did not send keep their value. An unknown language is a 422
 * (a denormalization error).
 */
final class UpdateMeInput
{
    /** `en` or `pl`; null goes back to the browser's language (ADR 0028 §2). */
    public ?Language $language = null;

    public static function fromView(MeView $view): self
    {
        $input = new self();
        $input->language = null === $view->language ? null : Language::from($view->language);

        return $input;
    }
}
