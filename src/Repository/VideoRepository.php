<?php

namespace App\Repository;

use App\Entity\Language;
use App\Entity\Thematic;
use App\Entity\Video;
use App\Enum\CapsuleFormat;
use App\Enum\VideoStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Video>
 */
class VideoRepository extends ServiceEntityRepository
{
    public const PER_PAGE = 12;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Video::class);
    }

    /**
     * Base des requêtes publiques : ne montre que les capsules publiées, une
     * capsule en brouillon/traitement/relecture ne doit pas fuiter côté site
     * public tant qu'elle n'a pas été validée.
     */
    private function baseQueryBuilder(): \Doctrine\ORM\QueryBuilder
    {
        return $this->createQueryBuilder('v')
            ->addSelect('t', 'l')
            ->innerJoin('v.thematic', 't')
            ->innerJoin('v.language', 'l')
            ->andWhere('v.status = :status')
            ->setParameter('status', VideoStatus::PUBLIE);
    }

    /**
     * `findOneBy(['slug' => ...])` ne filtre pas par statut : ce helper évite
     * qu'une capsule en brouillon/relecture reste accessible publiquement via
     * son URL directe.
     */
    public function findOneBySlug(string $slug): ?Video
    {
        return $this->baseQueryBuilder()
            ->andWhere('v.slug = :slug')
            ->setParameter('slug', $slug)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return Video[]
     */
    public function findLatest(int $limit = 8): array
    {
        return $this->baseQueryBuilder()
            ->orderBy('v.publishedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Video[]
     */
    public function findFeatured(int $limit = 3): array
    {
        return $this->baseQueryBuilder()
            ->andWhere('v.featured = true')
            ->orderBy('v.publishedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Video[]
     */
    public function findRelated(Video $video, int $limit = 3): array
    {
        return $this->baseQueryBuilder()
            ->andWhere('t = :thematic')
            ->andWhere('v.id != :id')
            ->setParameter('thematic', $video->getThematic())
            ->setParameter('id', $video->getId())
            ->orderBy('v.publishedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @param Thematic[] $thematics
     * @param Language[] $languages
     * @param CapsuleFormat[] $formats
     * @return array{videos: Video[], total: int, hasMore: bool, page: int}
     */
    public function search(array $thematics, array $languages, array $formats, ?string $query, int $page = 1, int $perPage = self::PER_PAGE): array
    {
        $page = max(1, $page);

        $qb = $this->baseQueryBuilder();

        if ($thematics !== []) {
            $qb->andWhere('t IN (:thematics)')->setParameter('thematics', $thematics);
        }

        if ($languages !== []) {
            $qb->andWhere('l IN (:languages)')->setParameter('languages', $languages);
        }

        if ($formats !== []) {
            $qb->andWhere('v.format IN (:formats)')->setParameter('formats', $formats);
        }

        if ($query !== null && $query !== '') {
            $qb->andWhere('v.title LIKE :query OR v.summary LIKE :query')
                ->setParameter('query', '%' . $query . '%');
        }

        $qb->orderBy('v.publishedAt', 'DESC');

        $countQb = clone $qb;
        $total = (int) $countQb->select('COUNT(DISTINCT v.id)')->getQuery()->getSingleScalarResult();

        $videos = $qb->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();

        return [
            'videos' => $videos,
            'total' => $total,
            'hasMore' => ($page * $perPage) < $total,
            'page' => $page,
            'perPage' => $perPage,
        ];
    }

    public function countAll(): int
    {
        return (int) $this->createQueryBuilder('v')
            ->select('COUNT(v.id)')
            ->andWhere('v.status = :status')
            ->setParameter('status', VideoStatus::PUBLIE)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function sumViews(): int
    {
        return (int) ($this->createQueryBuilder('v')
            ->select('COALESCE(SUM(v.viewsCount), 0)')
            ->andWhere('v.status = :status')
            ->setParameter('status', VideoStatus::PUBLIE)
            ->getQuery()
            ->getSingleScalarResult());
    }
}
