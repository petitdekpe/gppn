<?php

namespace App\Service;

use App\Entity\Speaker;
use App\Entity\Video;
use App\Enum\VideoStatus;
use App\Repository\GovernmentRepository;
use App\Repository\SpeakerRepository;
use App\Search\SpeakerPeriodFilter;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Données et lecture des filtres facultatifs « Intervenant » et « Période ».
 *
 * - Intervenant : une entrée par personne ayant des contenus publiés, toutes
 *   fiches confondues (un ministre reconduit a une fiche par gouvernement).
 *   Paramètre `intervenant` = nom normalisé (ex. « tognifode-veronique »).
 * - Période : paramètres `du` et `au` (AAAA-MM-JJ, chacun facultatif), ou
 *   `periode` = « du|au » pour les raccourcis (années, mandats).
 */
class SpeakerPeriodCriteria
{
    /** @var list<array{slug: string, name: string, role: ?string, councillor: bool, speakerIds: list<int>, videoCount: int}>|null */
    private ?array $people = null;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SpeakerRepository $speakerRepository,
        private readonly GovernmentRepository $governmentRepository,
    ) {
    }

    /**
     * @param array<string, mixed> $params paramètres de requête (GET ou POST)
     */
    public function fromParams(array $params): SpeakerPeriodFilter
    {
        $slug = is_string($params['intervenant'] ?? null) ? $params['intervenant'] : '';
        $person = null;
        foreach ($slug !== '' ? $this->people() : [] as $candidate) {
            if ($candidate['slug'] === $slug) {
                $person = $candidate;
                break;
            }
        }

        // Raccourci (« du|au ») ou « clear » : prime sur les champs de date.
        $shortcut = is_string($params['periode'] ?? null) ? $params['periode'] : null;
        [$from, $to] = match (true) {
            $shortcut === 'clear' => [null, null],
            $shortcut !== null => array_pad(explode('|', $shortcut, 2), 2, ''),
            default => [$params['du'] ?? '', $params['au'] ?? ''],
        };
        $from = $this->parseDate($from);
        $to = $this->parseDate($to);
        if ($from !== null && $to !== null && $from > $to) {
            [$from, $to] = [$to, $from];
        }

        return new SpeakerPeriodFilter($person['slug'] ?? null, $person['name'] ?? null, $person['speakerIds'] ?? [], $from, $to);
    }

    /**
     * Personnes ayant au moins un contenu publié, par ordre alphabétique.
     * Nom et fonction affichés : ceux de la fiche la plus récente
     * (gouvernement actuel d'abord).
     *
     * @return list<array{slug: string, name: string, role: ?string, councillor: bool, codes: list<string>, speakerIds: list<int>, videoCount: int}>
     */
    public function people(): array
    {
        if ($this->people !== null) {
            return $this->people;
        }

        $counts = array_map('intval', array_column($this->entityManager->createQueryBuilder()
            ->select('IDENTITY(v.speaker) AS speakerId', 'COUNT(v.id) AS videoCount')
            ->from(Video::class, 'v')
            ->where('v.status = :status AND v.speaker IS NOT NULL')
            ->setParameter('status', VideoStatus::PUBLIE)
            ->groupBy('v.speaker')
            ->getQuery()
            ->getArrayResult(), 'videoCount', 'speakerId'));

        $groups = [];
        foreach ($counts === [] ? [] : $this->speakerRepository->findBy(['id' => array_keys($counts)]) as $speaker) {
            $key = Speaker::nameKey($speaker->getFullName());
            $groups[$key][] = $speaker;
        }

        $people = [];
        foreach ($groups as $key => $speakers) {
            usort($speakers, fn (Speaker $a, Speaker $b) => $this->recency($b) <=> $this->recency($a));
            $latest = $speakers[0];
            $people[] = [
                'slug' => Speaker::slugForName($latest->getFullName()),
                'name' => $latest->getFullName(),
                'role' => $latest->getRole(),
                'councillor' => $latest->isMinistreConseiller(),
                // Sigles et codes de fichier de toutes ses fiches : recherche « MFAS », « MCCMFAS ».
                'codes' => array_values(array_unique(array_filter(array_merge(...array_map(static fn (Speaker $s) => [$s->getSigle(), $s->getFileCode()], $speakers))))),
                'speakerIds' => array_map(static fn (Speaker $s) => $s->getId(), $speakers),
                'videoCount' => array_sum(array_map(static fn (Speaker $s) => $counts[$s->getId()] ?? 0, $speakers)),
            ];
        }
        usort($people, static fn (array $a, array $b) => strcmp(Speaker::nameKey($a['name']), Speaker::nameKey($b['name'])));

        return $this->people = $people;
    }

    /**
     * Personne d'après son identifiant d'adresse (page intervenant).
     *
     * @return array{slug: string, name: string, role: ?string, councillor: bool, speakerIds: list<int>, videoCount: int}|null
     */
    public function person(string $slug): ?array
    {
        foreach ($this->people() as $person) {
            if ($person['slug'] === $slug) {
                return $person;
            }
        }

        return null;
    }

    /**
     * Personnes qui correspondent à un texte recherché : chaque mot tapé
     * commence un mot du nom (ordre, accents et casse indifférents :
     * « tognifode vero » trouve Véronique TOGNIFODÉ), ou le texte est un de
     * ses sigles. Les plus fournies d'abord.
     *
     * @return list<array{slug: string, name: string, role: ?string, councillor: bool, codes: list<string>, speakerIds: list<int>, videoCount: int}>
     */
    public function searchPeople(string $text, int $limit = 5): array
    {
        $words = array_filter(explode(' ', Speaker::nameKey($text)), static fn (string $word) => \strlen($word) >= 2);
        $code = strtoupper(preg_replace('/[^a-z0-9]/i', '', $text));
        if ($words === [] && \strlen($code) < 2) {
            return [];
        }

        $matches = array_filter($this->people(), static function (array $person) use ($words, $code): bool {
            if (\in_array($code, array_map('strtoupper', $person['codes']), true)) {
                return true;
            }
            if ($words === []) {
                return false;
            }
            $nameWords = explode(' ', Speaker::nameKey($person['name']));
            foreach ($words as $word) {
                if (array_filter($nameWords, static fn (string $nameWord) => str_starts_with($nameWord, $word)) === []) {
                    return false;
                }
            }

            return true;
        });
        usort($matches, static fn (array $a, array $b) => $b['videoCount'] <=> $a['videoCount']);

        return \array_slice($matches, 0, $limit);
    }

    /**
     * Raccourcis de période : mandats des gouvernements datés (jusqu'à
     * l'entrée en fonction du suivant), puis années ayant des contenus.
     *
     * @return list<array{label: string, value: string}>
     */
    public function periodShortcuts(): array
    {
        $shortcuts = [];

        $dated = array_values(array_filter($this->governmentRepository->findOrdered(), static fn ($g) => $g->getStartedAt() !== null));
        usort($dated, static fn ($a, $b) => $b->getStartedAt() <=> $a->getStartedAt());
        foreach ($dated as $index => $government) {
            $next = $dated[$index - 1] ?? null; // gouvernement suivant (liste du plus récent au plus ancien)
            $shortcuts[] = [
                'label' => $government->getLabel() . ($government->isCurrent() ? ' (actuel)' : ''),
                'value' => $government->getStartedAt()->format('Y-m-d') . '|' . ($next?->getStartedAt()->modify('-1 day')->format('Y-m-d') ?? ''),
            ];
        }

        $dates = array_column($this->entityManager->createQueryBuilder()
            ->select('DISTINCT c.date AS date')
            ->from(Video::class, 'v')
            ->innerJoin('v.subject', 's')
            ->innerJoin('s.councilSession', 'c')
            ->where('v.status = :status')
            ->setParameter('status', VideoStatus::PUBLIE)
            ->getQuery()
            ->getArrayResult(), 'date');
        $years = array_unique(array_map(static fn ($date) => $date instanceof \DateTimeInterface ? (int) $date->format('Y') : (int) substr((string) $date, 0, 4), $dates));
        rsort($years);
        foreach ($years as $year) {
            $shortcuts[] = ['label' => 'Année ' . $year, 'value' => sprintf('%d-01-01|%d-12-31', $year, $year)];
        }

        return $shortcuts;
    }

    /** Fiche du gouvernement actuel, puis la plus récemment entrée en fonction, puis la plus récemment créée. */
    private function recency(Speaker $speaker): array
    {
        $government = $speaker->getGovernment();

        return [$government?->isCurrent() ? 1 : 0, $government?->getStartedAt()?->format('Y-m-d') ?? '', $speaker->getId()];
    }

    private function parseDate(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value ? $date : null;
    }
}
