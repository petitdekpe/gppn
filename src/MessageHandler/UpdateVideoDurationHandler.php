<?php

namespace App\MessageHandler;

use App\Entity\Video;
use App\Message\UpdateVideoDuration;
use App\Service\VideoDurationProbe;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class UpdateVideoDurationHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly VideoDurationProbe $probe,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(UpdateVideoDuration $message): void
    {
        $video = $this->entityManager->find(Video::class, $message->videoId);
        if ($video === null) {
            return;
        }

        // Illisible : on garde la durée connue plutôt que de l'effacer.
        $seconds = $this->probe->durationOf($video);
        if ($seconds === null) {
            $this->logger->warning('Durée illisible pour le contenu {id}.', ['id' => $video->getId()]);

            return;
        }

        $video->setDurationSeconds($seconds);
        $this->entityManager->flush();
    }
}
