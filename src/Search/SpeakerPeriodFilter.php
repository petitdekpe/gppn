<?php

namespace App\Search;

use Doctrine\ORM\QueryBuilder;

/**
 * Filtres facultatifs « Intervenant » et « Période » (page Vidéos, espace
 * média). Un intervenant reconduit a une fiche par gouvernement : le filtre
 * porte sur la personne, donc sur toutes ses fiches. La période porte sur la
 * date du conseil des ministres, bornes comprises.
 *
 * Construit par SpeakerPeriodCriteria à partir des paramètres de la requête.
 */
final class SpeakerPeriodFilter
{
    /**
     * @param list<int> $speakerIds fiches de la personne choisie
     */
    public function __construct(
        public readonly ?string $person = null,
        public readonly ?string $personName = null,
        public readonly array $speakerIds = [],
        public readonly ?\DateTimeImmutable $from = null,
        public readonly ?\DateTimeImmutable $to = null,
    ) {
    }

    public function hasPerson(): bool
    {
        return $this->speakerIds !== [];
    }

    public function hasPeriod(): bool
    {
        return $this->from !== null || $this->to !== null;
    }

    public function isActive(): bool
    {
        return $this->hasPerson() || $this->hasPeriod();
    }

    /**
     * Restreint une requête dont l'alias `$video` désigne un contenu. La
     * période passe par une sous-requête : aucune jointure n'est exigée de
     * la requête d'origine.
     */
    public function apply(QueryBuilder $qb, string $video = 'v'): QueryBuilder
    {
        if ($this->hasPerson()) {
            $qb->andWhere(sprintf('%s.speaker IN (:spf_speakers)', $video))->setParameter('spf_speakers', $this->speakerIds);
        }
        if ($this->hasPeriod()) {
            $conditions = [];
            if ($this->from !== null) {
                $conditions[] = 'spf_c.date >= :spf_from';
                $qb->setParameter('spf_from', $this->from, 'date_immutable');
            }
            if ($this->to !== null) {
                $conditions[] = 'spf_c.date <= :spf_to';
                $qb->setParameter('spf_to', $this->to, 'date_immutable');
            }
            $qb->andWhere(sprintf(
                '%s.subject IN (SELECT spf_s FROM App\Entity\Subject spf_s JOIN spf_s.councilSession spf_c WHERE %s)',
                $video,
                implode(' AND ', $conditions),
            ));
        }

        return $qb;
    }

    /**
     * Paramètres d'URL à reporter (pagination, onglets, formulaire de téléchargement).
     *
     * @return array<string, string>
     */
    public function queryParams(): array
    {
        return array_filter([
            'intervenant' => $this->hasPerson() ? $this->person : null,
            'du' => $this->from?->format('Y-m-d'),
            'au' => $this->to?->format('Y-m-d'),
        ]);
    }

    public function periodLabel(): ?string
    {
        return match (true) {
            $this->from !== null && $this->to !== null => sprintf('du %s au %s', $this->from->format('d/m/Y'), $this->to->format('d/m/Y')),
            $this->from !== null => sprintf('depuis le %s', $this->from->format('d/m/Y')),
            $this->to !== null => sprintf('jusqu’au %s', $this->to->format('d/m/Y')),
            default => null,
        };
    }
}
