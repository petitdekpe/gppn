<?php

namespace App\Twig;

use App\Service\SpeakerKit;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Kits de diffusion des pages intervenant (voir SpeakerKit).
 */
class KitExtension extends AbstractExtension
{
    public function __construct(private readonly SpeakerKit $kit)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('kit_share_url', $this->kit->shareUrl(...)),
            new TwigFunction('kit_zip_url', $this->kit->zipUrl(...)),
            new TwigFunction('kit_file', $this->kit->fileFor(...)),
        ];
    }
}
