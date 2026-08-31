<?php

namespace App\Controller\Admin;

use App\Entity\Suggestion;
use App\Repository\SuggestionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/suggestions')]
#[IsGranted('ROLE_MODERATEUR')]
class SuggestionController extends AbstractController
{
    #[Route('', name: 'admin_suggestion_index')]
    public function index(SuggestionRepository $suggestionRepository): Response
    {
        return $this->render('admin/suggestion/index.html.twig', [
            'suggestions' => $suggestionRepository->findBy([], ['treated' => 'ASC', 'createdAt' => 'DESC']),
        ]);
    }

    #[Route('/{id}', name: 'admin_suggestion_show')]
    public function show(Suggestion $suggestion): Response
    {
        return $this->render('admin/suggestion/show.html.twig', [
            'suggestion' => $suggestion,
        ]);
    }

    #[Route('/{id}/traiter', name: 'admin_suggestion_toggle_treated', methods: ['POST'])]
    public function toggleTreated(Suggestion $suggestion, Request $request, EntityManagerInterface $entityManager): Response
    {
        if ($this->isCsrfTokenValid('toggle-suggestion-' . $suggestion->getId(), $request->request->get('_token'))) {
            $suggestion->setTreated(!$suggestion->isTreated());
            $entityManager->flush();
        }

        return $this->redirectToRoute('admin_suggestion_index');
    }

    #[Route('/{id}/supprimer', name: 'admin_suggestion_delete', methods: ['POST'])]
    public function delete(Suggestion $suggestion, Request $request, EntityManagerInterface $entityManager): Response
    {
        if ($this->isCsrfTokenValid('delete-suggestion-' . $suggestion->getId(), $request->request->get('_token'))) {
            $entityManager->remove($suggestion);
            $entityManager->flush();

            $this->addFlash('success', 'Suggestion supprimée.');
        }

        return $this->redirectToRoute('admin_suggestion_index');
    }
}
