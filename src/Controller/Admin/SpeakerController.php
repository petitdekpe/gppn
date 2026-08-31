<?php

namespace App\Controller\Admin;

use App\Entity\Speaker;
use App\Form\Admin\SpeakerType;
use App\Repository\SpeakerRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/intervenants')]
#[IsGranted('ROLE_EDITEUR')]
class SpeakerController extends AbstractController
{
    #[Route('', name: 'admin_speaker_index')]
    public function index(SpeakerRepository $speakerRepository): Response
    {
        return $this->render('admin/speaker/index.html.twig', [
            'speakers' => $speakerRepository->findBy([], ['fullName' => 'ASC']),
        ]);
    }

    #[Route('/nouveau', name: 'admin_speaker_new')]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        $speaker = new Speaker();
        $form = $this->createForm(SpeakerType::class, $speaker);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($speaker);
            $entityManager->flush();

            $this->addFlash('success', 'Intervenant créé.');

            return $this->redirectToRoute('admin_speaker_index');
        }

        return $this->render('admin/speaker/form.html.twig', [
            'form' => $form,
            'speaker' => $speaker,
        ]);
    }

    #[Route('/{id}/modifier', name: 'admin_speaker_edit')]
    public function edit(Speaker $speaker, Request $request, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createForm(SpeakerType::class, $speaker);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();

            $this->addFlash('success', 'Intervenant mis à jour.');

            return $this->redirectToRoute('admin_speaker_index');
        }

        return $this->render('admin/speaker/form.html.twig', [
            'form' => $form,
            'speaker' => $speaker,
        ]);
    }

    #[Route('/{id}/supprimer', name: 'admin_speaker_delete', methods: ['POST'])]
    public function delete(Speaker $speaker, Request $request, EntityManagerInterface $entityManager): Response
    {
        if ($this->isCsrfTokenValid('delete-speaker-' . $speaker->getId(), $request->request->get('_token'))) {
            $entityManager->remove($speaker);
            $entityManager->flush();

            $this->addFlash('success', 'Intervenant supprimé.');
        }

        return $this->redirectToRoute('admin_speaker_index');
    }
}
