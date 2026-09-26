<?php

namespace App\Repository;

use App\Entity\Language;
use App\Entity\Subject;
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

    /**
     * Langues réellement disponibles parmi les contenus publiés des sujets
     * donnés — utilisé par le constructeur de lot de l'espace média (étape
     * « choisir les langues ») pour ne pas proposer une langue sans rapport
     * avec les sujets déjà sélectionnés.
     *
     * @param Subject[] $subjects
     * @return Language[]
     */
    public function findAvailableForSubjects(array $subjects): array
    {
        if ($subjects === []) {
            return [];
        }

        return $this->createQueryBuilder('l')
            ->innerJoin(Video::class, 'v', 'WITH', 'v.language = l AND v.status = :status')
            ->andWhere('v.subject IN (:subjects)')
            ->setParameter('subjects', $subjects)
            ->setParameter('status', VideoStatus::PUBLIE)
            ->groupBy('l.id')
            ->orderBy('l.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
