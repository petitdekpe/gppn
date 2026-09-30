<?php

namespace App\Repository;

use App\Entity\Government;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Government>
 */
class GovernmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Government::class);
    }

    /**
     * Gouvernement actuel d'abord, puis du plus récent au plus ancien.
     *
     * @return list<Government>
     */
    public function findOrdered(): array
    {
        return $this->createQueryBuilder('g')
            ->orderBy('g.current', 'DESC')
            ->addOrderBy('CASE WHEN g.startedAt IS NULL THEN 1 ELSE 0 END', 'ASC')
            ->addOrderBy('g.startedAt', 'DESC')
            ->addOrderBy('g.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findCurrent(): ?Government
    {
        return $this->findOneBy(['current' => true]);
    }

    /**
     * Gouvernement en place à une date (le dernier entré en fonction avant
     * elle), à défaut le gouvernement actuel.
     */
    public function findInOfficeAt(\DateTimeImmutable $date): ?Government
    {
        return $this->createQueryBuilder('g')
            ->where('g.startedAt IS NOT NULL AND g.startedAt <= :date')
            ->setParameter('date', $date, 'date_immutable')
            ->orderBy('g.startedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult() ?? $this->findCurrent();
    }

    /** Désigne le gouvernement actuel ; les autres cessent de l'être. */
    public function makeCurrent(Government $government): void
    {
        foreach ($this->findBy(['current' => true]) as $other) {
            $other->setCurrent(false);
        }
        $government->setCurrent(true);
    }
}
