<?php

namespace App\Enum;

enum VideoStatus: string
{
    case BROUILLON = 'brouillon';
    case PUBLIE = 'publie';
    case MASQUE = 'masque';

    public function getLabel(): string
    {
        return match ($this) {
            self::BROUILLON => 'Brouillon',
            self::PUBLIE => 'Publié',
            self::MASQUE => 'Masqué',
        };
    }
}
