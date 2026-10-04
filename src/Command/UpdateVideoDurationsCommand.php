<?php

namespace App\Command;

use App\Repository\VideoRepository;
use App\Service\VideoDurationProbe;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Relit avec ffmpeg la durée de tous les contenus (voir VideoDurationProbe).
 * Les nouveaux envois sont traités d'office ; cette commande sert pour les
 * contenus déposés avant, dont la durée avait été saisie à la main.
 */
#[AsCommand(
    name: 'app:video:update-durations',
    description: 'Relit la durée de chaque contenu dans ses fichiers vidéo ou audio.',
)]
final class UpdateVideoDurationsCommand extends Command
{
    public function __construct(
        private readonly VideoRepository $videoRepository,
        private readonly VideoDurationProbe $probe,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $videos = $this->videoRepository->findBy([], ['id' => 'ASC']);

        $unreadable = [];
        foreach ($io->progressIterate($videos) as $video) {
            $seconds = $this->probe->durationOf($video);
            if ($seconds === null) {
                $unreadable[] = [$video->getId(), $video->getTitle()];
                continue;
            }
            $video->setDurationSeconds($seconds);
            $this->entityManager->flush();
        }

        if ($unreadable !== []) {
            $io->table(['Contenu', 'Titre'], $unreadable);
            $io->warning(sprintf('%d contenu(s) sans fichier vidéo ou audio lisible : durée inchangée.', \count($unreadable)));
        }
        $io->success(sprintf('%d durée(s) mise(s) à jour.', \count($videos) - \count($unreadable)));

        return Command::SUCCESS;
    }
}
