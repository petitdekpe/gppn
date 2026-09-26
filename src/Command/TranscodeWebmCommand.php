<?php

namespace App\Command;

use App\Enum\WebmStatus;
use App\Message\TranscodeToWebm;
use App\Repository\VideoFileRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'app:video:transcode-webm',
    description: 'Met en file la conversion WebM de lecture des vidéos TV/Mobile qui n’en ont pas encore (ou de toutes avec --all).',
)]
final class TranscodeWebmCommand extends Command
{
    public function __construct(
        private readonly VideoFileRepository $videoFileRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $bus,
    ) {
        parent::__construct();
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Reconvertir aussi les vidéos dont la version WebM est déjà prête')]
        bool $all = false,
    ): int {
        $queued = 0;
        foreach ($this->videoFileRepository->findAll() as $file) {
            if ($file->getFileName() === null || !$file->getType()->hasWebmPlayback()) {
                continue;
            }
            if (!$all && in_array($file->getWebmStatus(), [WebmStatus::READY, WebmStatus::PENDING, WebmStatus::PROCESSING], true)) {
                continue;
            }

            $file->setWebmStatus(WebmStatus::PENDING);
            $this->entityManager->flush();
            $this->bus->dispatch(new TranscodeToWebm($file->getId()));
            ++$queued;
        }

        $io->success(sprintf('%d conversion(s) mise(s) en file. Lancez « messenger:consume transcode » pour les traiter.', $queued));

        return Command::SUCCESS;
    }
}
