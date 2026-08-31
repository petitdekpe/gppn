<?php

namespace App\Command;

use App\Message\ProcessVideoMessage;
use App\Repository\VideoRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'app:video:reprocess',
    description: 'Redéclenche le pipeline de traitement (hash, vignette, HLS) pour une capsule vidéo existante.',
)]
final class ReprocessVideoCommand extends Command
{
    public function __construct(
        private readonly VideoRepository $videoRepository,
        private readonly MessageBusInterface $messageBus,
    ) {
        parent::__construct();
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Identifiant de la vidéo à retraiter')]
        ?int $id = null,
    ): int {
        if ($id === null) {
            $io->error('Merci de préciser --id=<identifiant de la vidéo>.');

            return Command::INVALID;
        }

        $video = $this->videoRepository->find($id);

        if ($video === null) {
            $io->error(sprintf('Aucune vidéo avec l’identifiant %d.', $id));

            return Command::FAILURE;
        }

        $this->messageBus->dispatch(new ProcessVideoMessage($video->getId()));

        $io->success(sprintf('Message de retraitement dispatché pour « %s » (id %d).', $video->getTitle(), $video->getId()));

        return Command::SUCCESS;
    }
}
