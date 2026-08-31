<?php

namespace App\Enum;

enum VideoStatus: string
{
    case BROUILLON = 'brouillon';
    case EN_TRAITEMENT = 'en_traitement';
    case EN_RELECTURE = 'en_relecture';
    case PUBLIE = 'publie';
    case ECHEC = 'echec';

    public function getLabel(): string
    {
        return match ($this) {
            self::BROUILLON => 'Brouillon',
            self::EN_TRAITEMENT => 'En traitement',
            self::EN_RELECTURE => 'En relecture',
            self::PUBLIE => 'Publié',
            self::ECHEC => 'Échec',
        };
    }
}
