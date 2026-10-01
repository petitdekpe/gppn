<?php

namespace App\MessageHandler;

use App\Entity\VideoFile;
use App\Message\CheckVideoFile;
use App\Service\VideoFileChecker;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class CheckVideoFileHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly VideoFileChecker $checker,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(CheckVideoFile $message): void
    {
        $file = $this->entityManager->find(VideoFile::class, $message->videoFileId);
        if ($file === null || !$this->checker->supports($file)) {
            return;
        }

        if ($this->checker->check($file)) {
            $this->logger->warning('Fichier {id} défectueux, masqué du site : {reason}', ['id' => $file->getId(), 'reason' => $file->getDefectReason()]);
        }
        $this->entityManager->flush();
    }
}
