<?php

namespace App\Service;

enum OtpResult
{
    case VALID;
    case INVALID;
    case EXPIRED;
    case TOO_MANY_ATTEMPTS;

    public function getMessage(): string
    {
        return match ($this) {
            self::VALID => 'Code vérifié.',
            self::INVALID => 'Code incorrect.',
            self::EXPIRED => 'Ce code a expiré. Demandez-en un nouveau.',
            self::TOO_MANY_ATTEMPTS => 'Trop d’essais incorrects.',
        };
    }
}
