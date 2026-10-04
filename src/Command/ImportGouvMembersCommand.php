<?php

namespace App\Command;

use App\Entity\Speaker;
use App\Repository\GovernmentRepository;
use App\Repository\SpeakerRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Vich\UploaderBundle\FileAbstraction\ReplacingFile;

/**
 * Membres du gouvernement publiés sur gouv.bj/membres, dans l'ordre de
 * préséance (nom, fonction, photo). Rapprochés des intervenants du
 * gouvernement actuel par leur nom (accents, casse et ordre des mots
 * indifférents, une ou deux lettres d'écart tolérées : « Adin Yaton » =
 * « Adin Yeton »), qui reçoivent leur rang et, s'ils n'en ont pas, leur photo.
 * Les ministres conseillers n'y figurent pas : rang et photo se saisissent dans l'admin.
 */
#[AsCommand(
    name: 'app:speakers:import-gouv',
    description: 'Importe de gouv.bj/membres l’ordre de préséance et la photo des ministres du gouvernement actuel.',
)]
final class ImportGouvMembersCommand extends Command
{
    private const SOURCE = 'https://www.gouv.bj/membres/';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly GovernmentRepository $governmentRepository,
        private readonly SpeakerRepository $speakerRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Remplace aussi les photos déjà présentes (déposées dans l’admin comprises)')]
        bool $force = false,
        #[Option(description: 'Affiche les correspondances sans rien enregistrer')]
        bool $dryRun = false,
    ): int {
        $government = $this->governmentRepository->findCurrent();
        if ($government === null) {
            $io->error('Aucun gouvernement actuel : rien à rapprocher.');

            return Command::FAILURE;
        }

        $members = $this->fetchMembers();
        if ($members === []) {
            $io->error('Aucun membre trouvé sur ' . self::SOURCE . ' : la page a peut-être changé de structure.');

            return Command::FAILURE;
        }

        $speakers = $this->speakerRepository->findBy(['government' => $government]);
        $rows = [];
        $photos = 0;
        $matched = [];
        foreach ($members as $index => $member) {
            $speaker = $this->match($member['name'], $speakers);
            if ($speaker === null) {
                continue;
            }
            $matched[$speaker->getId()] = true;
            // Rang sur la page, Président de la République compris (1) : seul l'ordre compte.
            $rank = $index + 1;
            $withPhoto = $speaker->getPhotoName() === null || $force;
            $rows[] = [$rank, $speaker->getFullName(), $member['name'], $withPhoto ? 'importée' : 'déjà présente'];
            if ($withPhoto) {
                ++$photos;
            }
            if (!$dryRun) {
                $speaker->setPrecedence($rank);
                $photoPath = $withPhoto ? $this->attach($speaker, $member['photo']) : null;
                $this->entityManager->flush();
                if ($photoPath !== null && is_file($photoPath)) {
                    unlink($photoPath);
                }
            }
        }

        if ($rows !== []) {
            $io->table(['Rang', 'Intervenant', 'Nom sur gouv.bj', 'Photo'], $rows);
        }
        $io->success(sprintf(
            '%d rang(s) et %d photo(s) %s (--force pour remplacer aussi les photos déjà présentes).',
            count($rows),
            $photos,
            $dryRun ? 'à importer' : 'importés',
        ));

        $missing = array_map(
            static fn (Speaker $s) => $s->getFullName(),
            array_filter($speakers, static fn (Speaker $s) => !isset($matched[$s->getId()])),
        );
        if ($missing !== []) {
            $io->note('Absents de gouv.bj, rang et photo à saisir dans l’admin :');
            $io->listing(array_values($missing));
        }

        return Command::SUCCESS;
    }

    /**
     * @return list<array{name: string, photo: string}>
     */
    private function fetchMembers(): array
    {
        $html = $this->httpClient->request('GET', self::SOURCE)->getContent();

        $document = new \DOMDocument();
        @$document->loadHTML('<?xml encoding="UTF-8">' . $html);
        $xpath = new \DOMXPath($document);

        // Une carte par ministre : photo puis nom en <h4> ; le Président en tête, en <h2>.
        $members = [];
        foreach ($xpath->query('//img[contains(@src, "/upload/thumbnails/structures/") or contains(@src, "/upload/thumbnails/members/")]') as $img) {
            $heading = $xpath->query('following::*[self::h2 or self::h4][1]', $img)->item(0);
            if ($heading === null) {
                continue;
            }
            $members[] = [
                'name' => trim(html_entity_decode($heading->textContent, \ENT_QUOTES | \ENT_HTML5, 'UTF-8')),
                'photo' => 'https://www.gouv.bj' . $img->getAttribute('src'),
            ];
        }

        return $members;
    }

    /**
     * @param list<Speaker> $speakers
     */
    private function match(string $name, array $speakers): ?Speaker
    {
        $key = Speaker::nameKey($name);
        $best = null;
        $bestDistance = 3; // au-delà de 2 lettres d'écart, ce n'est plus une coquille
        foreach ($speakers as $speaker) {
            $distance = levenshtein($key, Speaker::nameKey($speaker->getFullName()));
            if ($distance < $bestDistance) {
                $best = $speaker;
                $bestDistance = $distance;
            }
        }

        return $best;
    }

    /** Photo téléchargée dans un fichier temporaire, enregistrée par Vich au flush ; renvoie ce fichier. */
    private function attach(Speaker $speaker, string $url): string
    {
        $path = sprintf('%s/%s.jpg', sys_get_temp_dir(), $speaker->getPersonSlug());
        file_put_contents($path, $this->httpClient->request('GET', $url)->getContent());
        $speaker->setPhotoFile(new ReplacingFile($path));

        return $path;
    }
}
