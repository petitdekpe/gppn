<?php

namespace App\Controller\Admin;

use App\Entity\Subject;
use App\Form\Admin\SubjectType;
use App\Repository\SubjectRepository;
use App\Repository\VideoRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/sujets')]
#[IsGranted('ROLE_EDITEUR')]
class SubjectController extends AbstractController
{
    #[Route('', name: 'admin_subject_index')]
    public function index(SubjectRepository $subjectRepository, VideoRepository $videoRepository): Response
    {
        $subjects = $subjectRepository->createQueryBuilder('s')
            ->innerJoin('s.councilSession', 'cs')->addSelect('cs')
            ->innerJoin('s.thematic', 't')->addSelect('t')
            ->orderBy('cs.date', 'DESC')
            ->addOrderBy('s.referenceTitle', 'ASC')
            ->getQuery()
            ->getResult();

        $videoCounts = [];
        foreach ($subjects as $subject) {
            $videoCounts[$subject->getId()] = $videoRepository->count(['subject' => $subject]);
        }

        return $this->render('admin/subject/index.html.twig', [
            'subjects' => $subjects,
            'videoCounts' => $videoCounts,
        ]);
    }

    #[Route('/nouveau', name: 'admin_subject_new')]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        $subject = new Subject();
        $form = $this->createForm(SubjectType::class, $subject);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($subject);
            $entityManager->flush();

            $this->addFlash('success', 'Sujet créé.');

            return $this->redirectToRoute('admin_subject_index');
        }

        return $this->render('admin/subject/form.html.twig', [
            'form' => $form,
            'subject' => $subject,
        ]);
    }

    #[Route('/{id}/modifier', name: 'admin_subject_edit')]
    public function edit(Subject $subject, Request $request, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createForm(SubjectType::class, $subject);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();

            $this->addFlash('success', 'Sujet mis à jour.');

            return $this->redirectToRoute('admin_subject_index');
        }

        return $this->render('admin/subject/form.html.twig', [
            'form' => $form,
            'subject' => $subject,
        ]);
    }

    #[Route('/{id}/supprimer', name: 'admin_subject_delete', methods: ['POST'])]
    public function delete(Subject $subject, Request $request, EntityManagerInterface $entityManager, VideoRepository $videoRepository): Response
    {
        if (!$this->isCsrfTokenValid('delete-subject-' . $subject->getId(), $request->request->get('_token'))) {
            return $this->redirectToRoute('admin_subject_index');
        }

        if ($videoRepository->count(['subject' => $subject]) > 0) {
            $this->addFlash('error', 'Impossible de supprimer un sujet encore rattaché à des contenus.');

            return $this->redirectToRoute('admin_subject_index');
        }

        $entityManager->remove($subject);
        $entityManager->flush();

        $this->addFlash('success', 'Sujet supprimé.');

        return $this->redirectToRoute('admin_subject_index');
    }
}
