<?php

namespace App\Repository;

use App\Entity\CouncilSession;
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
            ->addSelect('s', 't', 'l')
            ->innerJoin('v.subject', 's')
            ->innerJoin('s.thematic', 't')
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
    public function findPublishedByCouncilSession(CouncilSession $councilSession): array
    {
        return $this->baseQueryBuilder()
            ->andWhere('s.councilSession = :councilSession')
            ->setParameter('councilSession', $councilSession)
            ->orderBy('s.title', 'ASC')
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
    public function search(array $thematics, array $languages, array $formats, ?string $query, int $page = 1, int $perPage = self::PER_PAGE, ?string $speakerRole = null): array
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
            $fileTypes = [];
            foreach ($formats as $format) {
                array_push($fileTypes, ...$format->getVideoFileTypes());
            }

            $qb->andWhere($qb->expr()->exists(
                'SELECT 1 FROM App\Entity\VideoFile vf WHERE vf.video = v AND vf.type IN (:fileTypes) AND vf.fileName IS NOT NULL',
            ))->setParameter('fileTypes', $fileTypes);
        }

        if ($query !== null && $query !== '') {
            $qb->andWhere('s.title LIKE :query OR s.summary LIKE :query')
                ->setParameter('query', '%' . $query . '%');
        }

        if ($speakerRole !== null) {
            $videoIds = $this->findVideoIdsBySpeakerRole($speakerRole);
            $qb->andWhere('v.id IN (:speakerVideoIds)')->setParameter('speakerVideoIds', $videoIds ?: [0]);
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

    /**
     * Distingue les contenus portés par un Ministre "de plein exercice" de ceux
     * portés par un Ministre Conseiller à la Présidence, à partir du champ texte
     * libre `role` de l'intervenant (aucune donnée structurée dédiée pour l'instant).
     *
     * @return int[]
     */
    private function findVideoIdsBySpeakerRole(string $roleFilter): array
    {
        $qb = $this->createQueryBuilder('v')
            ->select('DISTINCT v.id')
            ->innerJoin('v.speaker', 's');

        match ($roleFilter) {
            'ministre' => $qb->andWhere('s.role LIKE :ministre')->andWhere('s.role NOT LIKE :conseiller')
                ->setParameter('ministre', '%Ministre%')
                ->setParameter('conseiller', '%Conseiller%'),
            'conseiller' => $qb->andWhere('s.role LIKE :conseiller')
                ->setParameter('conseiller', '%Conseiller%'),
            default => $qb->andWhere('s.role LIKE :ministre')
                ->setParameter('ministre', '%Ministre%'),
        };

        return array_column($qb->getQuery()->getScalarResult(), 'id');
    }

    /**
     * `count(['councilSession' => ...])` ne fonctionne plus depuis que le
     * conseil des ministres est porté par le sujet et non plus directement
     * par le contenu.
     */
    public function countByCouncilSession(CouncilSession $councilSession): int
    {
        return (int) $this->createQueryBuilder('v')
            ->select('COUNT(v.id)')
            ->innerJoin('v.subject', 's')
            ->andWhere('s.councilSession = :councilSession')
            ->setParameter('councilSession', $councilSession)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Idem pour la thématique, désormais portée par le sujet.
     */
    public function countByThematic(Thematic $thematic): int
    {
        return (int) $this->createQueryBuilder('v')
            ->select('COUNT(v.id)')
            ->innerJoin('v.subject', 's')
            ->andWhere('s.thematic = :thematic')
            ->setParameter('thematic', $thematic)
            ->getQuery()
            ->getSingleScalarResult();
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
