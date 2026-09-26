<?php

namespace App\Controller\Admin;

use App\Repository\LanguageRepository;
use App\Repository\SpeakerRepository;
use App\Repository\SuggestionRepository;
use App\Repository\ThematicRepository;
use App\Service\DashboardStats;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
class DashboardController extends AbstractController
{
    #[Route('/admin', name: 'admin_dashboard')]
    public function index(
        DashboardStats $stats,
        ThematicRepository $thematicRepository,
        LanguageRepository $languageRepository,
        SpeakerRepository $speakerRepository,
        SuggestionRepository $suggestionRepository,
    ): Response {
        $videosByStatus = $stats->videosByStatus();

        return $this->render('admin/dashboard.html.twig', [
            'videoCount' => array_sum($videosByStatus),
            'videosByStatus' => $videosByStatus,
            'views' => $stats->views(),
            'thematicCount' => $thematicRepository->count([]),
            'languageCount' => $languageRepository->count([]),
            'speakerCount' => $speakerRepository->count([]),
            'untreatedSuggestionCount' => $suggestionRepository->count(['treated' => false]),
            'topVideos' => $stats->topVideos(),
            'byLanguage' => $stats->byLanguage(),
            'byThematic' => $stats->byThematic(),
            'publishedPerMonth' => $stats->publishedPerMonth(),
            'feedback' => $stats->feedback(),
            'latestComments' => $stats->latestComments(),
            'fileCoverage' => $stats->fileCoverage(),
            'storage' => $stats->storage(),
            'webm' => $stats->webmConversions(),
        ]);
    }
}
