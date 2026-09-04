<?php

namespace App\Controller\Admin;

use App\Entity\CouncilSession;
use App\Entity\Subject;
use App\Form\Admin\CouncilSessionType;
use App\Form\Admin\SubjectType;
use App\Repository\CouncilSessionRepository;
use App\Repository\SubjectRepository;
use App\Repository\VideoRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/conseils-des-ministres')]
#[IsGranted('ROLE_EDITEUR')]
class CouncilSessionController extends AbstractController
{
    #[Route('', name: 'admin_council_session_index')]
    public function index(CouncilSessionRepository $councilSessionRepository, VideoRepository $videoRepository, SubjectRepository $subjectRepository): Response
    {
        $councilSessions = $councilSessionRepository->findBy([], ['date' => 'DESC']);
        $videoCounts = [];
        $subjectCounts = [];
        foreach ($councilSessions as $councilSession) {
            $videoCounts[$councilSession->getId()] = $videoRepository->countByCouncilSession($councilSession);
            $subjectCounts[$councilSession->getId()] = $subjectRepository->count(['councilSession' => $councilSession]);
        }

        return $this->render('admin/council_session/index.html.twig', [
            'councilSessions' => $councilSessions,
            'videoCounts' => $videoCounts,
            'subjectCounts' => $subjectCounts,
        ]);
    }

    #[Route('/nouveau', name: 'admin_council_session_new')]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        $councilSession = new CouncilSession();
        $form = $this->createForm(CouncilSessionType::class, $councilSession);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->assignSlug($councilSession);
            $entityManager->persist($councilSession);
            $entityManager->flush();

            $this->addFlash('success', 'Conseil des ministres créé.');

            return $this->redirectToRoute('admin_council_session_index');
        }

        return $this->render('admin/council_session/form.html.twig', [
            'form' => $form,
            'councilSession' => $councilSession,
        ]);
    }

    #[Route('/{id}/modifier', name: 'admin_council_session_edit')]
    public function edit(CouncilSession $councilSession, Request $request, EntityManagerInterface $entityManager, SubjectRepository $subjectRepository, VideoRepository $videoRepository): Response
    {
        $form = $this->createForm(CouncilSessionType::class, $councilSession);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->assignSlug($councilSession);
            $entityManager->flush();

            $this->addFlash('success', 'Conseil des ministres mis à jour.');

            return $this->redirectToRoute('admin_council_session_edit', ['id' => $councilSession->getId()]);
        }

        $subjects = $subjectRepository->createQueryBuilder('s')
            ->innerJoin('s.thematic', 't')->addSelect('t')
            ->andWhere('s.councilSession = :councilSession')
            ->setParameter('councilSession', $councilSession)
            ->orderBy('s.referenceTitle', 'ASC')
            ->getQuery()
            ->getResult();

        $videoCounts = [];
        foreach ($subjects as $subject) {
            $videoCounts[$subject->getId()] = $videoRepository->count(['subject' => $subject]);
        }

        return $this->render('admin/council_session/form.html.twig', [
            'form' => $form,
            'councilSession' => $councilSession,
            'subjects' => $subjects,
            'videoCounts' => $videoCounts,
        ]);
    }

    #[Route('/{id}/supprimer', name: 'admin_council_session_delete', methods: ['POST'])]
    public function delete(CouncilSession $councilSession, Request $request, EntityManagerInterface $entityManager, SubjectRepository $subjectRepository): Response
    {
        if (!$this->isCsrfTokenValid('delete-council-session-' . $councilSession->getId(), $request->request->get('_token'))) {
            return $this->redirectToRoute('admin_council_session_index');
        }

        // Un sujet exige un conseil des ministres (colonne non nullable) :
        // il faut donc bloquer dès qu'un sujet existe, même sans contenu.
        if ($subjectRepository->count(['councilSession' => $councilSession]) > 0) {
            $this->addFlash('error', 'Impossible de supprimer un conseil des ministres encore rattaché à des sujets.');

            return $this->redirectToRoute('admin_council_session_index');
        }

        $entityManager->remove($councilSession);
        $entityManager->flush();

        $this->addFlash('success', 'Conseil des ministres supprimé.');

        return $this->redirectToRoute('admin_council_session_index');
    }

    #[Route('/{id}/sujets/nouveau', name: 'admin_council_session_subject_new')]
    public function newSubject(CouncilSession $councilSession, Request $request, EntityManagerInterface $entityManager): Response
    {
        $subject = new Subject();
        $subject->setCouncilSession($councilSession);
        $form = $this->createForm(SubjectType::class, $subject);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($subject);
            $entityManager->flush();

            $this->addFlash('success', 'Sujet créé.');

            return $this->redirectToRoute('admin_council_session_edit', ['id' => $councilSession->getId()]);
        }

        return $this->render('admin/council_session/subject_form.html.twig', [
            'form' => $form,
            'councilSession' => $councilSession,
            'subject' => $subject,
        ]);
    }

    #[Route('/{id}/sujets/{subjectId}/modifier', name: 'admin_council_session_subject_edit')]
    public function editSubject(CouncilSession $councilSession, #[MapEntity(id: 'subjectId')] Subject $subject, Request $request, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createForm(SubjectType::class, $subject);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();

            $this->addFlash('success', 'Sujet mis à jour.');

            return $this->redirectToRoute('admin_council_session_edit', ['id' => $councilSession->getId()]);
        }

        return $this->render('admin/council_session/subject_form.html.twig', [
            'form' => $form,
            'councilSession' => $councilSession,
            'subject' => $subject,
        ]);
    }

    #[Route('/{id}/sujets/{subjectId}/supprimer', name: 'admin_council_session_subject_delete', methods: ['POST'])]
    public function deleteSubject(CouncilSession $councilSession, #[MapEntity(id: 'subjectId')] Subject $subject, Request $request, EntityManagerInterface $entityManager, VideoRepository $videoRepository): Response
    {
        if (!$this->isCsrfTokenValid('delete-subject-' . $subject->getId(), $request->request->get('_token'))) {
            return $this->redirectToRoute('admin_council_session_edit', ['id' => $councilSession->getId()]);
        }

        if ($videoRepository->count(['subject' => $subject]) > 0) {
            $this->addFlash('error', 'Impossible de supprimer un sujet encore rattaché à des contenus.');

            return $this->redirectToRoute('admin_council_session_edit', ['id' => $councilSession->getId()]);
        }

        $entityManager->remove($subject);
        $entityManager->flush();

        $this->addFlash('success', 'Sujet supprimé.');

        return $this->redirectToRoute('admin_council_session_edit', ['id' => $councilSession->getId()]);
    }

    /**
     * Le slug est dérivé de la date plutôt que saisi à la main : la date
     * étant déjà contrainte unique, cela garantit un slug unique et stable
     * sans exposer un champ supplémentaire dans le formulaire.
     */
    private function assignSlug(CouncilSession $councilSession): void
    {
        $date = $councilSession->getDate();
        if ($date === null) {
            return;
        }

        $councilSession->setSlug('conseil-du-' . $date->format('Y-m-d'));
    }
}
