<?php

namespace App\Command;

use App\Exception\CoverGenerationException;
use App\Repository\VideoRepository;
use App\Service\VideoCoverGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:video:generate-covers',
    description: 'Tire de leur vidéo (TV, à défaut Mobile) la couverture des contenus qui n’en ont pas.',
    help: <<<'HELP'
        Par défaut : contenus sans couverture, depuis la vidéo TV (à défaut la vidéo Mobile).

          <info>--mobile</info>     depuis la vidéo Mobile, même s'il y a une vidéo TV ; les contenus
                       sans vidéo Mobile sont laissés de côté
          <info>--remplacer</info>  tous les contenus, y compris ceux qui ont déjà une couverture,
                       générée ou déposée à la main (confirmation demandée)

        Exemple : <info>php bin/console app:video:generate-covers --mobile --remplacer</info>
        HELP,
)]
final class GenerateCoversCommand extends Command
{
    public function __construct(
        private readonly VideoRepository $videoRepository,
        private readonly VideoCoverGenerator $coverGenerator,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Seconde de la vidéo à capturer (par défaut : 15 s, image la plus représentative des 2 s suivantes)')]
        ?int $seconde = null,
        #[Option(description: 'Tirer la couverture de la vidéo Mobile, même s’il y a une vidéo TV')]
        bool $mobile = false,
        #[Option(description: 'Remplacer aussi les couvertures existantes, y compris celles déposées à la main')]
        bool $remplacer = false,
    ): int {
        $qb = $this->videoRepository->createQueryBuilder('v')->select('v.id')->orderBy('v.id');
        if (!$remplacer) {
            $qb->where('v.coverImageName IS NULL');
        }
        $ids = array_column($qb->getQuery()->getArrayResult(), 'id');

        if ($remplacer) {
            // Une couverture déposée à la main est supprimée avec son fichier : on prévient.
            $manual = (int) $this->videoRepository->createQueryBuilder('v')->select('COUNT(v.id)')
                ->where('v.coverImageName IS NOT NULL')->andWhere('v.coverGenerated = false')
                ->getQuery()->getSingleScalarResult();
            if ($manual > 0 && !$io->confirm(sprintf('%d couverture(s) déposée(s) à la main seront remplacées et leur fichier supprimé. Continuer ?', $manual), false)) {
                $io->note('Aucune couverture modifiée.');

                return Command::SUCCESS;
            }
        }

        $generated = 0;
        $withoutSource = 0;
        $failures = [];
        foreach ($io->progressIterate($ids) as $id) {
            $video = $this->videoRepository->find($id);
            $source = $mobile ? VideoCoverGenerator::mobileSource($video) : VideoCoverGenerator::source($video);
            if ($source === null) {
                ++$withoutSource;
            } else {
                try {
                    $this->coverGenerator->generate($video, $seconde, $mobile);
                    ++$generated;
                } catch (CoverGenerationException $e) {
                    $failures[] = sprintf('#%d %s : %s', $video->getId(), $video->getTitle(), $e->getMessage());
                }
            }
            // Lot de plusieurs centaines de contenus : on ne garde rien en mémoire.
            $this->entityManager->clear();
        }

        $io->success(sprintf(
            '%d couverture(s) générée(s) ; %d contenu(s) sans %s laissé(s) de côté.',
            $generated,
            $withoutSource,
            $mobile ? 'vidéo Mobile' : 'vidéo TV ni Mobile',
        ));
        if ($failures !== []) {
            $io->warning(sprintf('%d échec(s) :', count($failures)));
            $io->listing($failures);

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
