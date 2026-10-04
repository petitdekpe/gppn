<?php

namespace App\Twig;

use App\Service\VideoCoverUrlResolver;
use App\Service\VideoDownloadUrlResolver;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class VideoExtension extends AbstractExtension
{
    public function __construct(
        private readonly VideoDownloadUrlResolver $downloadUrlResolver,
        private readonly VideoCoverUrlResolver $coverUrlResolver,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('video_file_url', $this->downloadUrlResolver->resolve(...)),
            new TwigFunction('video_webm_url', $this->downloadUrlResolver->resolveWebm(...)),
            // Gabarits publics : couverture par défaut incluse.
            new TwigFunction('video_cover_url', $this->coverUrlResolver->resolveForDisplay(...)),
            // Cartes : vignette WebP de cette même image, null tant qu'elle n'existe pas.
            new TwigFunction('video_cover_thumb_url', $this->coverUrlResolver->resolveThumbForDisplay(...)),
            new TwigFunction('default_cover_url', $this->coverUrlResolver->resolveDefault(...)),
        ];
    }
}
