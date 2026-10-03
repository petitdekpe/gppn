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
    ): Response {
        $queryParams = $request->query->all();
        $thematicSlugs = isset($queryParams['thematique']) ? array_values((array) $queryParams['thematique']) : [];
        $languageSlugs = isset($queryParams['langue']) ? array_values((array) $queryParams['langue']) : [];
        $formatValues = isset($queryParams['format']) ? array_values((array) $queryParams['format']) : [];
        $councilSessionSlugs = isset($queryParams['conseil']) ? array_values((array) $queryParams['conseil']) : [];

        $selectedThematics = $thematicSlugs ? $thematicRepository->findBy(['slug' => $thematicSlugs]) : [];
        $selectedLanguages = $languageSlugs ? $languageRepository->findBy(['slug' => $languageSlugs]) : [];
        $selectedCouncilSessions = $councilSessionSlugs ? $councilSessionRepository->findBy(['slug' => $councilSessionSlugs]) : [];
        // Recherche par format « Audio » (radio) réservée aux médias.
        $selectedFormats = $mediaAccess->filterFormats(array_filter(array_map(
            static fn (mixed $value): ?CapsuleFormat => CapsuleFormat::tryFrom((string) $value),
            $formatValues,
        )));

        $query = $request->query->getString('q') ?: null;
        $page = max(1, $request->query->getInt('page', 1));

        $selectedRole = $request->query->getString('role') ?: 'tous';
        if (!in_array($selectedRole, ['tous', 'ministre', 'conseiller'], true)) {
            $selectedRole = 'tous';
        }

        // Filtres facultatifs, repliés dans la barre latérale.
        $speakerPeriod = $speakerPeriodCriteria->fromParams($queryParams);

        // Texte recherché qui désigne un intervenant (nom, sigle) : ses contenus,
        // et le lien vers sa page en tête des résultats.
        $matchedPeople = $query !== null ? $speakerPeriodCriteria->searchPeople($query, 3) : [];

        $results = $videoRepository->search($selectedThematics, $selectedLanguages, $selectedFormats, $query, $page, speakerRole: $selectedRole, councilSessions: $selectedCouncilSessions, speakerPeriod: $speakerPeriod, querySpeakerIds: array_merge([], ...array_column($matchedPeople, 'speakerIds')));

        // Filtres retenus (format « Audio » écarté pour le public), repris dans la pagination.
        $routeParams = array_filter([
            'thematique' => $thematicSlugs,
            'langue' => $languageSlugs,
            'format' => array_map(static fn (CapsuleFormat $format) => $format->value, $selectedFormats),
            'conseil' => $councilSessionSlugs,
            'q' => $query,
            'role' => $selectedRole !== 'tous' ? $selectedRole : null,
        ]) + $speakerPeriod->queryParams();

        // Étiquettes des filtres actifs, au-dessus des résultats : chacune retire son filtre d'un clic.
        $without = function (string $key, ?string $value = null) use ($routeParams): string {
            $params = $routeParams;
            if ($value === null) {
                unset($params[$key]);
            } else {
                $params[$key] = array_values(array_diff((array) ($params[$key] ?? []), [$value]));
            }
            if ($key === 'du') {
                unset($params['au']); // une période se retire d'un bloc
            }

            return $this->generateUrl('app_video_index', array_filter($params));
        };
        $activeFilters = [];
        if ($selectedRole !== 'tous') {
            $activeFilters[] = ['label' => $selectedRole === 'conseiller' ? 'Ministres conseillers' : 'Ministres', 'url' => $without('role')];
        }
        foreach ($selectedThematics as $thematic) {
            $activeFilters[] = ['label' => $thematic->getName(), 'url' => $without('thematique', $thematic->getSlug())];
        }
        foreach ($selectedLanguages as $language) {
            $activeFilters[] = ['label' => $language->getName(), 'url' => $without('langue', $language->getSlug())];
        }
        foreach ($selectedFormats as $format) {
            $activeFilters[] = ['label' => $format->getLabel(), 'url' => $without('format', $format->value)];
        }
        foreach ($selectedCouncilSessions as $councilSession) {
            $activeFilters[] = ['label' => $councilSession->getLabel() ?: 'Conseil du ' . $councilSession->getDate()->format('d/m/Y'), 'url' => $without('conseil', $councilSession->getSlug())];
        }
        if ($speakerPeriod->hasPerson()) {
            $activeFilters[] = ['label' => $speakerPeriod->personName, 'url' => $without('intervenant')];
        }
        if ($speakerPeriod->hasPeriod()) {
            $activeFilters[] = ['label' => ucfirst($speakerPeriod->periodLabel()), 'url' => $without('du')];
        }
        if ($query !== null) {
            $activeFilters[] = ['label' => '« ' . $query . ' »', 'url' => $without('q')];
        }

        return $this->render('video/index.html.twig', [
            'results' => $results,
            'thematics' => $thematicRepository->findAllWithVideoCount(),
            'languages' => $languageRepository->findAllWithVideoCount(),
            'formats' => $mediaAccess->filterFormats($settings->getEnabledFormats()),
            'formatCounts' => $videoRepository->countAllByFormat(),
            'councilSessions' => $councilSessionRepository->findAllWithVideoCount(),
            'selectedThematicSlugs' => $thematicSlugs,
            'selectedLanguageSlugs' => $languageSlugs,
            'selectedFormatValues' => array_map(static fn (CapsuleFormat $format) => $format->value, $selectedFormats),
            'selectedCouncilSessionSlugs' => $councilSessionSlugs,
            'query' => $query,
            'selectedRole' => $selectedRole,
            'people' => $speakerPeriodCriteria->people(),
            'periodShortcuts' => $speakerPeriodCriteria->periodShortcuts(),
            'speakerPeriod' => $speakerPeriod,
            'routeParams' => $routeParams,
            'activeFilters' => $activeFilters,
            'matchedPeople' => $matchedPeople,
            'resetUrl' => $this->generateUrl('app_video_index'),
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
    public function show(string $slug, VideoRepository $videoRepository, EntityManagerInterface $entityManager): Response
    {
        $video = $videoRepository->findOneBySlug($slug) ?? throw $this->createNotFoundException('Contenu introuvable.');

        $video->setViewsCount($video->getViewsCount() + 1);
        $entityManager->flush();

        return $this->render('video/show.html.twig', [
            'video' => $video,
            'relatedVideos' => $videoRepository->findRelated($video, 8),
        ]);
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
