<?php

namespace App\Service;

use App\Entity\Video;
use App\Enum\CapsuleFormat;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class VideoCoverUrlResolver
{
    public function __construct(
        #[Autowire(service: 'video_cover.storage')]
        private readonly FilesystemOperator $storage,
        private readonly AppSettings $settings,
    ) {
    }

    /** Couverture propre au contenu, seule à compter comme « couverture ». */
    public function resolve(Video $video): ?string
    {
        $fileName = $video->getCoverImageName();

        if ($fileName === null) {
            return null;
        }

        return $this->storage->publicUrl($fileName);
    }

    /**
     * Image à afficher sur le site public : la couverture du contenu, sinon
     * celle par défaut des Paramètres. Un contenu image garde son propre
     * visuel, plus parlant qu'une image générique. Le contenu reste « sans
     * couverture » pour tout le reste (hero, génération, tableau de bord).
     */
    public function resolveForDisplay(Video $video): ?string
    {
        $url = $this->resolve($video);
        if ($url !== null) {
            return $url;
        }

        if ($video->getPrimaryPlaybackFile()?->getType()->getCategory() === CapsuleFormat::IMAGE) {
            return null;
        }

        return $this->resolveDefault();
    }

    public function resolveDefault(): ?string
    {
        $default = $this->settings->getDefaultCover();

        return $default !== null ? $this->storage->publicUrl($default) : null;
    }
}
