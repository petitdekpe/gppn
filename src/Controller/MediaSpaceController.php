<?php

namespace App\Controller;

use App\Repository\ThematicRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class MediaSpaceController extends AbstractController
{
    #[Route('/espace-media', name: 'app_media_space')]
    public function index(ThematicRepository $thematicRepository): Response
    {
        return $this->render('media_space/index.html.twig', [
            'thematics' => $thematicRepository->findAllWithVideoCount(),
        ]);
    }
}
