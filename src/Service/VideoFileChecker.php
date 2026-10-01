<?php

namespace App\Service;

use App\Entity\VideoFile;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;

/**
 * Vérifie qu'une vidéo se lit vraiment : ffmpeg doit en décoder les
 * premières secondes sans erreur. Un échec signalé par un navigateur ne
 * suffit pas à masquer un fichier (coupure réseau, codec non pris en charge
 * par ce seul navigateur) : c'est ce diagnostic qui tranche.
 */
class VideoFileChecker
{
    /** Secondes décodées : assez pour un fichier tronqué ou corrompu dès le début, rapide même en 1080p. */
    private const DECODED_SECONDS = 10;

    public function __construct(
        #[Autowire('%app.downloads_dir%')]
        private readonly string $downloadsDir,
        #[Autowire('%env(FFMPEG_BINARY)%')]
        private readonly string $ffmpegBinary,
    ) {
    }

    public function supports(VideoFile $file): bool
    {
        return $file->getFileName() !== null && (bool) $file->getType()?->isVideo();
    }

    /**
     * Met à jour le diagnostic du fichier (sans flush).
     *
     * @return bool true si le fichier est défectueux
     */
    public function check(VideoFile $file): bool
    {
        $path = $this->downloadsDir . '/' . $file->getFileName();
        $reason = is_file($path) ? $this->decodeError($path) : 'Fichier introuvable sur le serveur.';

        $file->setCheckedAt(new \DateTimeImmutable());
        if ($reason === null) {
            $file->clearDefect();

            return false;
        }

        $file->markDefective($reason);

        return true;
    }

    private function decodeError(string $path): ?string
    {
        $process = new Process([
            $this->ffmpegBinary, '-hide_banner', '-nostdin', '-v', 'error', '-xerror',
            '-i', $path,
            '-map', '0:v:0', '-t', (string) self::DECODED_SECONDS,
            '-f', 'null', '-',
        ]);
        $process->setTimeout(120);
        $process->run();

        if ($process->isSuccessful()) {
            return null;
        }

        $lines = array_values(array_filter(array_map('trim', explode("\n", $process->getErrorOutput()))));

        return 'Illisible (ffmpeg) : ' . ($lines !== [] ? end($lines) : 'code de sortie ' . $process->getExitCode());
    }
}
