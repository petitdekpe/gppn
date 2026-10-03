<?php

namespace App\Controller;

use App\Repository\LanguageRepository;
use App\Repository\ThematicRepository;
use App\Repository\VideoRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class HomeController extends AbstractController
{
    /**
     * Ancienne adresse de l'accueil (page mise en brouillon, voir
     * templates/home/brouillons/accueil2.html.twig) : redirigée pour ne pas
     * casser les liens déjà partagés.
     */
    #[Route('/accueil2', name: 'app_home_legacy')]
    public function legacy(): Response
    {
        return $this->redirectToRoute('app_home', [], Response::HTTP_MOVED_PERMANENTLY);
    }

    #[Route('/', name: 'app_home')]
    public function index(
        VideoRepository $videoRepository,
        ThematicRepository $thematicRepository,
        LanguageRepository $languageRepository,
    ): Response {
        $thematics = $thematicRepository->findAllWithVideoCount();
        $languages = $languageRepository->findAllWithVideoCount();

        // Les plus fournies d'abord (à égalité, ordre alphabétique conservé par usort stable).
        $byVideoCount = static fn(array $a, array $b) => $b['videoCount'] <=> $a['videoCount'];
        $activeLanguages = array_values(array_filter($languages, static fn(array $row) => $row['videoCount'] > 0));
        $activeThematics = array_values(array_filter($thematics, static fn(array $row) => $row['videoCount'] > 0));
        usort($activeLanguages, $byVideoCount);
        usort($activeThematics, $byVideoCount);
        $shownThematics = $activeThematics ?: $thematics;

        $heroVideo = $videoRepository->findHeroVideo();

        // « À la une » : un autre contenu que le hero, les mis en avant d'abord.
        $spotlightVideo = null;
        foreach ([...$videoRepository->findFeatured(2), ...$videoRepository->findLatest(2)] as $video) {
            if ($video !== $heroVideo) {
                $spotlightVideo = $video;
                break;
            }
        }

        // « Derniers contenus publiés » : ceux du dernier conseil des ministres seulement.
        $latestCouncilSession = $videoRepository->findLatestCouncilSession();

        return $this->render('home/index.html.twig', [
            'heroVideo' => $heroVideo,
            'spotlightVideo' => $spotlightVideo ?? $heroVideo,
            'latestCouncilSession' => $latestCouncilSession,
            'latestVideos' => $latestCouncilSession ? $videoRepository->findLatestForCouncilSession($latestCouncilSession, 6) : [],
            // Toutes : le gabarit en montre 8, les autres derrière « Voir plus ».
            'languages' => $activeLanguages ?: $languages,
            'thematics' => \array_slice($shownThematics, 0, 6),
            'hasMoreThematics' => \count($shownThematics) > 6,
        ]);
    }
}
