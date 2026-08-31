<?php

namespace App\Controller\Admin;

use App\Entity\Language;
use App\Form\Admin\LanguageType;
use App\Repository\LanguageRepository;
use App\Repository\VideoRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/langues')]
#[IsGranted('ROLE_EDITEUR')]
class LanguageController extends AbstractController
{
    #[Route('', name: 'admin_language_index')]
    public function index(LanguageRepository $languageRepository, VideoRepository $videoRepository): Response
    {
        $languages = $languageRepository->findBy([], ['name' => 'ASC']);
        $videoCounts = [];
        foreach ($languages as $language) {
            $videoCounts[$language->getId()] = $videoRepository->count(['language' => $language]);
        }

        return $this->render('admin/language/index.html.twig', [
            'languages' => $languages,
            'videoCounts' => $videoCounts,
        ]);
    }

    #[Route('/nouveau', name: 'admin_language_new')]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        $language = new Language();
        $form = $this->createForm(LanguageType::class, $language);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($language);
            $entityManager->flush();

            $this->addFlash('success', 'Langue créée.');

            return $this->redirectToRoute('admin_language_index');
        }

        return $this->render('admin/language/form.html.twig', [
            'form' => $form,
            'language' => $language,
        ]);
    }

    #[Route('/{id}/modifier', name: 'admin_language_edit')]
    public function edit(Language $language, Request $request, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createForm(LanguageType::class, $language);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();

            $this->addFlash('success', 'Langue mise à jour.');

            return $this->redirectToRoute('admin_language_index');
        }

        return $this->render('admin/language/form.html.twig', [
            'form' => $form,
            'language' => $language,
        ]);
    }

    #[Route('/{id}/supprimer', name: 'admin_language_delete', methods: ['POST'])]
    public function delete(Language $language, Request $request, EntityManagerInterface $entityManager, VideoRepository $videoRepository): Response
    {
        if (!$this->isCsrfTokenValid('delete-language-' . $language->getId(), $request->request->get('_token'))) {
            return $this->redirectToRoute('admin_language_index');
        }

        if ($videoRepository->count(['language' => $language]) > 0) {
            $this->addFlash('error', 'Impossible de supprimer une langue encore utilisée par des vidéos.');

            return $this->redirectToRoute('admin_language_index');
        }

        $entityManager->remove($language);
        $entityManager->flush();

        $this->addFlash('success', 'Langue supprimée.');

        return $this->redirectToRoute('admin_language_index');
    }
}
