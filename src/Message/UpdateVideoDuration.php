<?php

namespace App\Message;

/**
 * Demande de relire la durée d'un contenu dans ses fichiers (voir
 * VideoDurationProbe), après l'envoi d'une vidéo ou d'un audio.
 */
final class UpdateVideoDuration
{
    public function __construct(
        public readonly int $videoId,
    ) {
    }
}
