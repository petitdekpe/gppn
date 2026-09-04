<?php

namespace App\Repository;

use App\Entity\CouncilSession;
use App\Entity\Subject;
use App\Entity\Video;
use App\Enum\VideoStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CouncilSession>
 */
class CouncilSessionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CouncilSession::class);
    }

    public function findOneBySlug(string $slug): ?CouncilSession
    {
        return $this->findOneBy(['slug' => $slug]);
    }

    /**
     * @return array<int, array{councilSession: CouncilSession, videoCount: int}>
     */
    public function findAllWithVideoCount(): array
    {
        return $this->createQueryBuilder('cs')
            ->select('cs AS councilSession', 'COUNT(v.id) AS videoCount')
            ->leftJoin(Subject::class, 's', 'WITH', 's.councilSession = cs')
            ->leftJoin(Video::class, 'v', 'WITH', 'v.subject = s AND v.status = :status')
            ->groupBy('cs.id')
            ->orderBy('cs.date', 'DESC')
            ->setParameter('status', VideoStatus::PUBLIE)
            ->getQuery()
            ->getResult();
    }
}
