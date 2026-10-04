<?php

namespace App\Service;

use App\Entity\CouncilSession;
use App\Entity\Video;
use App\Entity\VideoFile;
use App\Enum\VideoFileType;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Kit de diffusion d'un intervenant pour un conseil des ministres : une
 * archive des contenus de ce seul intervenant (Vidéo TV, Vidéo Mobile et
 * Audio MP3), rangée en dossiers « Video TV », « Video Mobile » et
 * « Audio MP3 », toutes langues confondues, pour ses télévisions, radios
 * et groupes WhatsApp partenaires.
 *
 * Le kit se transmet par un lien signé, que chacun peut envoyer (un cabinet
 * ministériel sans compte par exemple) : son destinataire ne peut ni le
 * deviner ni le modifier pour obtenir autre chose, et le télécharge
 * librement, sans compte (contrairement aux lots de l'espace média).
 */
class SpeakerKit
{
    /**
     * Parties d'un kit ; pour chaque contenu, les types sont essayés dans l'ordre.
     * Dossiers de l'archive sans accent : certains outils de décompression les abîment.
     */
    public const PARTS = [
        'tv' => ['label' => 'Vidéo TV', 'folder' => 'Video TV', 'icon' => 'fa-tv', 'types' => [VideoFileType::MP4_1080P, VideoFileType::MP4_480P]],
        'radio' => ['label' => 'Audio MP3', 'folder' => 'Audio MP3', 'icon' => 'fa-headphones', 'types' => [VideoFileType::AUDIO]],
        'mobile' => ['label' => 'Vidéo Mobile', 'folder' => 'Video Mobile', 'icon' => 'fa-mobile-screen-button', 'types' => [VideoFileType::MP4_VERTICAL]],
    ];

    public const FORMATS = [
        'kit' => ['label' => 'Kit de diffusion (vidéo TV, vidéo Mobile et audio MP3)', 'parts' => ['tv', 'radio', 'mobile']],
    ];

    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly UriSigner $uriSigner,
    ) {
    }

    /** Fichier d'un contenu pour une partie du kit (null si absent). */
    public function fileFor(Video $video, string $part): ?VideoFile
    {
        foreach (self::PARTS[$part]['types'] as $type) {
            $file = $video->getVideoFileByType($type);
            if ($file?->getFileName() !== null && !$file->isDefective()) {
                return $file;
            }
        }

        return null;
    }

    /**
     * Fichiers du kit, contenu par contenu.
     *
     * @param Video[] $videos contenus de l'intervenant pour ce conseil
     *
     * @return list<array{video: Video, files: array<string, VideoFile>}>
     */
    public function items(array $videos, string $format): array
    {
        $items = [];
        foreach ($videos as $video) {
            $files = [];
            foreach (self::FORMATS[$format]['parts'] as $part) {
                if (($file = $this->fileFor($video, $part)) !== null) {
                    $files[$part] = $file;
                }
            }
            if ($files !== []) {
                $items[] = ['video' => $video, 'files' => $files];
            }
        }

        return $items;
    }

    /**
     * @param Video[] $videos
     *
     * @return list<VideoFile>
     */
    public function files(array $videos, string $format): array
    {
        return array_merge(...array_map(static fn (array $item) => array_values($item['files']), $this->items($videos, $format)) ?: [[]]);
    }

    /**
     * Nombre de fichiers par partie (« Vidéo TV : 2 · Audio MP3 : 3 · Vidéo Mobile : 3 »).
     *
     * @param Video[] $videos
     *
     * @return array<string, int>
     */
    public function counts(array $videos, string $format): array
    {
        $counts = [];
        foreach ($this->items($videos, $format) as $item) {
            foreach (array_keys($item['files']) as $part) {
                $counts[$part] = ($counts[$part] ?? 0) + 1;
            }
        }

        return array_intersect_key(array_merge(array_fill_keys(self::FORMATS[$format]['parts'], 0), $counts), $counts);
    }

    /** Dossier d'un fichier dans l'archive du kit : Video TV, Audio MP3 ou Video Mobile. */
    public function folderFor(VideoFile $file): ?string
    {
        foreach (self::PARTS as $part) {
            if (\in_array($file->getType(), $part['types'], true)) {
                return $part['folder'];
            }
        }

        return null;
    }

    /**
     * Contenus regroupés par conseil, avec le décompte du kit de chacun.
     *
     * @param Video[] $videos triés par conseil (VideoRepository::findPublishedForSpeakers)
     *
     * @return list<array{council: CouncilSession, videos: list<Video>, kit: array<string, int>, mobile: int}>
     */
    public function groupByCouncil(array $videos): array
    {
        $groups = [];
        foreach ($videos as $video) {
            $council = $video->getSubject()->getCouncilSession();
            $groups[$council->getId()] ??= ['council' => $council, 'videos' => []];
            $groups[$council->getId()]['videos'][] = $video;
        }

        foreach ($groups as &$group) {
            $group['kit'] = $this->counts($group['videos'], 'kit');
            $group['mobile'] = $group['kit']['mobile'] ?? 0;
        }

        return array_values($groups);
    }

    /** Lien à transmettre : page du kit, signée. */
    public function shareUrl(string $person, CouncilSession $council, string $format = 'kit'): string
    {
        return $this->uriSigner->sign($this->urlGenerator->generate('app_kit_show', [
            'person' => $person, 'council' => $council->getSlug(), 'format' => $format,
        ], UrlGeneratorInterface::ABSOLUTE_URL));
    }

    /** Archive du kit, signée elle aussi. */
    public function zipUrl(string $person, CouncilSession $council, string $format = 'kit'): string
    {
        return $this->uriSigner->sign($this->urlGenerator->generate('app_kit_download', [
            'person' => $person, 'council' => $council->getSlug(), 'format' => $format,
        ], UrlGeneratorInterface::ABSOLUTE_URL));
    }
}
