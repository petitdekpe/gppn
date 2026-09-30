<?php

namespace App\Message;

/**
 * Demande de tirer la couverture d'un contenu de sa vidéo TV, après l'envoi
 * de celle-ci (voir VideoCoverGenerator).
 */
final class GenerateVideoCover
{
    public function __construct(
        public readonly int $videoId,
    ) {
    }
}
