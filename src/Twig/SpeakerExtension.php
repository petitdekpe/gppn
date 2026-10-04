<?php

namespace App\Twig;

use App\Entity\Speaker;
use App\Repository\SpeakerRepository;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Sigle affiché d'un intervenant (cartes des blocs par sujet) : sans le
 * préfixe « MCC » des noms de fichiers. Un ministre conseiller qui partage
 * son sigle avec un ministre du même gouvernement (MFAS, MEEM, MS…) est
 * précisé : « MFAS (ministre conseillère) ».
 */
class SpeakerExtension extends AbstractExtension
{
    /** @var array<string, true>|null sigles « gouvernement|SIGLE » portés par un ministre */
    private ?array $ministerSigles = null;

    public function __construct(private readonly SpeakerRepository $speakerRepository)
    {
    }

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

        $sigle = Speaker::withoutCouncillorPrefix($sigle);
        if (!isset($this->ministerSigles()[$this->key($speaker, $sigle)])) {
            return $sigle;
        }

        return sprintf('%s (%s)', $sigle, str_contains($speaker->getRole() ?? '', 'Conseillère') ? 'ministre conseillère' : 'ministre conseiller');
    }

    /** @return array<string, true> */
    private function ministerSigles(): array
    {
        if ($this->ministerSigles === null) {
            $this->ministerSigles = [];
            foreach ($this->speakerRepository->findAll() as $speaker) {
                if ($speaker->getSigle() && !$speaker->isMinistreConseiller()) {
                    $this->ministerSigles[$this->key($speaker, $speaker->getSigle())] = true;
                }
            }
        }

        return $this->ministerSigles;
    }

    private function key(Speaker $speaker, string $sigle): string
    {
        return ($speaker->getGovernment()?->getId() ?? 0) . '|' . strtoupper($sigle);
    }
}
