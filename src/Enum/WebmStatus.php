<?php

namespace App\Enum;

/**
 * État de la version WebM générée pour la lecture sur le site (voir
 * TranscodeToWebmHandler). Le fichier d'origine reste celui proposé au
 * téléchargement.
 */
enum WebmStatus: string
{
    case PENDING = 'pending';
    case PROCESSING = 'processing';
    case READY = 'ready';
    case FAILED = 'failed';

    public function getLabel(): string
    {
        return match ($this) {
            self::PENDING => 'en attente de conversion',
            self::PROCESSING => 'conversion en cours',
            self::READY => 'prête',
            self::FAILED => 'échec de la conversion',
        };
    }
}
