<?php

declare(strict_types=1);

namespace Ui\Http\User;

use App\User\Application\Link\ClientApp;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class RequestPasswordResetInput
{
    public function __construct(
        /** The e-mail the person signs in with. */
        #[Assert\NotBlank(normalizer: 'trim')]
        #[Assert\Email(normalizer: 'trim')]
        #[Assert\Length(max: 180, normalizer: 'trim')]
        public string $email,
        /** The app whose "set a new password" page the e-mailed link opens. */
        #[Assert\NotNull]
        public ClientApp $app,
    ) {
    }
}
