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

class LangueController extends AbstractController
{
    #[Route('/langues/{slug}', name: 'app_langue_show')]
    public function show(
        string $slug,
        Request $request,
        LanguageRepository $languageRepository,
        ThematicRepository $thematicRepository,
        VideoRepository $videoRepository,
    ): Response {
        $language = $languageRepository->findOneBy(['slug' => $slug]) ?? throw $this->createNotFoundException('Langue introuvable.');

        $queryParams = $request->query->all();
        $thematicSlugs = isset($queryParams['thematique']) ? array_values((array) $queryParams['thematique']) : [];
        $formatValues = isset($queryParams['format']) ? array_values((array) $queryParams['format']) : [];
        $selectedThematics = $thematicSlugs ? $thematicRepository->findBy(['slug' => $thematicSlugs]) : [];
        $selectedFormats = array_filter(array_map(
            static fn (mixed $value): ?CapsuleFormat => CapsuleFormat::tryFrom((string) $value),
            $formatValues,
        ));

        $query = $request->query->getString('q') ?: null;
        $page = max(1, $request->query->getInt('page', 1));

        $results = $videoRepository->search($selectedThematics, [$language], $selectedFormats, $query, $page);

        return $this->render('langue/show.html.twig', [
            'language' => $language,
            'results' => $results,
            'thematics' => $thematicRepository->findAllWithVideoCount(),
            'formats' => CapsuleFormat::cases(),
            'selectedThematicSlugs' => $thematicSlugs,
            'selectedFormatValues' => array_map(static fn (CapsuleFormat $format) => $format->value, $selectedFormats),
            'query' => $query,
            'routeParams' => array_filter([
                'thematique' => $thematicSlugs,
                'format' => $formatValues,
                'q' => $query,
            ]),
        ]);
    }
}
