<?php

namespace App\Controller\Admin;

use App\Entity\Video;
use App\Entity\VideoFile;
use App\Enum\VideoFileType;
use App\Enum\VideoStatus;
use App\Exception\CoverGenerationException;
use App\Form\Admin\VideoType;
use App\Message\GenerateVideoCover;
use App\Repository\CouncilSessionRepository;
use App\Repository\SubjectRepository;
use App\Repository\VideoRepository;
use App\Service\VideoCoverGenerator;
use App\Service\VideoCoverUrlResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/videos')]
#[IsGranted('ROLE_EDITEUR')]
class VideoController extends AbstractController
{
    use BulkActionTrait;

    #[Route('', name: 'admin_video_index')]
    public function index(VideoRepository $videoRepository, Request $request): Response
    {
        // Conseil des ministres → sujet → contenus (déjà triés par langue).
        $sessions = [];
        foreach ($videoRepository->findForAdminIndex() as $video) {
            $subject = $video->getSubject();
            $session = $subject->getCouncilSession();
            $sessions[$session->getId()] ??= ['session' => $session, 'count' => 0, 'subjects' => []];
            $sessions[$session->getId()]['subjects'][$subject->getId()] ??= ['subject' => $subject, 'videos' => []];
            $sessions[$session->getId()]['subjects'][$subject->getId()]['videos'][] = $video;
            ++$sessions[$session->getId()]['count'];
        }

        // Données du calendrier latéral (mois → conseils), du plus récent au plus ancien.
        $calendar = array_values(array_map(fn (array $group) => [
            'id' => $group['session']->getId(),
            'date' => $group['session']->getDate()->format('Y-m-d'),
            'count' => $group['count'],
        ], $sessions));

        // Conseil affiché : celui demandé (?conseil=, cf. redirectToCouncil) ou le plus récent.
        $selectedId = $request->query->getInt('conseil');
        if (!isset($sessions[$selectedId])) {
            $selectedId = array_key_first($sessions);
        }

        return $this->render('admin/video/index.html.twig', [
            'sessions' => $sessions,
            'calendar' => $calendar,
            'selectedSessionId' => $selectedId,
        ]);
    }

    #[Route('/actions-groupees', name: 'admin_video_bulk', methods: ['POST'])]
    public function bulk(Request $request, VideoRepository $videoRepository, EntityManagerInterface $entityManager, VideoCoverGenerator $coverGenerator, MessageBusInterface $bus): Response
    {
        $ids = $this->bulkIds($request, 'bulk-video');
        if ($ids === null) {
            return $this->backToList($request, 'admin_video_index');
        }

        $action = $request->request->getString('action');
        $videos = $videoRepository->findBy(['id' => $ids]);
        $skipped = [];

        switch ($action) {
            case 'publish':
            case 'draft':
            case 'hide':
                $status = ['publish' => VideoStatus::PUBLIE, 'draft' => VideoStatus::BROUILLON, 'hide' => VideoStatus::MASQUE][$action];
                foreach ($videos as $video) {
                    // Première mise en ligne : datée du jour, comme à l'import en masse.
                    if ($status === VideoStatus::PUBLIE && $video->getStatus() === VideoStatus::BROUILLON) {
                        $video->setPublishedAt(new \DateTimeImmutable());
                    }
                    $video->setStatus($status);
                }
                $message = sprintf('%s : statut « %s ».', self::plural(count($videos), 'contenu'), $status->getLabel());
                break;
            case 'feature':
            case 'unfeature':
                foreach ($videos as $video) {
                    $video->setFeatured($action === 'feature');
                }
                $message = sprintf('%s %s.', self::plural(count($videos), 'contenu'), $action === 'feature' ? 'mis à la une' : (count($videos) > 1 ? 'retirés de la une' : 'retiré de la une'));
                break;
            case 'cover':
                $queued = 0;
                foreach ($videos as $video) {
                    if (!$coverGenerator->hasSource($video)) {
                        $skipped[] = sprintf('%s — %s (pas de vidéo TV)', $video->getTitle(), $video->getLanguage()->getName());

                        continue;
                    }
                    $bus->dispatch(new GenerateVideoCover($video->getId(), force: true));
                    ++$queued;
                }
                $message = sprintf('%s en cours de génération (image de la vidéo TV à 15 s) : rechargez la page dans un instant.', self::plural($queued, 'couverture'));
                break;
            case 'delete':
                foreach ($videos as $video) {
                    $entityManager->remove($video);
                }
                $message = self::plural(count($videos), 'contenu supprimé', 'contenus supprimés') . '.';
                break;
            default:
                $this->unknownBulkAction();

                return $this->backToList($request, 'admin_video_index');
        }

        $entityManager->flush();
        $this->bulkReport($message, $skipped);

        return $this->backToList($request, 'admin_video_index');
    }

