<?php

namespace App\Controller;

use App\Entity\VideoFeedback;
use App\Enum\CapsuleFormat;
use App\Repository\LanguageRepository;
use App\Repository\ThematicRepository;
use App\Repository\VideoRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class VideoController extends AbstractController
{
    #[Route('/videos', name: 'app_video_index')]
    public function index(
        Request $request,
        VideoRepository $videoRepository,
        ThematicRepository $thematicRepository,
        LanguageRepository $languageRepository,
    ): Response {
        $queryParams = $request->query->all();
        $thematicSlugs = isset($queryParams['thematique']) ? array_values((array) $queryParams['thematique']) : [];
        $languageSlugs = isset($queryParams['langue']) ? array_values((array) $queryParams['langue']) : [];
        $formatValues = isset($queryParams['format']) ? array_values((array) $queryParams['format']) : [];

        $selectedThematics = $thematicSlugs ? $thematicRepository->findBy(['slug' => $thematicSlugs]) : [];
        $selectedLanguages = $languageSlugs ? $languageRepository->findBy(['slug' => $languageSlugs]) : [];
        $selectedFormats = array_filter(array_map(
            static fn (mixed $value): ?CapsuleFormat => CapsuleFormat::tryFrom((string) $value),
            $formatValues,
        ));

        $query = $request->query->getString('q') ?: null;
        $page = max(1, $request->query->getInt('page', 1));

        $results = $videoRepository->search($selectedThematics, $selectedLanguages, $selectedFormats, $query, $page);

        return $this->render('video/index.html.twig', [
            'results' => $results,
            'thematics' => $thematicRepository->findAllWithVideoCount(),
            'languages' => $languageRepository->findAllWithVideoCount(),
            'formats' => CapsuleFormat::cases(),
            'selectedThematicSlugs' => $thematicSlugs,
            'selectedLanguageSlugs' => $languageSlugs,
            'selectedFormatValues' => array_map(static fn (CapsuleFormat $format) => $format->value, $selectedFormats),
            'query' => $query,
            'routeParams' => array_filter([
                'thematique' => $thematicSlugs,
                'langue' => $languageSlugs,
                'format' => $formatValues,
                'q' => $query,
            ]),
            'featuredVideos' => $videoRepository->findFeatured(3),
        ]);
    }

    #[Route('/videos/{slug}', name: 'app_video_show')]
    public function show(string $slug, VideoRepository $videoRepository): Response
    {
        $video = $videoRepository->findOneBySlug($slug) ?? throw $this->createNotFoundException('Vidéo introuvable.');

        return $this->render('video/show.html.twig', [
            'video' => $video,
            'relatedVideos' => $videoRepository->findRelated($video, 8),
        ]);
    }

    #[Route('/videos/{slug}/avis', name: 'app_video_feedback', methods: ['POST'])]
    public function feedback(string $slug, Request $request, VideoRepository $videoRepository, EntityManagerInterface $entityManager): Response
    {
        $video = $videoRepository->findOneBySlug($slug) ?? throw $this->createNotFoundException('Vidéo introuvable.');

        if (!$this->isCsrfTokenValid('video_feedback_' . $video->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $rating = (string) $request->request->get('rating');
        if (!in_array($rating, VideoFeedback::RATINGS, true)) {
            $this->addFlash('error', 'Merci de choisir une réponse avant d’envoyer votre avis.');

            return $this->redirectToRoute('app_video_show', ['slug' => $slug]);
        }

        $feedback = (new VideoFeedback())
            ->setVideo($video)
            ->setRating($rating)
            ->setComment($request->request->getString('comment') ?: null);

        $entityManager->persist($feedback);
        $entityManager->flush();

        $this->addFlash('success', 'Merci pour votre retour, il nous aide à améliorer cette capsule.');

        return $this->redirectToRoute('app_video_show', ['slug' => $slug]);
    }
}
