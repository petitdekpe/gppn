<?php

namespace App\Repository;

use App\Entity\Subject;
use App\Entity\Video;
use App\Enum\VideoStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Subject>
 */
class SubjectRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Subject::class);
    }

    /**
     * Sujets ayant au moins un contenu publié — utilisé par le constructeur
     * de lot de l'espace média (étape « choisir les sujets »), qui ne doit
     * pas proposer de sujet sans rien à télécharger derrière.
     *
     * @return array<int, array{subject: Subject, videoCount: int}>
     */
    public function findAllWithVideoCount(): array
    {
        return $this->createQueryBuilder('s')
            ->select('s AS subject', 'COUNT(v.id) AS videoCount')
            ->addSelect('t', 'cs')
            ->innerJoin('s.thematic', 't')
            ->innerJoin('s.councilSession', 'cs')
            ->innerJoin(Video::class, 'v', 'WITH', 'v.subject = s AND v.status = :status')
            ->groupBy('s.id')
            ->orderBy('cs.date', 'DESC')
            ->addOrderBy('s.title', 'ASC')
            ->setParameter('status', VideoStatus::PUBLIE)
            ->getQuery()
            ->getResult();
    }
}
