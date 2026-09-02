<?php

namespace App\Controller;

use App\Repository\CouncilSessionRepository;
use App\Repository\ThematicRepository;
use App\Repository\VideoFileRepository;
use App\Repository\VideoRepository;
use App\Service\VideoFileZipBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

class MediaSpaceController extends AbstractController
{
    #[Route('/espace-media', name: 'app_media_space')]
    public function index(ThematicRepository $thematicRepository, CouncilSessionRepository $councilSessionRepository): Response
    {
        return $this->render('media_space/index.html.twig', [
            'thematics' => $thematicRepository->findAllWithVideoCount(),
            'councilSessions' => $councilSessionRepository->findAllWithVideoCount(),
        ]);
    }

    #[Route('/espace-media/conseils/{slug}', name: 'app_media_space_council_show')]
    public function councilShow(string $slug, CouncilSessionRepository $councilSessionRepository, VideoRepository $videoRepository): Response
    {
        $councilSession = $councilSessionRepository->findOneBySlug($slug) ?? throw $this->createNotFoundException('Conseil des ministres introuvable.');

        return $this->render('media_space/council_show.html.twig', [
            'councilSession' => $councilSession,
            'videos' => $videoRepository->findPublishedByCouncilSession($councilSession),
        ]);
    }

    #[Route('/espace-media/conseils/{slug}/telecharger', name: 'app_media_space_council_download', methods: ['POST'])]
    public function councilDownload(
        string $slug,
        Request $request,
        CouncilSessionRepository $councilSessionRepository,
        VideoFileRepository $videoFileRepository,
        VideoFileZipBuilder $zipBuilder,
    ): Response {
        $councilSession = $councilSessionRepository->findOneBySlug($slug) ?? throw $this->createNotFoundException('Conseil des ministres introuvable.');

        if (!$this->isCsrfTokenValid('council_session_download_' . $councilSession->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $selectedIds = array_map('intval', $request->request->all('files'));
        $selectedFiles = $videoFileRepository->findSelectableForCouncilSession($councilSession, $selectedIds);

        if ($selectedFiles === []) {
            $this->addFlash('error', 'Merci de sélectionner au moins un fichier téléchargeable.');

            return $this->redirectToRoute('app_media_space_council_show', ['slug' => $slug]);
        }

        $zipPath = $zipBuilder->build($selectedFiles);

        $response = new BinaryFileResponse($zipPath);
        $response->deleteFileAfterSend(true);
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            sprintf('conseil-des-ministres-%s.zip', $councilSession->getDate()->format('Y-m-d')),
        );

        return $response;
    }
}
