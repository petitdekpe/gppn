<?php

namespace App\Controller\Admin;

use App\Entity\Government;
use App\Form\Admin\GovernmentType;
use App\Repository\GovernmentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Gouvernements, sous la rubrique Intervenants (routes admin_speaker_* pour
 * que le menu reste sur « Intervenants »).
 */
#[Route('/admin/intervenants/gouvernements')]
#[IsGranted('ROLE_EDITEUR')]
class GovernmentController extends AbstractController
{
    #[Route('/nouveau', name: 'admin_speaker_government_new')]
    public function new(Request $request, EntityManagerInterface $entityManager, GovernmentRepository $governmentRepository): Response
    {
        // Premier gouvernement saisi : actuel d'office.
        $government = (new Government())->setCurrent($governmentRepository->findCurrent() === null);

        return $this->handleForm($government, $request, $entityManager, $governmentRepository, 'Gouvernement créé.');
    }

    #[Route('/{id}/modifier', name: 'admin_speaker_government_edit')]
    public function edit(Government $government, Request $request, EntityManagerInterface $entityManager, GovernmentRepository $governmentRepository): Response
    {
        return $this->handleForm($government, $request, $entityManager, $governmentRepository, 'Gouvernement mis à jour.');
    }

    #[Route('/{id}/actuel', name: 'admin_speaker_government_current', methods: ['POST'])]
    public function makeCurrent(Government $government, Request $request, EntityManagerInterface $entityManager, GovernmentRepository $governmentRepository): Response
    {
        if ($this->isCsrfTokenValid('current-government-' . $government->getId(), $request->request->getString('_token'))) {
            $governmentRepository->makeCurrent($government);
            $entityManager->flush();
            $this->addFlash('success', sprintf('« %s » est désormais le gouvernement actuel.', $government->getLabel()));
        }

        return $this->redirectToRoute('admin_speaker_index');
    }

    #[Route('/{id}/supprimer', name: 'admin_speaker_government_delete', methods: ['POST'])]
    public function delete(Government $government, Request $request, EntityManagerInterface $entityManager): Response
    {
        if (!$this->isCsrfTokenValid('delete-government-' . $government->getId(), $request->request->getString('_token'))) {
            return $this->redirectToRoute('admin_speaker_index');
        }
        if (!$government->getSpeakers()->isEmpty()) {
            $this->addFlash('error', 'Ce gouvernement compte encore des intervenants : déplacez-les ou supprimez-les d’abord.');

            return $this->redirectToRoute('admin_speaker_index');
        }

        $entityManager->remove($government);
        $entityManager->flush();
        $this->addFlash('success', 'Gouvernement supprimé.');

        return $this->redirectToRoute('admin_speaker_index');
    }

    private function handleForm(Government $government, Request $request, EntityManagerInterface $entityManager, GovernmentRepository $governmentRepository, string $success): Response
    {
        $form = $this->createForm(GovernmentType::class, $government);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($government->isCurrent()) {
                $governmentRepository->makeCurrent($government);
            }
            $entityManager->persist($government);
            $entityManager->flush();
            $this->addFlash('success', $success);

            return $this->redirectToRoute('admin_speaker_index', ['gouvernement' => $government->getId()]);
        }

        return $this->render('admin/speaker/government_form.html.twig', [
            'form' => $form,
            'government' => $government,
        ]);
    }
}
