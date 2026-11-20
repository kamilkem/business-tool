<?php

declare(strict_types=1);

namespace App\Secret\Domain\Model;

/**
 * What a stored secret is (ADR 0026 §1). It never changes after creation and is part of the
 * associated data, so a value cannot be read back as another kind. A kind with several parts (a KSeF
 * certificate with its key and passphrase) encrypts one JSON document whose shape the kind defines;
 * a new kind is a new case, without migrating data.
 */
enum SecretKind: string
{
    /** A KSeF token, the plain string the owner generated in KSeF (ADR 0023 §2). */
    case KsefToken = 'ksef_token';
}
