<?php

namespace App\Controller;

use App\Entity\VideoFeedback;
use App\Enum\CapsuleFormat;
use App\Message\CheckVideoFile;
use App\Repository\CouncilSessionRepository;
use App\Repository\LanguageRepository;
use App\Repository\ThematicRepository;
use App\Repository\VideoFileRepository;
use App\Repository\VideoRepository;
use App\Service\AppSettings;
use App\Service\ContentFilters;
use App\Service\MediaAccess;
use App\Service\SpeakerPeriodCriteria;
use App\Service\VideoFileChecker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

class VideoController extends AbstractController
{
    #[Route('/videos', name: 'app_video_index')]
    public function index(
        Request $request,
        VideoRepository $videoRepository,
        ThematicRepository $thematicRepository,
        LanguageRepository $languageRepository,
        CouncilSessionRepository $councilSessionRepository,
        AppSettings $settings,
        SpeakerPeriodCriteria $speakerPeriodCriteria,
        MediaAccess $mediaAccess,
        ContentFilters $contentFilters,
    ): Response {
        $filters = $contentFilters->read($request, 'app_video_index');

        $results = $videoRepository->searchBySubject($filters['thematics'], $filters['languages'], $filters['formats'], $filters['query'], $filters['page'], speakerRole: $filters['role'], councilSessions: $filters['councilSessions'], speakerPeriod: $filters['speakerPeriod'], querySpeakerIds: $filters['querySpeakerIds']);

        return $this->render('video/index.html.twig', [
            'results' => $results,
            'thematics' => $thematicRepository->findAllWithVideoCount(),
            'languages' => $languageRepository->findAllWithVideoCount(),
            'formats' => $mediaAccess->filterFormats($settings->getEnabledFormats()),
            'formatCounts' => $videoRepository->countAllByFormat(),
            'councilSessions' => $councilSessionRepository->findAllWithVideoCount(),
            'selectedThematicSlugs' => $filters['selectedThematicSlugs'],
            'selectedLanguageSlugs' => $filters['selectedLanguageSlugs'],
            'selectedFormatValues' => $filters['selectedFormatValues'],
            'selectedCouncilSessionSlugs' => $filters['selectedCouncilSessionSlugs'],
            'query' => $filters['query'],
            'selectedRole' => $filters['role'],
            'people' => $speakerPeriodCriteria->people(),
            'periodShortcuts' => $speakerPeriodCriteria->periodShortcuts(),
            'speakerPeriod' => $filters['speakerPeriod'],
            'routeParams' => $filters['routeParams'],
            'activeFilters' => $filters['activeFilters'],
            'matchedPeople' => $filters['matchedPeople'],
            'resetUrl' => $filters['resetUrl'],
            'featuredVideos' => $videoRepository->findFeatured(3),
        ]);
    }

    #[Route('/feed', name: 'app_video_feed')]
    public function feed(VideoRepository $videoRepository): Response
    {
        $results = $videoRepository->findVerticalFeed();

        return $this->render('video/feed.html.twig', [
            'videos' => $results['videos'],
            'hasMore' => $results['hasMore'],
            'nextPage' => $results['page'] + 1,
        ]);
    }

    #[Route('/feed/plus', name: 'app_video_feed_more')]
    public function feedMore(Request $request, VideoRepository $videoRepository): JsonResponse
    {
        $page = max(1, $request->query->getInt('page', 1));
        $results = $videoRepository->findVerticalFeed($page);

        return $this->json([
            'html' => $this->renderView('video/_feed_items.html.twig', ['videos' => $results['videos']]),
            'hasMore' => $results['hasMore'],
            'nextPage' => $results['page'] + 1,
        ]);
    }

    /**
     * Échec de lecture remonté par le navigateur (voir assets/playback_report.js).
     * Ne masque rien par lui-même : il met en file une vérification ffmpeg,
     * au plus une par fichier toutes les 10 minutes.
     */
    #[Route('/videos/fichiers/{id}/echec-lecture', name: 'app_video_file_playback_error', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function playbackError(int $id, VideoFileRepository $videoFileRepository, VideoFileChecker $checker, EntityManagerInterface $entityManager, MessageBusInterface $bus): Response
    {
        $file = $videoFileRepository->find($id);
        if ($file !== null && $checker->supports($file) && ($file->getCheckedAt() === null || $file->getCheckedAt() < new \DateTimeImmutable('-10 minutes'))) {
            $file->setCheckedAt(new \DateTimeImmutable());
            $entityManager->flush();
            $bus->dispatch(new CheckVideoFile($file->getId()));
        }

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/videos/{slug}', name: 'app_video_show')]
    public function show(string $slug, VideoRepository $videoRepository, EntityManagerInterface $entityManager, AppSettings $settings): Response
    {
        $video = $videoRepository->findOneBySlug($slug) ?? throw $this->createNotFoundException('Contenu introuvable.');

        $video->setViewsCount($video->getViewsCount() + 1);
        $entityManager->flush();

        return $this->render('video/show.html.twig', [
            'video' => $video,
            'relatedVideos' => $videoRepository->findRelated($video, 8),
            'feedbackEnabled' => $settings->isFeedbackEnabled(),
        ]);
    }

    #[Route('/videos/{slug}/avis', name: 'app_video_feedback', methods: ['POST'])]
    public function feedback(string $slug, Request $request, VideoRepository $videoRepository, EntityManagerInterface $entityManager, AppSettings $settings): Response
    {
        // Section désactivée dans les paramètres : aucun avis n'est enregistré.
        if (!$settings->isFeedbackEnabled()) {
            throw $this->createNotFoundException('Les avis sont désactivés.');
        }

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

        $isAudio = $video->getPrimaryPlaybackFile()?->getType()->getCategory() === CapsuleFormat::AUDIO;
        $this->addFlash('success', sprintf('Merci pour votre retour, il nous aide à améliorer %s.', $isAudio ? 'cet audio' : 'cette vidéo'));

        return $this->redirectToRoute('app_video_show', ['slug' => $slug]);
    }
}
