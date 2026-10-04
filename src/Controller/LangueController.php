<?php

namespace App\Controller;

use App\Repository\CouncilSessionRepository;
use App\Repository\LanguageRepository;
use App\Repository\ThematicRepository;
use App\Repository\VideoRepository;
use App\Service\AppSettings;
use App\Service\ContentFilters;
use App\Service\MediaAccess;
use App\Service\SpeakerPeriodCriteria;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class LangueController extends AbstractController
{
    /**
     * Langues renommées : l'ancienne adresse redirige vers la nouvelle pour
     * ne pas casser les liens déjà partagés.
     */
    private const RENAMED_SLUGS = [
        'idatcha' => 'idaasha',
    ];

    /** Toutes les langues qui ont des contenus publiés, par ordre alphabétique (« Voir plus de langues » de l'accueil). */
    #[Route('/langues', name: 'app_langue_index')]
    public function index(LanguageRepository $languageRepository): Response
    {
        return $this->render('langue/index.html.twig', [
            'languages' => array_values(array_filter($languageRepository->findAllWithVideoCount(), static fn (array $row) => $row['videoCount'] > 0)),
        ]);
    }

    /**
     * Comme la page Contenus (barre de filtres en haut, un bloc par sujet), langue imposée :
     * conseil des ministres, puis intervenants, thématique, intervenant,
     * période et format sous « Plus de filtres ».
     */
    #[Route('/langues/{slug}', name: 'app_langue_show')]
    public function show(
        string $slug,
        Request $request,
        LanguageRepository $languageRepository,
        ThematicRepository $thematicRepository,
        CouncilSessionRepository $councilSessionRepository,
        VideoRepository $videoRepository,
        AppSettings $settings,
        MediaAccess $mediaAccess,
        SpeakerPeriodCriteria $speakerPeriodCriteria,
        ContentFilters $contentFilters,
    ): Response {
        if (isset(self::RENAMED_SLUGS[$slug])) {
            return $this->redirectToRoute('app_langue_show', ['slug' => self::RENAMED_SLUGS[$slug]] + $request->query->all(), Response::HTTP_MOVED_PERMANENTLY);
        }

        $language = $languageRepository->findOneBy(['slug' => $slug]) ?? throw $this->createNotFoundException('Langue introuvable.');

        $filters = $contentFilters->read($request, 'app_langue_show', ['slug' => $language->getSlug()], $language);

        $results = $videoRepository->searchBySubject($filters['thematics'], $filters['languages'], $filters['formats'], $filters['query'], $filters['page'], speakerRole: $filters['role'], councilSessions: $filters['councilSessions'], speakerPeriod: $filters['speakerPeriod'], querySpeakerIds: $filters['querySpeakerIds']);

        return $this->render('langue/show.html.twig', [
            'language' => $language,
            'results' => $results,
            'thematics' => $thematicRepository->findAllWithVideoCount(),
            'formats' => $mediaAccess->filterFormats($settings->getEnabledFormats()),
            'councilSessions' => $councilSessionRepository->findWithVideoCountForLanguage($language),
            'selectedThematicSlugs' => $filters['selectedThematicSlugs'],
            'selectedFormatValues' => $filters['selectedFormatValues'],
            'selectedCouncilSessionSlugs' => $filters['selectedCouncilSessionSlugs'],
            'query' => $filters['query'],
            'selectedRole' => $filters['role'],
            'people' => $speakerPeriodCriteria->people(),
            'periodShortcuts' => $speakerPeriodCriteria->periodShortcuts(),
            'speakerPeriod' => $filters['speakerPeriod'],
            'routeParams' => $filters['routeParams'],
            'activeFilters' => $filters['activeFilters'],
            'resetUrl' => $filters['resetUrl'],
        ]);
    }
}
