<?php

namespace App\Twig;

use App\Service\MediaAccess;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Règles de téléchargement (voir MediaAccess) dans les gabarits.
 */
class MediaExtension extends AbstractExtension
{
    public function __construct(private readonly MediaAccess $mediaAccess)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('is_media', $this->mediaAccess->isMedia(...)),
            new TwigFunction('can_download_bundles', $this->mediaAccess->canDownloadBundles(...)),
            new TwigFunction('downloadable_files', $this->mediaAccess->downloadableFiles(...)),
            new TwigFunction('preferred_download', $this->mediaAccess->preferredDownload(...)),
            // Classe à poser sur un bouton de téléchargement : masqué sur téléphone sauf version Mobile.
            new TwigFunction('download_class', static fn ($file) => MediaAccess::isPhoneFormat($file) ? 'download--phone' : 'download--desktop'),
        ];
    }
}
