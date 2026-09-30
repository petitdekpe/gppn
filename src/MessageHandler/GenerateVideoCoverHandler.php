<?php

namespace App\MessageHandler;

use App\Entity\Video;
use App\Exception\CoverGenerationException;
use App\Message\GenerateVideoCover;
use App\Service\VideoCoverGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class GenerateVideoCoverHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly VideoCoverGenerator $coverGenerator,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(GenerateVideoCover $message): void
    {
        $video = $this->entityManager->find(Video::class, $message->videoId);

        // Vérifié ici, pas à l'envoi : une couverture a pu être déposée à la
        // main entre-temps, ou la vidéo TV retirée.
        if ($video === null || !$this->coverGenerator->hasSource($video) || (!$message->force && !$this->coverGenerator->canReplaceAutomatically($video))) {
            return;
        }

        try {
            $this->coverGenerator->generate($video);
        } catch (CoverGenerationException $e) {
            // Pas de nouvel essai : le bouton de l'administration permet de réessayer à une autre seconde.
            $this->logger->warning('Couverture non générée pour le contenu {id} : {error}', ['id' => $video->getId(), 'error' => $e->getMessage()]);
        }
    }
}
