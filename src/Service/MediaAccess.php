<?php

namespace App\Service;

use App\Entity\User;
use App\Entity\Video;
use App\Entity\VideoFile;
use App\Enum\CapsuleFormat;
use App\Enum\VideoFileType;
use App\EventSubscriber\LoginOtpSubscriber;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Règles de téléchargement :
 * - public : fichier par fichier ; sur téléphone la version Mobile seule,
 *   sur ordinateur aussi les versions TV et radio (masquées sur petit écran
 *   par la classe .download--desktop, voir app.css) ;
 * - presse et médias : téléchargements groupés (« Tout télécharger »,
 *   lots de l'espace média, kits des ministres), après connexion ou
 *   inscription dans l'espace presse, qui recueille e-mail et téléphone.
 *
 * Un média est un compte « Média » (inscrit depuis le site) ou « Lecteur
 * presse » et au-delà, connecté et ayant validé son code de vérification :
 * un mot de passe seul, OTP en attente, ne suffit pas.
 */
class MediaAccess
{
    private ?bool $isMedia = null;

    public function __construct(
        private readonly Security $security,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function isMedia(): bool
    {
        if ($this->isMedia !== null) {
            return $this->isMedia;
        }

        $session = $this->requestStack->getCurrentRequest()?->hasSession() ? $this->requestStack->getSession() : null;

        return $this->isMedia = $this->security->isGranted(User::ROLE_MEDIA)
            && $session?->get(LoginOtpSubscriber::PENDING_KEY) !== true;
    }

    /** Téléchargements groupés (archives ZIP, lots, kits) : presse et médias connectés. */
    public function canDownloadBundles(): bool
    {
        return $this->isMedia();
    }

    /** Fichier par fichier : ouvert à tous (le téléphone n'affiche que le Mobile). */
    public function canDownload(VideoFile|VideoFileType $file): bool
    {
        $type = $file instanceof VideoFile ? $file->getType() : $file;

        return $type->isPubliclyDownloadable();
    }

    /** Version proposée sur téléphone : la vidéo verticale seule. */
    public static function isPhoneFormat(VideoFile|VideoFileType $file): bool
    {
        return ($file instanceof VideoFile ? $file->getType() : $file) === VideoFileType::MP4_VERTICAL;
    }

    /**
     * Fichiers téléchargeables, Mobile en tête (format le plus demandé).
     *
     * @return list<VideoFile>
     */
    public function downloadableFiles(Video $video): array
    {
        $files = array_values(array_filter(
            iterator_to_array($video->getFiles()),
            fn (VideoFile $file) => $file->getFileName() !== null && !$file->isDefective() && $this->canDownload($file),
        ));
        usort($files, static fn (VideoFile $a, VideoFile $b) => self::isPhoneFormat($b) <=> self::isPhoneFormat($a));

        return $files;
    }

    /** Fichier du bouton « Télécharger » principal (accueil, « À la une ») : Mobile si possible. */
    public function preferredDownload(Video $video): ?VideoFile
    {
        return $this->downloadableFiles($video)[0] ?? null;
    }

    /**
     * Formats proposés aux filtres de recherche : sans « Audio » (radio) pour le public.
     *
     * @param CapsuleFormat[] $formats
     *
     * @return CapsuleFormat[]
     */
    public function filterFormats(array $formats): array
    {
        return $this->isMedia() ? array_values($formats) : array_values(array_filter($formats, static fn (CapsuleFormat $format) => $format !== CapsuleFormat::AUDIO));
    }
}
