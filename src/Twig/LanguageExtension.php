<?php

namespace App\Twig;

use App\Entity\Language;
use App\Repository\LanguageRepository;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Langues proposées sur les pages d'erreur, qui n'ont pas de contrôleur
 * pour les leur fournir.
 */
class LanguageExtension extends AbstractExtension
{
    public function __construct(private readonly LanguageRepository $languageRepository)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('popular_languages', $this->popularLanguages(...)),
        ];
    }

    /**
     * Langues qui ont des contenus publiés, les plus fournies d'abord (comme l'accueil).
     *
     * @return Language[]
     */
    public function popularLanguages(int $limit = 8): array
    {
        try {
            $rows = $this->languageRepository->findAllWithVideoCount();
        } catch (\Throwable) {
            // Page d'erreur 500 : la base peut être la cause, la page doit s'afficher quand même.
            return [];
        }

        $rows = array_filter($rows, static fn (array $row) => $row['videoCount'] > 0);
        usort($rows, static fn (array $a, array $b) => $b['videoCount'] <=> $a['videoCount']);

        return array_map(static fn (array $row) => $row['language'], array_slice($rows, 0, $limit));
    }
}
