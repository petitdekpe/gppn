<?php

namespace App\Message;

/**
 * Demande la génération de la version WebM de lecture d'un VideoFile.
 */
final class TranscodeToWebm
{
    public function __construct(
        public readonly int $videoFileId,
    ) {
    }
}
