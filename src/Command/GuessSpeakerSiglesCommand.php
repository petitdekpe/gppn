<?php

namespace App\Command;

use App\Repository\SpeakerRepository;
use App\Service\SpeakerSigleGuesser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:speaker:guess-sigles',
    description: 'Devine le sigle des intervenants dont le champ est vide, à partir de leur fonction.',
)]
final class GuessSpeakerSiglesCommand extends Command
{
    public function __construct(
        private readonly SpeakerRepository $speakerRepository,
        private readonly SpeakerSigleGuesser $sigleGuesser,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Recalcule aussi les sigles déjà renseignés (écrase la valeur actuelle).')]
        bool $force = false,
    ): int {
        $speakers = $this->speakerRepository->findBy([], ['fullName' => 'ASC']);

        $rows = [];
        foreach ($speakers as $speaker) {
            if ($speaker->getSigle() !== null && !$force) {
                continue;
            }

            $guess = $this->sigleGuesser->guess($speaker->getRole());
            if ($guess === null) {
                continue;
            }

            $rows[] = [$speaker->getFullName(), $speaker->getSigle() ?? '—', $guess];
            $speaker->setSigle($guess);
        }

        if ($rows === []) {
            $io->success('Rien à faire : aucun sigle à deviner.');

            return Command::SUCCESS;
        }

        $this->entityManager->flush();

        $io->table(['Intervenant', 'Avant', 'Après'], $rows);
        $io->success(sprintf('%d sigle(s) mis à jour.', count($rows)));

        return Command::SUCCESS;
    }
}
