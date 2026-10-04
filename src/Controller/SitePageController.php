<?php

namespace App\Controller;

use App\Entity\Suggestion;
use App\Form\ContactType;
use App\Service\SitePages;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Pages de texte modifiables depuis l'admin (voir SitePages) et formulaire
 * « Nous contacter », dont les messages arrivent dans l'admin (menu « Messages »).
 */
class SitePageController extends AbstractController
{
    public function __construct(
        private readonly SitePages $sitePages,
    ) {
    }

    #[Route('/politique-de-confidentialite', name: 'app_privacy')]
    public function privacy(): Response
    {
        return $this->render('site_page/show.html.twig', ['page' => $this->sitePages->get(SitePages::PRIVACY)]);
    }

    #[Route('/mentions-legales', name: 'app_legal_notice')]
    public function legalNotice(): Response
    {
        return $this->render('site_page/show.html.twig', ['page' => $this->sitePages->get(SitePages::LEGAL_NOTICE)]);
    }

    #[Route('/nous-contacter', name: 'app_contact', methods: ['GET', 'POST'])]
    public function contact(Request $request, EntityManagerInterface $entityManager): Response
    {
        $message = (new Suggestion())->setKind(Suggestion::KIND_CONTACT);
        $form = $this->createForm(ContactType::class, $message);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($message);
            $entityManager->flush();

            $this->addFlash('success', 'Merci, votre message a bien été envoyé. Nous vous répondrons à l’adresse e-mail indiquée.');

            return $this->redirectToRoute('app_contact');
        }

        return $this->render('site_page/contact.html.twig', [
            'page' => $this->sitePages->get(SitePages::CONTACT),
            'form' => $form,
        ]);
    }
}
