<?php

namespace App\Controller\Admin;

use App\Entity\Video;
use App\Form\Admin\VideoType;
use App\Repository\VideoRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/videos')]
#[IsGranted('ROLE_EDITEUR')]
class VideoController extends AbstractController
{
    #[Route('', name: 'admin_video_index')]
    public function index(VideoRepository $videoRepository): Response
    {
        return $this->render('admin/video/index.html.twig', [
            'videos' => $videoRepository->findBy([], ['publishedAt' => 'DESC']),
        ]);
    }

    #[Route('/nouveau', name: 'admin_video_new')]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        $video = new Video();
        $form = $this->createForm(VideoType::class, $video);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($video);
            $entityManager->flush();

            $this->addFlash('success', 'Vidéo créée.');

            return $this->redirectToRoute('admin_video_index');
        }

        return $this->render('admin/video/form.html.twig', [
            'form' => $form,
            'video' => $video,
        ]);
    }

    #[Route('/{id}/modifier', name: 'admin_video_edit')]
    public function edit(Video $video, Request $request, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createForm(VideoType::class, $video);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();

            $this->addFlash('success', 'Vidéo mise à jour.');

            return $this->redirectToRoute('admin_video_index');
        }

        return $this->render('admin/video/form.html.twig', [
            'form' => $form,
            'video' => $video,
        ]);
    }

    #[Route('/{id}/supprimer', name: 'admin_video_delete', methods: ['POST'])]
    public function delete(Video $video, Request $request, EntityManagerInterface $entityManager): Response
    {
        if ($this->isCsrfTokenValid('delete-video-' . $video->getId(), $request->request->get('_token'))) {
            $entityManager->remove($video);
            $entityManager->flush();

            $this->addFlash('success', 'Vidéo supprimée.');
        }

        return $this->redirectToRoute('admin_video_index');
    }
}
