<?php

declare(strict_types=1);

namespace Ui\Http\Organization;

use App\Organization\Domain\Model\MembershipRole;
use App\Shared\Domain\Language\Language;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class AddMemberInput
{
    public function __construct(
        #[Assert\NotBlank(normalizer: 'trim')]
        #[Assert\Email(normalizer: 'trim')]
        #[Assert\Length(max: 180, normalizer: 'trim')]
        public string $email,
        /** Used only when the e-mail has no account yet; an existing user keeps their own name. */
        #[Assert\NotBlank(normalizer: 'trim')]
        #[Assert\Length(max: 100, normalizer: 'trim')]
        public string $firstName,
        /** Used only when the e-mail has no account yet; an existing user keeps their own name. */
        #[Assert\NotBlank(normalizer: 'trim')]
        #[Assert\Length(max: 100, normalizer: 'trim')]
        public string $lastName,
        /** Owners give any role; managers add employees only. */
        #[Assert\NotNull]
        public MembershipRole $role,
        /**
         * The language of the invitation e-mail and the new user's UI language (ADR 0028 §6). Used
         * only when the e-mail has no account yet; an existing user keeps their own. Null leaves it
         * unset, and the apps then use the browser's.
         */
        public ?Language $language = null,
    ) {
    }
}
