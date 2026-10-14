<?php

namespace App\Twig;

use App\Entity\Speaker;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Sigle affiché d'un intervenant (cartes des blocs par sujet) : sans le
 * préfixe « MCC » des noms de fichiers. Un ministre conseiller est reconnu
 * au préfixe « MC » : « MC MFAS ».
 */
class SpeakerExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('speaker_sigle', $this->sigle(...)),
        ];
    }

    public function sigle(Speaker $speaker): ?string
    {
        $sigle = $speaker->getSigle();
        if (!$sigle) {
            return null;
        }
        if (!$speaker->isMinistreConseiller()) {
            return $sigle;
        }

        return 'MC ' . Speaker::withoutCouncillorPrefix($sigle);
    }
}