    #[Route('/nouveau', name: 'admin_video_new')]
    public function new(Request $request, EntityManagerInterface $entityManager, CouncilSessionRepository $councilSessionRepository, SubjectRepository $subjectRepository): Response
    {
        $video = new Video();
        // Retour du parcours « nouveau conseil / nouveau sujet » : le sujet
        // créé est pré-sélectionné (le calendrier se cale sur son conseil).
        if ($subject = $subjectRepository->find($request->query->getInt('sujet'))) {
            $video->setSubject($subject);
        }
        $this->ensureAllFileSlots($video);
        $form = $this->createForm(VideoType::class, $video);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($video);
            $entityManager->flush();

            $this->addFlash('success', 'Contenu créé.');

            return $this->redirectToCouncil($video);
        }

        return $this->render('admin/video/form.html.twig', [
            'form' => $form,
            'video' => $video,
            'calendar' => $councilSessionRepository->findCalendarWithSubjectCount(),
        ]);
    }

    #[Route('/{id}/modifier', name: 'admin_video_edit')]
    public function edit(Video $video, Request $request, EntityManagerInterface $entityManager, CouncilSessionRepository $councilSessionRepository, VideoCoverGenerator $coverGenerator): Response
    {
        $this->ensureAllFileSlots($video);
        $form = $this->createForm(VideoType::class, $video);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();

            $this->addFlash('success', 'Contenu mis à jour.');

            return $this->redirectToCouncil($video);
        }

        return $this->render('admin/video/form.html.twig', [
            'form' => $form,
            'video' => $video,
            'calendar' => $councilSessionRepository->findCalendarWithSubjectCount(),
            'coverSource' => $coverGenerator->hasSource($video),
        ]);
    }

    /**
     * Bouton « Générer depuis la vidéo TV » du formulaire : image de la
     * seconde choisie, qui remplace la couverture actuelle. Appelé en fetch
     * pour ne pas perdre les modifications en cours du formulaire.
     */
    #[Route('/{id}/couverture', name: 'admin_video_cover_generate', methods: ['POST'])]
    public function generateCover(Video $video, Request $request, VideoCoverGenerator $coverGenerator, VideoCoverUrlResolver $coverUrlResolver): JsonResponse
    {
        if (!$this->isCsrfTokenValid('cover-video-' . $video->getId(), $request->request->getString('_token'))) {
            return new JsonResponse(['error' => 'Jeton de sécurité expiré : rechargez la page puis réessayez.'], Response::HTTP_FORBIDDEN);
        }

        $second = filter_var($request->request->get('second'), \FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($second === false) {
            return new JsonResponse(['error' => 'Indiquez une seconde : un nombre entier, 0 ou plus.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $at = $coverGenerator->generate($video, $second);
        } catch (CoverGenerationException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse(['url' => $coverUrlResolver->resolve($video), 'second' => $at]);
    }

    #[Route('/{id}/supprimer', name: 'admin_video_delete', methods: ['POST'])]
    public function delete(Video $video, Request $request, EntityManagerInterface $entityManager): Response
    {
        if ($this->isCsrfTokenValid('delete-video-' . $video->getId(), $request->request->get('_token'))) {
            $entityManager->remove($video);
            $entityManager->flush();

            $this->addFlash('success', 'Contenu supprimé.');
        }

        return $this->redirectToRoute('admin_video_index');
    }

    /**
     * Retour à la liste, ouverte sur le conseil du contenu enregistré. Un
     * paramètre plutôt qu'une ancre : l'envoi avec barre de progression
     * (XHR) perd l'ancre en suivant la redirection.
     */
    private function redirectToCouncil(Video $video): Response
    {
        return $this->redirectToRoute('admin_video_index', ['conseil' => $video->getCouncilSession()->getId()]);
    }

    /**
     * Garantit qu'un emplacement (vide ou déjà rempli) existe pour chacun
     * des 6 types de fichiers, dans l'ordre d'affichage voulu — le
     * formulaire (CollectionType figée, sans ajout/suppression) s'appuie sur
     * cette liste complète.
     */
    private function ensureAllFileSlots(Video $video): void
    {
        $present = [];
        foreach ($video->getFiles() as $file) {
            $present[$file->getType()->value] = true;
        }

        foreach (VideoFileType::cases() as $type) {
            if (!isset($present[$type->value])) {
                $video->addFile((new VideoFile())->setType($type));
            }
        }
    }
}
