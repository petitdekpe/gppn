<?php

namespace App\Controller\Admin;

use App\Entity\Video;
use App\Entity\VideoFile;
use App\Enum\VideoFileType;
use App\Form\Admin\VideoType;
use App\Repository\CouncilSessionRepository;
use App\Repository\SubjectRepository;
use App\Repository\VideoRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/videos')]
#[IsGranted('ROLE_EDITEUR')]
class VideoController extends AbstractController
{
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
    public function edit(Video $video, Request $request, EntityManagerInterface $entityManager, CouncilSessionRepository $councilSessionRepository): Response
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
        ]);
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
