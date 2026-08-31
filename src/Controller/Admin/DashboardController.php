<?php

namespace App\Controller\Admin;

use App\Repository\LanguageRepository;
use App\Repository\SpeakerRepository;
use App\Repository\SuggestionRepository;
use App\Repository\ThematicRepository;
use App\Repository\VideoRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
class DashboardController extends AbstractController
{
    #[Route('/admin', name: 'admin_dashboard')]
    public function index(
        VideoRepository $videoRepository,
        ThematicRepository $thematicRepository,
        LanguageRepository $languageRepository,
        SpeakerRepository $speakerRepository,
        SuggestionRepository $suggestionRepository,
    ): Response {
        return $this->render('admin/dashboard.html.twig', [
            'videoCount' => $videoRepository->count([]),
            'thematicCount' => $thematicRepository->count([]),
            'languageCount' => $languageRepository->count([]),
            'speakerCount' => $speakerRepository->count([]),
            'untreatedSuggestionCount' => $suggestionRepository->count(['treated' => false]),
        ]);
    }
}
