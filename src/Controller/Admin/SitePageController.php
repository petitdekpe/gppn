<?php

namespace App\Controller\Admin;

use App\Entity\User;
use App\Form\Admin\SitePageType;
use App\Service\SitePages;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Pages de texte du site (confidentialité, mentions légales, contact),
 * rédigées en Markdown : voir SitePages.
 */
#[Route('/admin/pages')]
#[IsGranted('ROLE_SUPER_ADMIN')]
class SitePageController extends AbstractController
{
    public function __construct(
        private readonly SitePages $sitePages,
    ) {
    }

    #[Route('', name: 'admin_site_page_index')]
    public function index(): Response
    {
        $pages = [];
        foreach (SitePages::DEFINITIONS as $slug => $definition) {
            $pages[] = ['page' => $this->sitePages->get($slug), 'definition' => $definition];
        }

        return $this->render('admin/site_page/index.html.twig', ['pages' => $pages]);
    }

    /** Aperçu du Markdown en cours de saisie (markdown_preview_controller.js). */
    #[Route('/apercu', name: 'admin_site_page_preview', methods: ['POST'])]
    public function preview(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('site-page-preview', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        return $this->render('admin/site_page/_preview.html.twig', [
            'content' => $request->request->getString('content'),
        ]);
    }

    #[Route('/{slug}', name: 'admin_site_page_edit', requirements: ['slug' => 'confidentialite|mentions-legales|contact'])]
    public function edit(string $slug, Request $request, EntityManagerInterface $entityManager): Response
    {
        $page = $this->sitePages->get($slug);
        $form = $this->createForm(SitePageType::class, $page);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($form->get('restoreDefault')->getData()) {
                $page->setContent($this->sitePages->defaultContent($slug));
            }
            $user = $this->getUser();
            $page->markUpdated($user instanceof User ? $user : null);
            $entityManager->persist($page);
            $entityManager->flush();

            $this->addFlash('success', sprintf('Page « %s » enregistrée.', $page->getTitle()));

            return $this->redirectToRoute('admin_site_page_edit', ['slug' => $slug]);
        }

        return $this->render('admin/site_page/edit.html.twig', [
            'page' => $page,
            'definition' => SitePages::DEFINITIONS[$slug],
            'form' => $form,
        ]);
    }
}
