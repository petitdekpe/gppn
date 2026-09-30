<?php

namespace App\Service;

use App\Entity\Government;
use App\Entity\Speaker;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Import en masse d'intervenants, une ligne par personne :
 *   Nom complet ; Fonction ; Sigle
 * Colonnes séparées par une tabulation (copier-coller depuis Excel), un
 * point-virgule ou une virgule (CSV). Fonction et sigle sont facultatifs ;
 * un sigle absent est deviné à partir de la fonction.
 *
 * Une personne déjà présente dans le gouvernement visé (même nom, à
 * l'accent et à l'ordre des mots près) est mise à jour, pas dupliquée.
 */
class SpeakerImporter
{
    public const CREATE = 'create';
    public const UPDATE = 'update';
    public const UNCHANGED = 'unchanged';
    public const ERROR = 'error';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SpeakerSigleGuesser $sigleGuesser,
    ) {
    }

    /**
     * Ce que l'import ferait, ligne par ligne, sans rien enregistrer.
     *
     * @return list<array{line: int, fullName: string, role: ?string, sigle: ?string, status: string, message: string, speaker: ?Speaker}>
     */
    public function plan(string $text, ?Government $government): array
    {
        $existing = [];
        foreach ($this->entityManager->getRepository(Speaker::class)->findBy(['government' => $government]) as $speaker) {
            $existing[Speaker::nameKey($speaker->getFullName())] = $speaker;
        }

        $plan = [];
        $seen = [];
        foreach ($this->parse($text) as $line => [$fullName, $role, $sigle]) {
            $row = ['line' => $line, 'fullName' => $fullName, 'role' => $role, 'sigle' => $sigle, 'status' => self::ERROR, 'message' => '', 'speaker' => null];
            $key = Speaker::nameKey($fullName);

            // Ministre conseiller dont le sigle arrive avec « MCC » : préfixe
            // retiré, sans quoi le code de fichier deviendrait MCCMCC…
            $note = '';
            $effectiveRole = $role ?? (isset($existing[$key]) ? $existing[$key]->getRole() : null);
            if ($sigle !== null && str_contains((string) $effectiveRole, 'Conseill') && Speaker::withoutCouncillorPrefix($sigle) !== $sigle) {
                $sigle = Speaker::withoutCouncillorPrefix($sigle);
                $row['sigle'] = $sigle;
                $note = ' Préfixe MCC retiré du sigle : il est ajouté automatiquement aux noms de fichiers.';
            }

            if ($key === '') {
                $row['message'] = 'Nom complet manquant.';
            } elseif (mb_strlen($fullName) > 150 || mb_strlen((string) $role) > 150) {
                $row['message'] = 'Nom ou fonction trop long (150 caractères au plus).';
            } elseif ($sigle !== null && mb_strlen($sigle) > 20) {
                $row['message'] = 'Sigle trop long (20 caractères au plus).';
            } elseif (isset($seen[$key])) {
                $row['message'] = sprintf('Déjà présent ligne %d : ligne ignorée.', $seen[$key]);
            } elseif (isset($existing[$key])) {
                $speaker = $existing[$key];
                $changes = array_filter([
                    $role !== null && $role !== $speaker->getRole() ? sprintf('fonction : « %s » → « %s »', $speaker->getRole() ?? '—', $role) : null,
                    $sigle !== null && $sigle !== $speaker->getSigle() ? sprintf('sigle : %s → %s', $speaker->getSigle() ?? '—', $sigle) : null,
                ]);
                $row['speaker'] = $speaker;
                $row['status'] = $changes === [] ? self::UNCHANGED : self::UPDATE;
                $row['message'] = $changes === [] ? 'Déjà présent, rien à changer.' : 'Mise à jour — ' . implode(' ; ', $changes) . '.';
            } else {
                $row['status'] = self::CREATE;
                $guessed = $sigle ?? $this->sigleGuesser->guess($role);
                $row['message'] = $sigle === null && $guessed !== null ? sprintf('Nouvel intervenant (sigle deviné : %s).', $guessed) : 'Nouvel intervenant.';
                $row['sigle'] = $guessed;
            }

            if ($key !== '') {
                $seen[$key] ??= $line;
            }
            if ($row['status'] !== self::ERROR) {
                $row['message'] .= $note;
            }
            $plan[] = $row;
        }

        return $plan;
    }

    /**
     * @param list<array{status: string, fullName: string, role: ?string, sigle: ?string, speaker: ?Speaker}> $plan
     *
     * @return array{created: int, updated: int}
     */
    public function apply(array $plan, ?Government $government): array
    {
        $created = $updated = 0;
        foreach ($plan as $row) {
            if ($row['status'] === self::CREATE) {
                $this->entityManager->persist((new Speaker())
                    ->setFullName($row['fullName'])
                    ->setRole($row['role'])
                    ->setSigle($row['sigle'])
                    ->setGovernment($government));
                ++$created;
            } elseif ($row['status'] === self::UPDATE) {
                $speaker = $row['speaker'];
                if ($row['role'] !== null) {
                    $speaker->setRole($row['role']);
                }
                if ($row['sigle'] !== null) {
                    $speaker->setSigle($row['sigle']);
                }
                ++$updated;
            }
        }
        $this->entityManager->flush();

        return ['created' => $created, 'updated' => $updated];
    }

    /**
     * @return array<int, array{string, ?string, ?string}> colonnes nettoyées, indexées par numéro de ligne
     */
    private function parse(string $text): array
    {
        $lines = preg_split('/\R/u', trim(preg_replace('/^\xEF\xBB\xBF/', '', $text)));
        $delimiter = $this->detectDelimiter($lines);

        $rows = [];
        foreach ($lines as $index => $line) {
            if (trim($line) === '') {
                continue;
            }
            $cells = array_map(static fn ($cell) => trim((string) $cell), str_getcsv($line, $delimiter, '"', ''));
            // Virgules dans une fonction non protégée par des guillemets
            // (« Ministre de …, chargé de … ») : on recolle le milieu, le
            // dernier morceau n'est un sigle que s'il en a l'allure.
            if ($delimiter === ',' && count($cells) > 3) {
                $last = end($cells);
                $isSigle = preg_match('/^[A-Z0-9]{2,12}$/', $last) === 1;
                $cells = [$cells[0], implode(', ', array_slice($cells, 1, $isSigle ? -1 : null)), $isSigle ? $last : ''];
            }
            // Ligne d'en-têtes d'un tableur (« Nom », « Fonction », « Sigle »).
            if ($index === 0 && preg_match('/^(nom|intervenant|nom complet)$/iu', $cells[0] ?? '') === 1) {
                continue;
            }
            $rows[$index + 1] = [
                preg_replace('/\s+/u', ' ', $cells[0] ?? ''),
                ($cells[1] ?? '') !== '' ? preg_replace('/\s+/u', ' ', $cells[1]) : null,
                ($cells[2] ?? '') !== '' ? strtoupper(preg_replace('/\s+/u', '', $cells[2])) : null,
            ];
        }

        return $rows;
    }

    /**
     * Tabulation (copie depuis un tableur), sinon point-virgule, sinon
     * virgule : le séparateur le plus présent sur les premières lignes.
     *
     * @param list<string> $lines
     */
    private function detectDelimiter(array $lines): string
    {
        $sample = implode("\n", array_slice($lines, 0, 10));
        foreach (["\t", ';'] as $delimiter) {
            if (str_contains($sample, $delimiter)) {
                return $delimiter;
            }
        }

        return ',';
    }
}
