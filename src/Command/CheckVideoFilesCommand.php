<?php

namespace App\Command;

use App\Entity\VideoFile;
use App\Repository\VideoFileRepository;
use App\Service\VideoFileChecker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Vérifie avec ffmpeg que les vidéos se lisent et masque du site public
 * celles qui sont illisibles (voir VideoFileChecker). Les nouveaux envois
 * sont vérifiés d'office ; cette commande sert pour les fichiers déposés
 * avant, ou pour relancer une vérification complète.
 */
#[AsCommand(
    name: 'app:video:check-files',
    description: 'Vérifie que les vidéos se lisent et masque du site celles qui sont défectueuses.',
)]
final class CheckVideoFilesCommand extends Command
{
    public function __construct(
        private readonly VideoFileRepository $videoFileRepository,
        private readonly VideoFileChecker $checker,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Revérifier aussi les fichiers déjà vérifiés')]
        bool $all = false,
    ): int {
        $files = array_filter(
            $this->videoFileRepository->findBy([], ['id' => 'ASC']),
            fn (VideoFile $file) => $this->checker->supports($file) && ($all || $file->getCheckedAt() === null),
        );
        if ($files === []) {
            $io->success('Aucune vidéo à vérifier.');

            return Command::SUCCESS;
        }

        $defective = [];
        foreach ($io->progressIterate($files) as $file) {
            if ($this->checker->check($file)) {
                $defective[] = [$file->getId(), $file->getVideo()?->getTitle(), $file->getType()->getLabel(), $file->getDefectReason()];
            }
            $this->entityManager->flush();
        }

        if ($defective === []) {
            $io->success(sprintf('%d vidéo(s) vérifiée(s), aucune défectueuse.', \count($files)));

            return Command::SUCCESS;
        }

        $io->table(['Fichier', 'Contenu', 'Format', 'Problème'], $defective);
        $io->warning(sprintf('%d vidéo(s) défectueuse(s) sur %d, masquée(s) du site jusqu’à leur remplacement.', \count($defective), \count($files)));

        return Command::SUCCESS;
    }
}
