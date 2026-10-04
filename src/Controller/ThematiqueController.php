<?php

namespace App\Controller;

use App\Enum\CapsuleFormat;
use App\Repository\CouncilSessionRepository;
use App\Repository\LanguageRepository;
use App\Repository\ThematicRepository;
use App\Repository\VideoRepository;
use App\Service\AppSettings;
use App\Service\MediaAccess;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class ThematiqueController extends AbstractController
{
    #[Route('/thematiques', name: 'app_thematique_index')]
    public function index(ThematicRepository $thematicRepository): Response
    {
        // Thématiques sans aucun contenu publié : pas de tuile vide.
        return $this->render('thematique/index.html.twig', [
            'thematics' => array_values(array_filter($thematicRepository->findAllWithVideoCount(), static fn (array $row) => $row['videoCount'] > 0)),
        ]);
    }

    #[Route('/thematiques/{slug}', name: 'app_thematique_show')]
    public function show(
        string $slug,
        Request $request,
        ThematicRepository $thematicRepository,
        LanguageRepository $languageRepository,
        VideoRepository $videoRepository,
        CouncilSessionRepository $councilSessionRepository,
        AppSettings $settings,
        MediaAccess $mediaAccess,
    ): Response {
        $thematic =$thematicRepository->findOneBy(['slug' => $slug]) ?? throw $this->createNotFoundException('Thématique introuvable.');

        $queryParams = $request->query->all();
        $languageSlugs = isset($queryParams['langue']) ? array_values((array) $queryParams['langue']) : [];
        $formatValues = isset($queryParams['format']) ? array_values((array) $queryParams['format']) : [];
        $selectedLanguages = $languageSlugs ? $languageRepository->findBy(['slug' => $languageSlugs]) : [];
        // Recherche par format « Audio MP3 » réservée aux médias.
        $selectedFormats = $mediaAccess->filterFormats(array_filter(array_map(
            static fn (mixed $value): ?CapsuleFormat => CapsuleFormat::tryFrom((string) $value),
            $formatValues,
        )));

        $query = $request->query->getString('q') ?: null;
        $page = max(1, $request->query->getInt('page', 1));

        // Filtre « Conseil des ministres » : tous, ou un seul parmi ceux qui
        // ont des contenus dans cette thématique.
        $councilSessions = $councilSessionRepository->findWithVideoCountForThematic($thematic);
        $councilSlug = $request->query->getString('conseil');
        $selectedCouncil = null;
        foreach ($councilSessions as $row) {
            if ($row['councilSession']->getSlug() === $councilSlug) {
                $selectedCouncil = $row['councilSession'];
            }
        }

        $results = $videoRepository->search([$thematic], $selectedLanguages, $selectedFormats, $query, $page, councilSessions: $selectedCouncil ? [$selectedCouncil] : []);

        return $this->render('thematique/show.html.twig', [
            'thematic' => $thematic,
            'results' => $results,
            'languages' => $languageRepository->findAllWithVideoCount(),
            'formats' => $mediaAccess->filterFormats($settings->getEnabledFormats()),
            'selectedLanguageSlugs' => $languageSlugs,
            'selectedFormatValues' => array_map(static fn (CapsuleFormat $format) => $format->value, $selectedFormats),
            'query' => $query,
            'councilOptions' => $councilSessions,
            'selectedCouncil' => $selectedCouncil,
            'routeParams' => array_filter([
                'langue' => $languageSlugs,
                'format' => $formatValues,
                'q' => $query,
                'conseil' => $selectedCouncil?->getSlug(),
            ]),
        ]);
    }
}
