<?php

namespace App\Repository;

use App\Entity\CouncilSession;
use App\Entity\Subject;
use App\Entity\Thematic;
use App\Entity\Video;
use App\Enum\VideoStatus;
use App\Doctrine\Filter\CapsuleFormatFilter;
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
            ->leftJoin(Video::class, 'v', 'WITH', 'v.subject = s AND v.status = :status' . $this->visibleVideoCondition())
            ->groupBy('cs.id')
            ->orderBy('cs.date', 'DESC')
            ->setParameter('status', VideoStatus::PUBLIE)
            ->getQuery()
            ->getResult();
    }

    /**
     * Conseils ayant au moins un contenu publié dans une thématique, du plus
     * récent au plus ancien (filtre de la page thématique).
     *
     * @return array<int, array{councilSession: CouncilSession, videoCount: int}>
     */
    public function findWithVideoCountForThematic(Thematic $thematic): array
    {
        return $this->createQueryBuilder('cs')
            ->select('cs AS councilSession', 'COUNT(v.id) AS videoCount')
            ->innerJoin(Subject::class, 's', 'WITH', 's.councilSession = cs AND s.thematic = :thematic')
            ->innerJoin(Video::class, 'v', 'WITH', 'v.subject = s AND v.status = :status' . $this->visibleVideoCondition())
            ->groupBy('cs.id')
            ->orderBy('cs.date', 'DESC')
            ->setParameter('thematic', $thematic)
            ->setParameter('status', VideoStatus::PUBLIE)
            ->getQuery()
            ->getResult();
    }

    /**
     * Conseils ayant au moins un sujet, du plus récent au plus ancien, pour
     * le calendrier du formulaire de contenu (count = nombre de sujets).
     *
     * @return list<array{id: int, date: string, count: int}>
     */
    public function findCalendarWithSubjectCount(): array
    {
        $rows = $this->createQueryBuilder('cs')
            ->select('cs.id', 'cs.date', 'COUNT(s.id) AS subjectCount')
            ->innerJoin(Subject::class, 's', 'WITH', 's.councilSession = cs')
            ->groupBy('cs.id')
            ->orderBy('cs.date', 'DESC')
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $row) => [
            'id' => (int) $row['id'],
            'date' => $row['date']->format('Y-m-d'),
            'count' => (int) $row['subjectCount'],
        ], $rows);
    }

    /**
     * Ne compte pas les contenus dont tous les fichiers sont d'un type
     * désactivé dans les Paramètres (voir CapsuleFormatFilter).
     */
    private function visibleVideoCondition(): string
    {
        $condition = CapsuleFormatFilter::visibleVideoCondition($this->getEntityManager(), 'v');

        return $condition !== null ? ' AND ' . $condition : '';
    }
}
