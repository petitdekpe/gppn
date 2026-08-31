<?php

namespace App\Repository;

use App\Entity\Thematic;
use App\Entity\Video;
use App\Enum\VideoStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Thematic>
 */
class ThematicRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Thematic::class);
    }

    /**
     * @return array<int, array{thematic: Thematic, videoCount: int}>
     */
    public function findAllWithVideoCount(): array
    {
        return $this->createQueryBuilder('t')
            ->select('t AS thematic', 'COUNT(v.id) AS videoCount')
            ->leftJoin(Video::class, 'v', 'WITH', 'v.thematic = t AND v.status = :status')
            ->groupBy('t.id')
            ->orderBy('t.name', 'ASC')
            ->setParameter('status', VideoStatus::PUBLIE)
            ->getQuery()
            ->getResult();
    }
}
