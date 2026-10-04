<?php

namespace App\Command;

use App\Entity\Video;
use App\Entity\VideoFile;
use App\Enum\VideoFileType;
use App\Enum\VideoStatus;
use App\Repository\VideoRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:video:draft-without-mobile',
    description: 'Passe en brouillon les contenus (publiés ou masqués) qui n’ont pas de vidéo Mobile.',
    help: <<<'HELP'
        Liste les contenus publiés ou masqués sans vidéo Mobile déposée, puis les passe
        en brouillon après confirmation. Les numéros affichés permettent de les republier
        ensuite depuis l'administration.

          <info>--simulation</info>  affiche la liste sans rien modifier
        HELP,
)]
final class DraftWithoutMobileCommand extends Command
{
    public function __construct(
        private readonly VideoRepository $videoRepository,
    ) {
        parent::__construct();
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Afficher les contenus concernés sans les modifier')]
        bool $simulation = false,
    ): int {
        /** @var Video[] $videos */
        $videos = $this->videoRepository->createQueryBuilder('v')
            ->addSelect('l')
            ->innerJoin('v.language', 'l')
            ->where('v.status != :draft')
            ->andWhere(sprintf('NOT EXISTS (SELECT 1 FROM %s vf WHERE vf.video = v AND vf.type = :mobile AND vf.fileName IS NOT NULL)', VideoFile::class))
            ->setParameter('draft', VideoStatus::BROUILLON)
            ->setParameter('mobile', VideoFileType::MP4_VERTICAL)
            ->orderBy('v.id')
            ->getQuery()
            ->getResult();

        if ($videos === []) {
            $io->success('Tous les contenus publiés ou masqués ont une vidéo Mobile : rien à faire.');

            return Command::SUCCESS;
        }

        $io->table(['N°', 'Contenu', 'Langue', 'Statut actuel'], array_map(static fn (Video $video) => [
            $video->getId(),
            $video->getTitle(),
            $video->getLanguage()->getName(),
            $video->getStatus()->getLabel(),
        ], $videos));

        if ($simulation) {
            $io->note(sprintf('Simulation : %d contenu(s) seraient passés en brouillon. Aucun changement.', count($videos)));

            return Command::SUCCESS;
        }

        if (!$io->confirm(sprintf('Passer ces %d contenu(s) en brouillon ? Ils ne seront plus visibles sur le site.', count($videos)), false)) {
            $io->note('Aucun contenu modifié.');

            return Command::SUCCESS;
        }

        $updated = $this->videoRepository->createQueryBuilder('v')
            ->update()
            ->set('v.status', ':draft')
            ->where('v.id IN (:ids)')
            ->setParameter('draft', VideoStatus::BROUILLON)
            ->setParameter('ids', array_map(static fn (Video $video) => $video->getId(), $videos))
            ->getQuery()
            ->execute();

        $io->success(sprintf('%d contenu(s) passé(s) en brouillon (n° %s).', $updated, implode(', ', array_map(static fn (Video $video) => $video->getId(), $videos))));

        return Command::SUCCESS;
    }
}
