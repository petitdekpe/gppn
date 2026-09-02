<?php

namespace App\Repository;

use App\Entity\CouncilSession;
use App\Entity\VideoFile;
use App\Enum\VideoStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<VideoFile>
 */
class VideoFileRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, VideoFile::class);
    }

    /**
     * Revalide côté serveur une sélection de fichiers soumise pour
     * téléchargement : ne retourne que les fichiers réellement disponibles,
     * appartenant à une capsule publiée de ce conseil des ministres — pour
     * éviter qu'une requête forgée fasse fuiter un fichier hors sélection
     * légitime.
     *
     * @param int[] $ids
     * @return VideoFile[]
     */
    public function findSelectableForCouncilSession(CouncilSession $councilSession, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return $this->createQueryBuilder('f')
            ->innerJoin('f.video', 'v')
            ->andWhere('f.id IN (:ids)')
            ->andWhere('v.councilSession = :councilSession')
            ->andWhere('v.status = :status')
            ->andWhere('f.fileName IS NOT NULL')
            ->setParameter('ids', $ids)
            ->setParameter('councilSession', $councilSession)
            ->setParameter('status', VideoStatus::PUBLIE)
            ->getQuery()
            ->getResult();
    }
}
