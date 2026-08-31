<?php

namespace App\Repository;

use App\Entity\Language;
use App\Entity\Video;
use App\Enum\VideoStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Language>
 */
class LanguageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Language::class);
    }

    /**
     * @return array<int, array{language: Language, videoCount: int}>
     */
    public function findAllWithVideoCount(): array
    {
        return $this->createQueryBuilder('l')
            ->select('l AS language', 'COUNT(v.id) AS videoCount')
            ->leftJoin(Video::class, 'v', 'WITH', 'v.language = l AND v.status = :status')
            ->groupBy('l.id')
            ->orderBy('l.name', 'ASC')
            ->setParameter('status', VideoStatus::PUBLIE)
            ->getQuery()
            ->getResult();
    }
}
