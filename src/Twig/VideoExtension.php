<?php

namespace App\Twig;

use App\Service\VideoDownloadUrlResolver;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class VideoExtension extends AbstractExtension
{
    public function __construct(
        private readonly VideoDownloadUrlResolver $downloadUrlResolver,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('video_file_url', $this->downloadUrlResolver->resolve(...)),
        ];
    }
}
