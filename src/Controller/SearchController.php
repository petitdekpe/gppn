<?php

namespace App\Controller;

use App\Repository\LanguageRepository;
use App\Repository\ThematicRepository;
use App\Repository\VideoRepository;
use App\Service\SpeakerPeriodCriteria;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\String\UnicodeString;

/**
 * Suggestions de la barre de recherche, à mesure que l'on tape : les
 * intervenants d'abord (raccourci vers leur page), puis les contenus, les
 * thématiques et les langues (header_search_controller.js).
 */
class SearchController extends AbstractController
{
    #[Route('/recherche/suggestions', name: 'app_search_suggest', methods: ['GET'])]
    public function suggest(Request $request, SpeakerPeriodCriteria $criteria, VideoRepository $videoRepository, ThematicRepository $thematicRepository, LanguageRepository $languageRepository): JsonResponse
    {
        $text = trim($request->query->getString('q'));
        if (mb_strlen($text) < 2) {
            return new JsonResponse(['people' => [], 'contents' => [], 'thematics' => [], 'languages' => []]);
        }

        $key = self::normalize($text);
        $matching = static fn (string $name) => str_contains(self::normalize($name), $key);

        return new JsonResponse([
            'people' => array_map(fn (array $person) => [
                'label' => $person['name'],
                'detail' => $person['role'],
                'count' => $person['videoCount'],
                'url' => $this->generateUrl('app_intervenant_show', ['person' => $person['slug']]),
            ], $criteria->searchPeople($text, 4)),
            'contents' => array_map(fn ($video) => [
                'label' => $video->getTitle(),
                'detail' => $video->getLanguage()->getName(),
                'url' => $this->generateUrl('app_video_show', ['slug' => $video->getSlug()]),
            ], $videoRepository->suggest($text, 5)),
            'thematics' => array_values(array_map(fn (array $row) => [
                'label' => $row['thematic']->getName(),
                'url' => $this->generateUrl('app_thematique_show', ['slug' => $row['thematic']->getSlug()]),
            ], array_slice(array_filter($thematicRepository->findAllWithVideoCount(), static fn (array $row) => $row['videoCount'] > 0 && $matching($row['thematic']->getName())), 0, 3))),
            'languages' => array_values(array_map(fn (array $row) => [
                'label' => $row['language']->getName(),
                'url' => $this->generateUrl('app_langue_show', ['slug' => $row['language']->getSlug()]),
            ], array_slice(array_filter($languageRepository->findAllWithVideoCount(), static fn (array $row) => $row['videoCount'] > 0 && $matching($row['language']->getName())), 0, 3))),
            'all' => $this->generateUrl('app_video_index', ['q' => $text]),
        ]);
    }

    /** Sans accents ni casse : « Wémègbé » se trouve en tapant « wemegbe ». */
    private static function normalize(string $text): string
    {
        return strtolower((new UnicodeString($text))->ascii()->toString());
    }
}
