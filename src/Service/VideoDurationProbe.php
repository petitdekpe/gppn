<?php

namespace App\Service;

use App\Entity\Video;
use App\Enum\VideoFileType;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;

/**
 * Durée d'un contenu, lue par ffmpeg dans ses fichiers déposés : la durée
 * n'est jamais saisie à la main.
 */
class VideoDurationProbe
{
    public function __construct(
        #[Autowire('%app.downloads_dir%')]
        private readonly string $downloadsDir,
        #[Autowire('%env(FFMPEG_BINARY)%')]
        private readonly string $ffmpegBinary,
    ) {
    }

    /**
     * Durée en secondes du premier fichier vidéo ou audio lisible, dans
     * l'ordre de VideoFileType::cases() (TV 1080p d'abord) ; null si aucun.
     */
    public function durationOf(Video $video): ?int
    {
        foreach (VideoFileType::cases() as $type) {
            if (!$type->isVideo() && !$type->isAudio()) {
                continue;
            }
            $fileName = $video->getVideoFileByType($type)?->getFileName();
            if ($fileName !== null && ($seconds = $this->probe($this->downloadsDir . '/' . $fileName)) !== null) {
                return $seconds;
            }
        }

        return null;
    }

    private function probe(string $path): ?int
    {
        if (!is_file($path)) {
            return null;
        }

        // Sans fichier de sortie, ffmpeg décrit l'entrée puis s'arrête en
        // erreur : seule la ligne « Duration: 00:01:23.45 » compte.
        $process = new Process([$this->ffmpegBinary, '-hide_banner', '-nostdin', '-i', $path]);
        $process->setTimeout(30);
        $process->run();

        if (!preg_match('/Duration: (\d+):(\d{2}):(\d{2}(?:\.\d+)?)/', $process->getErrorOutput(), $match)) {
            return null;
        }
        $seconds = (int) round((int) $match[1] * 3600 + (int) $match[2] * 60 + (float) $match[3]);

        return $seconds > 0 ? $seconds : null;
    }
}
