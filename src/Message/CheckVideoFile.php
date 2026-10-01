<?php

namespace App\Message;

/**
 * Demande de vérifier avec ffmpeg qu'une vidéo se lit (voir
 * VideoFileChecker), après son envoi ou un échec de lecture remonté par un
 * navigateur.
 */
final class CheckVideoFile
{
    public function __construct(
        public readonly int $videoFileId,
    ) {
    }
}
