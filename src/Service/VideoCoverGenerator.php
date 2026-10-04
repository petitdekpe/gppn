<?php

namespace App\Service;

use App\Entity\Video;
use App\Entity\VideoFile;
use App\Enum\VideoFileType;
use App\Exception\CoverGenerationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Process\Process;

/**
 * Tire l'image de couverture d'un contenu d'une de ses vidéos, avec ffmpeg :
 * la vidéo TV (1080p, à défaut 480p), qui a le cadrage horizontal des
 * cartes ; sans vidéo TV, la vidéo Mobile (verticale), recadrée en 16:9 sur
 * sa partie haute, où se trouvent les visages, puis mise à l'échelle.
 *
 * - automatique (après l'envoi d'une vidéo, ou commande de rattrapage) :
 *   à 15 s, parmi les images des 2 secondes suivantes, la plus
 *   représentative (évite une image noire ou un fondu) ; jamais à la place
 *   d'une couverture déposée à la main ;
 * - à la demande (bouton de l'administration) : l'image exacte de la
 *   seconde choisie, qui remplace la couverture quelle qu'elle soit ;
 * - depuis la vidéo Mobile même s'il y a une vidéo TV (commande
 *   app:video:generate-covers --mobile).
 */
class VideoCoverGenerator
{
    public const DEFAULT_SECOND = 15;

    /** Sources possibles, par ordre de préférence. */
    private const SOURCES = [VideoFileType::MP4_1080P, VideoFileType::MP4_480P, VideoFileType::MP4_VERTICAL];

    /**
     * Haut de la fenêtre 16:9 découpée dans une vidéo verticale, en part de
     * sa hauteur : sous le bandeau du haut, à hauteur des visages.
     */
    private const VERTICAL_CROP_TOP = 0.16;

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
        return $this->source($video) !== null;
    }

    /**
     * Vidéo qui servirait de source : TV si possible, sinon Mobile. Statique
     * pour VideoCoverSubscriber (écouteur Doctrine, qui ne peut pas dépendre
     * de ce service sans boucle de dépendances).
     */
    public static function source(Video $video): ?VideoFile
    {
        foreach (self::SOURCES as $type) {
            $file = $video->getVideoFileByType($type);
            if ($file?->getFileName() !== null) {
                return $file;
            }
        }

        return null;
    }

    /** Vidéo Mobile déposée, ou null. */
    public static function mobileSource(Video $video): ?VideoFile
    {
        $file = $video->getVideoFileByType(VideoFileType::MP4_VERTICAL);

        return $file?->getFileName() !== null ? $file : null;
    }

    /** « vidéo TV » ou « vidéo Mobile », pour les messages et l'administration. */
    public function sourceLabel(Video $video): ?string
    {
        return self::label(self::source($video));
    }

    private static function label(?VideoFile $source): ?string
    {
        return $source === null ? null : ($source->getType() === VideoFileType::MP4_VERTICAL ? 'vidéo Mobile' : 'vidéo TV');
    }

    /**
     * @param int|null $second     seconde choisie par l'éditeur ; null = automatique
     * @param bool     $fromMobile vidéo Mobile même s'il y a une vidéo TV
     *
     * @return int seconde réellement utilisée
     */
    public function generate(Video $video, ?int $second = null, bool $fromMobile = false): int
    {
        $source = $fromMobile ? self::mobileSource($video) : self::source($video);
        if ($source === null) {
            throw new CoverGenerationException($fromMobile
                ? 'Ce contenu n’a pas de vidéo Mobile : la couverture ne peut pas en être tirée.'
                : 'Ce contenu n’a ni vidéo TV ni vidéo Mobile : la couverture ne peut pas en être tirée.');
        }
        $label = self::label($source);
        $vertical = $source->getType() === VideoFileType::MP4_VERTICAL;

        $duration = $video->getDurationSeconds();
        if ($second !== null && $duration > 0 && $second >= $duration) {
            throw new CoverGenerationException(sprintf('La %s dure %d s : choisissez une seconde entre 0 et %d.', $label, $duration, $duration - 1));
        }
        // Vidéo plus courte que 15 s : on vise le milieu.
        $at = $second ?? ($duration > 0 && $duration <= self::DEFAULT_SECOND ? intdiv($duration, 2) : self::DEFAULT_SECOND);

        $path = $this->downloadsDir . '/' . $source->getFileName();
        if (!is_file($path)) {
            throw new CoverGenerationException(sprintf('Le fichier de la %s est introuvable sur le serveur (%s).', $label, $source->getFileName()));
        }

        $output = sys_get_temp_dir() . '/cover_' . bin2hex(random_bytes(6)) . '.jpg';
        try {
            $error = $this->extract($path, $at, $second === null, $vertical, $output);
            // Durée inconnue et vidéo plus courte que 15 s : rien à cette
            // position, on se rabat sur la première seconde.
            if ($error !== null && $second === null && $at > 1) {
                $at = 1;
                $error = $this->extract($path, $at, true, $vertical, $output);
            }
            if ($error !== null) {
                throw new CoverGenerationException(sprintf('Aucune image à %d s : la %s est probablement plus courte, ou illisible. Détail ffmpeg : %s', $at, $label, $error));
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
    private function extract(string $path, int $at, bool $pickBest, bool $vertical, string $output): ?string
    {
        $filters = $vertical
            // Fenêtre 16:9 sur toute la largeur, en haut de l'image (sans
            // déborder du bas), agrandie à la largeur des cartes.
            ? sprintf('crop=iw:iw*9/16:0:min(ih*%s\,ih-iw*9/16),scale=1280:-2:flags=lanczos', self::VERTICAL_CROP_TOP)
            // Largeur des cartes et de la fiche détail, sans agrandir une vidéo plus petite.
            : 'scale=min(1280\,iw):-2';
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
