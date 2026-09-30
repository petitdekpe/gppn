<?php

namespace App\Service;

use App\Entity\Video;
use App\Enum\VideoFileType;
use App\Exception\CoverGenerationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Process\Process;

/**
 * Tire l'image de couverture d'un contenu de sa vidéo TV (1080p), avec
 * ffmpeg. Seule la vidéo TV sert : elle a le cadrage horizontal des cartes.
 *
 * - automatique (après l'envoi d'une vidéo TV, ou commande de rattrapage) :
 *   à 15 s, parmi les images des 2 secondes suivantes, la plus
 *   représentative (évite une image noire ou un fondu) ; jamais à la place
 *   d'une couverture déposée à la main ;
 * - à la demande (bouton de l'administration) : l'image exacte de la
 *   seconde choisie, qui remplace la couverture quelle qu'elle soit.
 */
class VideoCoverGenerator
{
    public const DEFAULT_SECOND = 15;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        #[Autowire('%app.downloads_dir%')]
        private readonly string $downloadsDir,
        #[Autowire('%env(FFMPEG_BINARY)%')]
        private readonly string $ffmpegBinary,
    ) {
    }

    /** Couverture absente, ou générée : une couverture déposée à la main n'est jamais remplacée d'office. */
    public function canReplaceAutomatically(Video $video): bool
    {
        return $video->getCoverImageName() === null || $video->isCoverGenerated();
    }

    public function hasSource(Video $video): bool
    {
        return $video->getVideoFileByType(VideoFileType::MP4_1080P)?->getFileName() !== null;
    }

    /**
     * @param int|null $second seconde choisie par l'éditeur ; null = automatique
     *
     * @return int seconde réellement utilisée
     */
    public function generate(Video $video, ?int $second = null): int
    {
        $source = $video->getVideoFileByType(VideoFileType::MP4_1080P);
        if ($source?->getFileName() === null) {
            throw new CoverGenerationException('Ce contenu n’a pas de vidéo TV (1080p) : la couverture ne peut pas en être tirée.');
        }

        $duration = $video->getDurationSeconds();
        if ($second !== null && $duration > 0 && $second >= $duration) {
            throw new CoverGenerationException(sprintf('La vidéo TV dure %d s : choisissez une seconde entre 0 et %d.', $duration, $duration - 1));
        }
        // Vidéo plus courte que 15 s : on vise le milieu.
        $at = $second ?? ($duration > 0 && $duration <= self::DEFAULT_SECOND ? intdiv($duration, 2) : self::DEFAULT_SECOND);

        $path = $this->downloadsDir . '/' . $source->getFileName();
        if (!is_file($path)) {
            throw new CoverGenerationException(sprintf('Le fichier de la vidéo TV est introuvable sur le serveur (%s).', $source->getFileName()));
        }

        $output = sys_get_temp_dir() . '/cover_' . bin2hex(random_bytes(6)) . '.jpg';
        try {
            $error = $this->extract($path, $at, $second === null, $output);
            // Durée inconnue et vidéo plus courte que 15 s : rien à cette
            // position, on se rabat sur la première seconde.
            if ($error !== null && $second === null && $at > 1) {
                $at = 1;
                $error = $this->extract($path, $at, true, $output);
            }
            if ($error !== null) {
                throw new CoverGenerationException(sprintf('Aucune image à %d s : la vidéo TV est probablement plus courte, ou illisible. Détail ffmpeg : %s', $at, $error));
            }

            $video
                ->setCoverImageFile(new UploadedFile($output, sprintf('couverture-%s-%ds.jpg', $video->getSlug() ?: 'contenu', $at), 'image/jpeg', null, true))
                ->setCoverGenerated(true);
            // L'image est copiée dans le stockage des couvertures pendant le flush.
            $this->entityManager->flush();
        } finally {
            @unlink($output);
        }

        return $at;
    }

    /**
     * @return string|null null si l'image est produite, sinon la fin de la sortie d'erreur de ffmpeg
     */
    private function extract(string $path, int $at, bool $pickBest, string $output): ?string
    {
        // Largeur des cartes et de la fiche détail, sans agrandir une vidéo plus petite.
        $filters = 'scale=min(1280\,iw):-2';
        if ($pickBest) {
            $filters = 'thumbnail=50,' . $filters;
        }

        $process = new Process([
            $this->ffmpegBinary, '-hide_banner', '-nostdin', '-y',
            // Avant -i : saut direct à la position, sans décoder le début.
            '-ss', (string) $at,
            '-i', $path,
            '-vf', $filters,
            '-frames:v', '1', '-q:v', '3',
            $output,
        ]);
        $process->setTimeout(120);
        $process->run();

        // Au-delà de la fin, ffmpeg échoue (« Nothing was written ») ou laisse un fichier vide selon les versions.
        clearstatcache(true, $output);
        if ($process->isSuccessful() && is_file($output) && filesize($output) > 0) {
            return null;
        }
        $lines = array_filter(array_map('trim', explode("\n", $process->getErrorOutput())));

        return end($lines) ?: 'aucune image produite';
    }
}
