<?php

namespace App\Controller;

use App\Entity\VideoFeedback;
use App\Enum\CapsuleFormat;
use App\Repository\LanguageRepository;
use App\Repository\ThematicRepository;
use App\Repository\VideoRepository;
use App\Service\VideoFileZipBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
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

        $selectedRole = $request->query->getString('role') ?: 'tous';
        if (!in_array($selectedRole, ['tous', 'ministre', 'conseiller'], true)) {
            $selectedRole = 'tous';
        }

        $results = $videoRepository->search($selectedThematics, $selectedLanguages, $selectedFormats, $query, $page, speakerRole: $selectedRole);

        return $this->render('video/index.html.twig', [
            'results' => $results,
            'thematics' => $thematicRepository->findAllWithVideoCount(),
            'languages' => $languageRepository->findAllWithVideoCount(),
            'formats' => CapsuleFormat::cases(),
            'selectedThematicSlugs' => $thematicSlugs,
            'selectedLanguageSlugs' => $languageSlugs,
            'selectedFormatValues' => array_map(static fn (CapsuleFormat $format) => $format->value, $selectedFormats),
            'query' => $query,
            'selectedRole' => $selectedRole,
            'routeParams' => array_filter([
                'thematique' => $thematicSlugs,
                'langue' => $languageSlugs,
                'format' => $formatValues,
                'q' => $query,
                'role' => $selectedRole !== 'tous' ? $selectedRole : null,
            ]),
            'featuredVideos' => $videoRepository->findFeatured(3),
        ]);
    }

    #[Route('/videos/{slug}', name: 'app_video_show')]
    public function show(string $slug, VideoRepository $videoRepository): Response
    {
        $video = $videoRepository->findOneBySlug($slug) ?? throw $this->createNotFoundException('Contenu introuvable.');

        return $this->render('video/show.html.twig', [
            'video' => $video,
            'relatedVideos' => $videoRepository->findRelated($video, 8),
        ]);
    }

    #[Route('/videos/{slug}/telecharger-tout', name: 'app_video_download_all')]
    public function downloadAll(string $slug, VideoRepository $videoRepository, VideoFileZipBuilder $zipBuilder): Response
    {
        $video = $videoRepository->findOneBySlug($slug) ?? throw $this->createNotFoundException('Contenu introuvable.');

        $availableFiles = array_values(array_filter(
            iterator_to_array($video->getFiles()),
            static fn ($file) => $file->getFileName() !== null,
        ));

        if ($availableFiles === []) {
            throw $this->createNotFoundException('Aucun fichier disponible pour ce contenu.');
        }

        $zipPath = $zipBuilder->build($availableFiles);

        $response = new BinaryFileResponse($zipPath);
        $response->deleteFileAfterSend(true);
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            sprintf('%s.zip', $video->getSlug()),
        );

        return $response;
    }

    #[Route('/videos/{slug}/avis', name: 'app_video_feedback', methods: ['POST'])]
    public function feedback(string $slug, Request $request, VideoRepository $videoRepository, EntityManagerInterface $entityManager): Response
    {
        $video = $videoRepository->findOneBySlug($slug) ?? throw $this->createNotFoundException('Contenu introuvable.');

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
