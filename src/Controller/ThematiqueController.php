<?php

namespace App\Controller;

use App\Enum\CapsuleFormat;
use App\Repository\LanguageRepository;
use App\Repository\ThematicRepository;
use App\Repository\VideoRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class ThematiqueController extends AbstractController
{
    #[Route('/thematiques', name: 'app_thematique_index')]
    public function index(ThematicRepository $thematicRepository): Response
    {
        return $this->render('thematique/index.html.twig', [
            'thematics' => $thematicRepository->findAllWithVideoCount(),
        ]);
    }

    #[Route('/thematiques/{slug}', name: 'app_thematique_show')]
    public function show(
        string $slug,
        Request $request,
        ThematicRepository $thematicRepository,
        LanguageRepository $languageRepository,
        VideoRepository $videoRepository,
    ): Response {
        $thematic = $thematicRepository->findOneBy(['slug' => $slug]) ?? throw $this->createNotFoundException('Thématique introuvable.');

        $queryParams = $request->query->all();
        $languageSlugs = isset($queryParams['langue']) ? array_values((array) $queryParams['langue']) : [];
        $formatValues = isset($queryParams['format']) ? array_values((array) $queryParams['format']) : [];
        $selectedLanguages = $languageSlugs ? $languageRepository->findBy(['slug' => $languageSlugs]) : [];
        $selectedFormats = array_filter(array_map(
            static fn (mixed $value): ?CapsuleFormat => CapsuleFormat::tryFrom((string) $value),
            $formatValues,
        ));

        $query = $request->query->getString('q') ?: null;
        $page = max(1, $request->query->getInt('page', 1));

        $results = $videoRepository->search([$thematic], $selectedLanguages, $selectedFormats, $query, $page);

        return $this->render('thematique/show.html.twig', [
            'thematic' => $thematic,
            'results' => $results,
            'languages' => $languageRepository->findAllWithVideoCount(),
            'formats' => CapsuleFormat::cases(),
            'selectedLanguageSlugs' => $languageSlugs,
            'selectedFormatValues' => array_map(static fn (CapsuleFormat $format) => $format->value, $selectedFormats),
            'query' => $query,
            'routeParams' => array_filter([
                'langue' => $languageSlugs,
                'format' => $formatValues,
                'q' => $query,
            ]),
        ]);
    }
}
