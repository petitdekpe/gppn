<?php

namespace App\Repository;

use App\Entity\Language;
use App\Entity\Subject;
use App\Entity\VideoFile;
use App\Enum\CapsuleFormat;
use App\Enum\VideoFileType;
use App\Enum\VideoStatus;
use App\Search\SpeakerPeriodFilter;
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
     * Fichiers d'un lot (constructeur de l'espace média) : recalculée
     * entièrement côté serveur à partir des sujets/langues/formats soumis,
     * plutôt que de faire confiance à une liste d'identifiants de fichiers
     * envoyée par le client — un sujet est obligatoire (pas de lot vide),
     * langue et format restent optionnels (aucune coche = pas de restriction).
     *
     * @param Subject[] $subjects
     * @param Language[] $languages
     * @param CapsuleFormat[] $formats
     * @param VideoFileType[]|null $allowedTypes types ouverts au visiteur (vidéo TV et audio MP3 réservés aux médias) ; null = tous
     * @return VideoFile[]
     */
    public function findForLot(array $subjects, array $languages, array $formats, ?SpeakerPeriodFilter $speakerPeriod = null, ?array $allowedTypes = null): array
    {
        if ($subjects === []) {
            return [];
        }

        $qb = $this->createQueryBuilder('f')
            ->addSelect('v', 's', 't', 'l')
            ->innerJoin('f.video', 'v')
            ->innerJoin('v.subject', 's')
            ->innerJoin('s.thematic', 't')
            ->innerJoin('v.language', 'l')
            ->andWhere('s IN (:subjects)')
            ->andWhere('v.status = :status')
            ->andWhere('f.fileName IS NOT NULL')
            ->setParameter('subjects', $subjects)
            ->setParameter('status', VideoStatus::PUBLIE);
        $speakerPeriod?->apply($qb);
        if ($allowedTypes !== null) {
            $qb->andWhere('f.type IN (:allowedTypes)')->setParameter('allowedTypes', $allowedTypes);
        }

        if ($languages !== []) {
            $qb->andWhere('l IN (:languages)')->setParameter('languages', $languages);
        }

        if ($formats !== []) {
            $fileTypes = [];
            foreach ($formats as $format) {
                array_push($fileTypes, ...$format->getVideoFileTypes());
            }
            $qb->andWhere('f.type IN (:fileTypes)')->setParameter('fileTypes', $fileTypes);
        }

        return $qb
            ->orderBy('s.title', 'ASC')
            ->addOrderBy('l.name', 'ASC')
            ->addOrderBy('f.type', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Catégories de formats (voir CapsuleFormat) réellement disponibles parmi
     * les contenus publiés des sujets (et, si fournies, langues) donnés —
     * utilisé par le constructeur de lot pour ne pas proposer un format sans
     * rapport avec la sélection déjà faite.
     *
     * @param Subject[] $subjects
     * @param Language[] $languages
     * @param VideoFileType[]|null $allowedTypes types ouverts au visiteur ; null = tous
     * @return CapsuleFormat[]
     */
    public function findAvailableFormatsForSubjects(array $subjects, array $languages, ?SpeakerPeriodFilter $speakerPeriod = null, ?array $allowedTypes = null): array
    {
        if ($subjects === []) {
            return [];
        }

        $qb = $this->createQueryBuilder('f')
            ->select('DISTINCT f.type')
            ->innerJoin('f.video', 'v')
            ->andWhere('v.subject IN (:subjects)')
            ->andWhere('v.status = :status')
            ->andWhere('f.fileName IS NOT NULL')
            ->setParameter('subjects', $subjects)
            ->setParameter('status', VideoStatus::PUBLIE);
        $speakerPeriod?->apply($qb);
        if ($allowedTypes !== null) {
            $qb->andWhere('f.type IN (:allowedTypes)')->setParameter('allowedTypes', $allowedTypes);
        }

        if ($languages !== []) {
            $qb->innerJoin('v.language', 'l')
                ->andWhere('l IN (:languages)')
                ->setParameter('languages', $languages);
        }

        $categories = [];
        foreach ($qb->getQuery()->getScalarResult() as $row) {
            // Doctrine convertit déjà les colonnes `enumType` en instances de
            // l'enum, y compris en hydratation scalaire — mais on reste
            // défensif au cas où une version future renverrait la valeur brute.
            $type = $row['type'] instanceof VideoFileType ? $row['type'] : VideoFileType::from($row['type']);
            $category = $type->getCategory();
            $categories[$category->value] = $category;
        }

        return array_values($categories);
    }
}
