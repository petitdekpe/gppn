<?php

namespace App\Command;

use App\Entity\Speaker;
use App\Repository\SpeakerRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Le code de fichier d'un ministre conseiller est « MCC » + sigle
 * (Speaker::getFileCode). Un sigle saisi avec son préfixe (« MCCMFAS »)
 * donnait donc « MCCMCCMFAS », que l'import en masse ne reconnaissait
 * qu'approximativement. La commande liste ces sigles et, avec --apply,
 * retire le préfixe.
 */
#[AsCommand(
    name: 'app:speaker:fix-mcc',
    description: 'Vérifie et corrige les sigles de ministres conseillers saisis avec le préfixe MCC (code de fichier doublé MCCMCC…).',
)]
final class FixCouncillorSiglesCommand extends Command
{
    public function __construct(
        private readonly SpeakerRepository $speakerRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Enregistrer les corrections (sans cette option, simple vérification)')]
        bool $apply = false,
    ): int {
        $toFix = [];
        $toCheck = [];
        foreach ($this->speakerRepository->findBy([], ['fullName' => 'ASC']) as $speaker) {
            $sigle = (string) $speaker->getSigle();
            if (!str_starts_with(strtoupper($sigle), Speaker::COUNCILLOR_PREFIX)) {
                continue;
            }
            if (Speaker::withoutCouncillorPrefix($sigle) === $sigle) {
                continue; // « MCC » seul, ou presque : un vrai sigle, pas un préfixe.
            }

            // Code tel qu'il était calculé avant correction : préfixe ajouté sans condition.
            $row = [
                $speaker->getFullName(),
                $speaker->getGovernment()?->getLabel() ?? 'Hors gouvernement',
                $sigle,
                ($speaker->isMinistreConseiller() ? Speaker::COUNCILLOR_PREFIX : '') . $sigle,
            ];
            if ($speaker->isMinistreConseiller()) {
                $toFix[] = [$speaker, [...$row, Speaker::withoutCouncillorPrefix($sigle), Speaker::COUNCILLOR_PREFIX . Speaker::withoutCouncillorPrefix($sigle)]];
            } else {
                $toCheck[] = [...$row, (string) $speaker->getRole() ?: '—'];
            }
        }

        if ($toFix === [] && $toCheck === []) {
            $io->success('Aucun sigle ne commence par MCC : aucun code de fichier doublé.');

            return Command::SUCCESS;
        }

        if ($toFix !== []) {
            $io->section(sprintf('%d ministre(s) conseiller(s) avec un code doublé', count($toFix)));
            $io->table(['Intervenant', 'Gouvernement', 'Sigle actuel', 'Code actuel', 'Sigle corrigé', 'Code corrigé'], array_column($toFix, 1));
        }

        if ($toCheck !== []) {
            // Sigle en MCC… sans « Conseiller » dans la fonction : fonction
            // incomplète, ou vrai sigle de ministère. À trancher à la main.
            $io->section(sprintf('%d intervenant(s) à vérifier à la main (non modifiés)', count($toCheck)));
            $io->table(['Intervenant', 'Gouvernement', 'Sigle', 'Code', 'Fonction'], $toCheck);
            $io->note('Leur fonction ne contient pas « Conseiller » : s’il s’agit de ministres conseillers, complétez la fonction puis relancez la commande ; sinon le sigle est peut-être correct.');
        }

        $conflicts = $this->conflicts(array_column($toFix, 0));
        if ($conflicts !== []) {
            $io->warning(array_merge(['Après correction, ces codes seraient partagés dans un même gouvernement (l’import en masse demandera de choisir) :'], $conflicts));
        }

        if ($toFix === []) {
            return Command::SUCCESS;
        }

        if (!$apply) {
            $io->note(sprintf('Simple vérification : rien n’a été modifié. Relancez avec --apply pour corriger les %d sigle(s).', count($toFix)));

            return Command::SUCCESS;
        }

        foreach (array_column($toFix, 0) as $speaker) {
            $speaker->normalizeSigle();
        }
        $this->entityManager->flush();
        $io->success(sprintf('%d sigle(s) corrigé(s).', count($toFix)));

        return Command::SUCCESS;
    }

    /**
     * Codes qui, une fois corrigés, en rejoindraient un autre dans le même gouvernement.
     *
     * @param list<Speaker> $fixed
     *
     * @return list<string>
     */
    private function conflicts(array $fixed): array
    {
        $fixedIds = array_map(static fn (Speaker $s) => $s->getId(), $fixed);
        $codes = [];
        foreach ($this->speakerRepository->findAll() as $speaker) {
            $sigle = $speaker->getSigle();
            if ($sigle === null) {
                continue;
            }
            $code = in_array($speaker->getId(), $fixedIds, true)
                ? Speaker::COUNCILLOR_PREFIX . Speaker::withoutCouncillorPrefix($sigle)
                : $speaker->getFileCode();
            $codes[($speaker->getGovernment()?->getId() ?? 0) . '|' . $code][] = $speaker->getFullName();
        }

        $conflicts = [];
        foreach ($codes as $key => $names) {
            if (count($names) > 1 && array_intersect($names, array_map(static fn (Speaker $s) => $s->getFullName(), $fixed)) !== []) {
                $conflicts[] = sprintf('%s : %s', explode('|', $key, 2)[1], implode(', ', $names));
            }
        }

        return $conflicts;
    }
}
