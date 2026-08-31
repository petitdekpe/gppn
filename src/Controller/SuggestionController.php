<?php

namespace App\Controller;

use App\Entity\Suggestion;
use App\Form\SuggestionType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class SuggestionController extends AbstractController
{
    #[Route('/proposer-un-sujet', name: 'app_suggestion_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        $suggestion = new Suggestion();
        $form = $this->createForm(SuggestionType::class, $suggestion);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($suggestion);
            $entityManager->flush();

            $this->addFlash('success', 'Merci ! Votre suggestion a bien été envoyée à l’équipe éditoriale.');

            return $this->redirectToRoute('app_suggestion_new');
        }

        return $this->render('suggestion/new.html.twig', [
            'form' => $form,
        ]);
    }
}
