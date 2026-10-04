<?php

namespace App\Command;

use App\Repository\VideoRepository;
use App\Service\AppSettings;
use App\Service\CoverThumbnailer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:covers:thumbnails',
    description: 'Crée les vignettes WebP des cartes pour les couvertures qui n’en ont pas.',
)]
final class GenerateCoverThumbnailsCommand extends Command
{
    public function __construct(
        private readonly VideoRepository $videoRepository,
        private readonly AppSettings $settings,
        private readonly CoverThumbnailer $thumbnailer,
    ) {
        parent::__construct();
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Recréer aussi les vignettes existantes')]
        bool $force = false,
    ): int {
        $fileNames = array_map(
            static fn (array $row) => $row['coverImageName'],
            $this->videoRepository->createQueryBuilder('v')->select('v.coverImageName')->where('v.coverImageName IS NOT NULL')->orderBy('v.id')->getQuery()->getArrayResult(),
        );
        if ($this->settings->getDefaultCover() !== null) {
            $fileNames[] = $this->settings->getDefaultCover();
        }

        $generated = 0;
        $failures = [];
        foreach ($io->progressIterate(array_unique($fileNames)) as $fileName) {
            if (!$force && $this->thumbnailer->exists($fileName)) {
                continue;
            }
            if ($this->thumbnailer->generate($fileName)) {
                ++$generated;
            } else {
                $failures[] = $fileName;
            }
        }

        $io->success(sprintf('%d vignette(s) créée(s).', $generated));
        if ($failures !== []) {
            $io->warning(sprintf('%d couverture(s) illisible(s) ou introuvable(s) :', count($failures)));
            $io->listing($failures);

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
