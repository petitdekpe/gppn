<?php

namespace App\Controller\Admin;

use App\Entity\Suggestion;
use App\Repository\SuggestionRepository;
use App\Service\AdminPaginator;
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
    use BulkActionTrait;

    private const PER_PAGE = 30;

    #[Route('', name: 'admin_suggestion_index')]
    public function index(SuggestionRepository $suggestionRepository, Request $request): Response
    {
        $results = AdminPaginator::paginate(
            $suggestionRepository->createQueryBuilder('s')->orderBy('s.treated', 'ASC')->addOrderBy('s.createdAt', 'DESC'),
            $request->query->getInt('page', 1),
            self::PER_PAGE,
        );

        return $this->render('admin/suggestion/index.html.twig', [
            'suggestions' => $results['items'],
            'results' => $results,
        ]);
    }

    // Déclarée avant /{id} : cette route-là capterait « actions-groupees ».
    #[Route('/actions-groupees', name: 'admin_suggestion_bulk', methods: ['POST'])]
    public function bulk(Request $request, SuggestionRepository $suggestionRepository, EntityManagerInterface $entityManager): Response
    {
        $ids = $this->bulkIds($request, 'bulk-suggestion');
        if ($ids === null) {
            return $this->backToList($request, 'admin_suggestion_index');
        }

        $action = $request->request->getString('action');
        if (!in_array($action, ['treated', 'untreated', 'delete'], true)) {
            $this->unknownBulkAction();

            return $this->backToList($request, 'admin_suggestion_index');
        }

        $suggestions = $suggestionRepository->findBy(['id' => $ids]);
        foreach ($suggestions as $suggestion) {
            $action === 'delete' ? $entityManager->remove($suggestion) : $suggestion->setTreated($action === 'treated');
        }
        $entityManager->flush();

        $this->bulkReport(match ($action) {
            'treated' => self::plural(count($suggestions), 'suggestion marquée traitée', 'suggestions marquées traitées'),
            'untreated' => self::plural(count($suggestions), 'suggestion remise à traiter', 'suggestions remises à traiter'),
            'delete' => self::plural(count($suggestions), 'suggestion supprimée', 'suggestions supprimées'),
        } . '.');

        return $this->backToList($request, 'admin_suggestion_index');
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
