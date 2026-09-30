<?php

namespace App\Message;

/**
 * Demande de tirer la couverture d'un contenu de sa vidéo TV (voir
 * VideoCoverGenerator), après l'envoi de celle-ci ou depuis l'action groupée
 * de la liste des contenus.
 */
final class GenerateVideoCover
{
    public function __construct(
        public readonly int $videoId,
        // Demandé explicitement : remplace aussi une couverture déposée à la main.
        public readonly bool $force = false,
    ) {
    }
}
