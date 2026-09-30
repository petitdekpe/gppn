<?php

namespace App\Controller\Admin;

use App\Entity\Thematic;
use App\Form\Admin\ThematicType;
use App\Repository\SubjectRepository;
use App\Repository\ThematicRepository;
use App\Repository\VideoRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/thematiques')]
#[IsGranted('ROLE_EDITEUR')]
class ThematicController extends AbstractController
{
    use BulkActionTrait;

    #[Route('', name: 'admin_thematic_index')]
    public function index(ThematicRepository $thematicRepository, VideoRepository $videoRepository, SubjectRepository $subjectRepository): Response
    {
        $thematics = $thematicRepository->findBy([], ['name' => 'ASC']);
        $videoCounts = [];
        $subjectCounts = [];
        foreach ($thematics as $thematic) {
            $videoCounts[$thematic->getId()] = $videoRepository->countByThematic($thematic);
            $subjectCounts[$thematic->getId()] = $subjectRepository->count(['thematic' => $thematic]);
        }

        return $this->render('admin/thematic/index.html.twig', [
            'thematics' => $thematics,
            'videoCounts' => $videoCounts,
            'subjectCounts' => $subjectCounts,
        ]);
    }

    #[Route('/actions-groupees', name: 'admin_thematic_bulk', methods: ['POST'])]
    public function bulk(Request $request, ThematicRepository $thematicRepository, SubjectRepository $subjectRepository, EntityManagerInterface $entityManager): Response
    {
        $ids = $this->bulkIds($request, 'bulk-thematic');
        if ($ids === null) {
            return $this->redirectToRoute('admin_thematic_index');
        }

        if ($request->request->getString('action') !== 'delete') {
            $this->unknownBulkAction();

            return $this->redirectToRoute('admin_thematic_index');
        }

        $deleted = 0;
        $skipped = [];
        foreach ($thematicRepository->findBy(['id' => $ids]) as $thematic) {
            if ($subjectRepository->count(['thematic' => $thematic]) > 0) {
                $skipped[] = $thematic->getName() . ' (encore utilisée par des sujets)';

                continue;
            }
            $entityManager->remove($thematic);
            ++$deleted;
        }
        $entityManager->flush();

        $this->bulkReport(self::plural($deleted, 'thématique supprimée', 'thématiques supprimées') . '.', $skipped);

        return $this->redirectToRoute('admin_thematic_index');
    }

    #[Route('/nouveau', name: 'admin_thematic_new')]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        $thematic = new Thematic();
        $form = $this->createForm(ThematicType::class, $thematic);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($thematic);
            $entityManager->flush();

            $this->addFlash('success', 'Thématique créée.');

            return $this->redirectToRoute('admin_thematic_index');
        }

        return $this->render('admin/thematic/form.html.twig', [
            'form' => $form,
            'thematic' => $thematic,
        ]);
    }

    #[Route('/{id}/modifier', name: 'admin_thematic_edit')]
    public function edit(Thematic $thematic, Request $request, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createForm(ThematicType::class, $thematic);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();

            $this->addFlash('success', 'Thématique mise à jour.');

            return $this->redirectToRoute('admin_thematic_index');
        }

        return $this->render('admin/thematic/form.html.twig', [
            'form' => $form,
            'thematic' => $thematic,
        ]);
    }

    #[Route('/{id}/supprimer', name: 'admin_thematic_delete', methods: ['POST'])]
    public function delete(Thematic $thematic, Request $request, EntityManagerInterface $entityManager, SubjectRepository $subjectRepository): Response
    {
        if (!$this->isCsrfTokenValid('delete-thematic-' . $thematic->getId(), $request->request->get('_token'))) {
            return $this->redirectToRoute('admin_thematic_index');
        }

        // Un sujet exige une thématique (colonne non nullable) : il faut
        // donc bloquer dès qu'un sujet existe, même sans contenu.
        if ($subjectRepository->count(['thematic' => $thematic]) > 0) {
            $this->addFlash('error', 'Impossible de supprimer une thématique encore utilisée par des sujets.');

            return $this->redirectToRoute('admin_thematic_index');
        }

        $entityManager->remove($thematic);
        $entityManager->flush();

        $this->addFlash('success', 'Thématique supprimée.');

        return $this->redirectToRoute('admin_thematic_index');
    }
}
