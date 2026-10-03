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
    ): int {
        $ids = array_map(
            static fn (array $row) => $row['id'],
            $this->videoRepository->createQueryBuilder('v')->select('v.id')->where('v.coverImageName IS NULL')->orderBy('v.id')->getQuery()->getArrayResult(),
        );

        $generated = 0;
        $withoutSource = 0;
        $failures = [];
        foreach ($io->progressIterate($ids) as $id) {
            $video = $this->videoRepository->find($id);
            if (!$this->coverGenerator->hasSource($video)) {
                ++$withoutSource;
            } else {
                try {
                    $this->coverGenerator->generate($video, $seconde);
                    ++$generated;
                } catch (CoverGenerationException $e) {
                    $failures[] = sprintf('#%d %s : %s', $video->getId(), $video->getTitle(), $e->getMessage());
                }
            }
            // Lot de plusieurs centaines de contenus : on ne garde rien en mémoire.
            $this->entityManager->clear();
        }

        $io->success(sprintf('%d couverture(s) générée(s) ; %d contenu(s) sans vidéo TV ni Mobile laissé(s) de côté.', $generated, $withoutSource));
        if ($failures !== []) {
            $io->warning(sprintf('%d échec(s) :', count($failures)));
            $io->listing($failures);

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
