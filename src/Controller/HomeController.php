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
    #[Route('/accueil2', name: 'app_home')]
    public function index(
        VideoRepository $videoRepository,
        ThematicRepository $thematicRepository,
        LanguageRepository $languageRepository,
    ): Response {
        $thematics = $thematicRepository->findAllWithVideoCount();
        $languages = $languageRepository->findAllWithVideoCount();
        $activeLanguages = array_filter($languages, static fn(array $row) => $row['videoCount'] > 0);

        return $this->render('home/index.html.twig', [
            'latestVideos' => $videoRepository->findLatest(8),
            'featuredVideos' => $videoRepository->findFeatured(3),
            'thematics' => $thematics,
            'languages' => $languages,
            'stats' => [
                'videos' => $videoRepository->countAll(),
                'languages' => count($activeLanguages),
                'thematics' => count($thematics),
                'views' => $videoRepository->sumViews(),
            ],
        ]);
    }

    #[Route('/', name: 'app_home2')]
    public function index2(
        VideoRepository $videoRepository,
        ThematicRepository $thematicRepository,
        LanguageRepository $languageRepository,
    ): Response {
        $thematics = $thematicRepository->findAllWithVideoCount();
        $languages = $languageRepository->findAllWithVideoCount();

        $activeLanguages = array_values(array_filter($languages, static fn(array $row) => $row['videoCount'] > 0));
        $activeThematics = array_values(array_filter($thematics, static fn(array $row) => $row['videoCount'] > 0));

        $featuredVideos = $videoRepository->findFeatured(2);
        if (\count($featuredVideos) < 2) {
            foreach ($videoRepository->findLatest(4) as $video) {
                if (\count($featuredVideos) >= 2) {
                    break;
                }
                if (!\in_array($video, $featuredVideos, true)) {
                    $featuredVideos[] = $video;
                }
            }
        }

        return $this->render('home/index2.html.twig', [
            'heroVideo' => $featuredVideos[0] ?? null,
            'spotlightVideo' => $featuredVideos[1] ?? ($featuredVideos[0] ?? null),
            'languages' => \array_slice($activeLanguages ?: $languages, 0, 4),
            'thematics' => \array_slice($activeThematics ?: $thematics, 0, 4),
        ]);
    }
}
