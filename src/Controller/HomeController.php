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
    #[Route('/', name: 'app_home')]
    public function index(
        VideoRepository $videoRepository,
        ThematicRepository $thematicRepository,
        LanguageRepository $languageRepository,
    ): Response {
        $thematics = $thematicRepository->findAllWithVideoCount();
        $languages = $languageRepository->findAllWithVideoCount();
        $activeLanguages = array_filter($languages, static fn (array $row) => $row['videoCount'] > 0);

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
}
