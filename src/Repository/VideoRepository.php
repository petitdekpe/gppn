<?php

namespace App\Repository;

use App\Doctrine\Filter\CapsuleFormatFilter;
use App\Entity\CouncilSession;
use App\Entity\Language;
use App\Entity\Subject;
use App\Entity\Thematic;
use App\Entity\Video;
use App\Enum\CapsuleFormat;
use App\Enum\VideoFileType;
use App\Enum\VideoStatus;
use App\Search\SpeakerPeriodFilter;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Video>
 */
class VideoRepository extends ServiceEntityRepository
{
    public const PER_PAGE = 12;
    /** Page Contenus : sujets par page, chacun avec tous ses contenus (une langue par contenu). */
    public const SUBJECTS_PER_PAGE = 6;

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
        $qb = $this->createQueryBuilder('v')
            ->addSelect('s', 't', 'l')
            ->innerJoin('v.subject', 's')
            ->innerJoin('s.thematic', 't')
            ->innerJoin('v.language', 'l')
            ->andWhere('v.status = :status')
            ->setParameter('status', VideoStatus::PUBLIE);

        return $this->onlyVisible($qb);
    }

    /**
     * Écarte les contenus dont tous les fichiers sont d'un type désactivé
     * dans les Paramètres (voir CapsuleFormatFilter).
     */
    private function onlyVisible(\Doctrine\ORM\QueryBuilder $qb): \Doctrine\ORM\QueryBuilder
    {
        $condition = CapsuleFormatFilter::visibleVideoCondition($this->getEntityManager(), 'v');

        return $condition !== null ? $qb->andWhere($condition) : $qb;
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
     * Calendrier de la liste de l'admin : conseils ayant des contenus, du
     * plus récent au plus ancien, avec leur nombre de contenus.
     *
     * @return list<array{id: int, date: \DateTimeInterface|string, count: int}>
     */
    public function findAdminCalendar(): array
    {
        return array_map(static fn (array $row) => ['id' => (int) $row['id'], 'date' => $row['date'], 'count' => (int) $row['count']], $this->createQueryBuilder('v')
            ->select('c.id AS id', 'c.date AS date', 'COUNT(v.id) AS count')
            ->innerJoin('v.subject', 's')
            ->innerJoin('s.councilSession', 'c')
            ->groupBy('c.id', 'c.date')
            ->orderBy('c.date', 'DESC')
            ->getQuery()
            ->getArrayResult());
    }

    /**
     * Contenus d'un conseil pour la liste de l'admin, tous statuts
     * confondus, triés par sujet puis langue. Un conseil à la fois : la
     * liste complète deviendrait trop lourde avec les années.
     *
     * @return Video[]
     */
    public function findForAdminIndex(int $councilSessionId): array
    {
        return $this->createQueryBuilder('v')
            ->addSelect('s', 'c', 't', 'l', 'sp', 'f')
            ->innerJoin('v.subject', 's')
            ->innerJoin('s.councilSession', 'c')
            ->innerJoin('s.thematic', 't')
            ->innerJoin('v.language', 'l')
            ->leftJoin('v.speaker', 'sp')
            // Fichiers chargés d'un coup : colonne des formats disponibles.
            ->leftJoin('v.files', 'f')
            ->where('c.id = :councilSession')
            ->setParameter('councilSession', $councilSessionId)
            ->orderBy('s.title', 'ASC')
            ->addOrderBy('l.name', 'ASC')
            ->addOrderBy('v.id', 'ASC')
            ->getQuery()
            ->getResult();
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
     * Dernier conseil des ministres (par date) ayant au moins un contenu
     * publié et visible : section « Derniers contenus publiés » de l'accueil.
     */
    public function findLatestCouncilSession(): ?CouncilSession
    {
        $video = $this->baseQueryBuilder()
            ->addSelect('cs')
            ->innerJoin('s.councilSession', 'cs')
            ->orderBy('cs.date', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $video?->getSubject()->getCouncilSession();
    }

    /**
     * Contenus publiés d'un intervenant (toutes ses fiches), pour sa page :
     * du conseil le plus récent au plus ancien, puis par sujet et langue.
     * Fichiers chargés d'un coup (boutons de téléchargement, kits).
     *
     * @param list<int> $speakerIds
     *
     * @return Video[]
     */
    public function findPublishedForSpeakers(array $speakerIds, ?CouncilSession $councilSession = null): array
    {
        if ($speakerIds === []) {
            return [];
        }

        $qb = $this->baseQueryBuilder()
            ->addSelect('cs', 'f', 'sp')
            ->innerJoin('s.councilSession', 'cs')
            ->leftJoin('v.files', 'f')
            ->leftJoin('v.speaker', 'sp')
            ->andWhere('v.speaker IN (:speakers)')
            ->setParameter('speakers', $speakerIds)
            ->orderBy('cs.date', 'DESC')
            ->addOrderBy('s.title', 'ASC')
            ->addOrderBy('l.name', 'ASC');
        if ($councilSession !== null) {
            $qb->andWhere('cs = :councilSession')->setParameter('councilSession', $councilSession);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Contenus publiés d'un conseil des ministres, les plus récents d'abord.
     *
     * @return Video[]
     */
    public function findLatestForCouncilSession(CouncilSession $councilSession, int $limit = 6): array
    {
        return $this->baseQueryBuilder()
            ->andWhere('s.councilSession = :councilSession')
            ->setParameter('councilSession', $councilSession)
            ->orderBy('v.publishedAt', 'DESC')
            ->addOrderBy('v.id', 'DESC')
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
     * Contenu du hero de l'accueil, tiré au hasard à chaque affichage parmi
     * ceux du dernier conseil des ministres : de préférence une vidéo avec
     * cover (sans elle, le hero n'affiche qu'un aplat de couleur), à défaut
     * un contenu avec cover, à défaut n'importe lequel du conseil.
     * Sans conseil, le plus récent « à la une », sinon le plus récent avec cover.
     */
    public function findHeroVideo(?CouncilSession $councilSession): ?Video
    {
        if ($councilSession !== null) {
            $videos = $this->baseQueryBuilder()
                ->addSelect('f')
                ->leftJoin('v.files', 'f')
                ->andWhere('s.councilSession = :councilSession')
                ->setParameter('councilSession', $councilSession)
                ->getQuery()
                ->getResult();
            $withCover = array_values(array_filter($videos, static fn (Video $v) => $v->getCoverImageName() !== null));
            $playable = array_values(array_filter($withCover, static fn (Video $v) => $v->getPrimaryPlaybackFile()?->getType()->isVideo() === true));
            $candidates = $playable ?: $withCover ?: $videos;
            if ($candidates !== []) {
                return $candidates[array_rand($candidates)];
            }
        }

        return $this->baseQueryBuilder()
            ->andWhere('v.coverImageName IS NOT NULL')
            ->orderBy('v.featured', 'DESC')
            ->addOrderBy('v.publishedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
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
    /**
     * Le même sujet dans les autres langues (fiche contenu, « Dans d'autres langues »).
     *
     * @return Video[]
     */
    public function findOtherLanguages(Video $video, int $limit = 12): array
    {
        if ($video->getSubject() === null) {
            return [];
        }

        return $this->baseQueryBuilder()
            ->andWhere('v.subject = :subject')
            ->andWhere('v.id != :id')
            ->setParameter('subject', $video->getSubject())
            ->setParameter('id', $video->getId())
            ->orderBy('l.name', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public const FEED_PER_PAGE = 6;

    /**
     * Fil vertical (bouton flottant « Feed ») : uniquement les contenus ayant
     * une version 9:16 déposée, du plus récent au plus ancien.
     *
     * @return array{videos: Video[], hasMore: bool, page: int}
     */
    public function findVerticalFeed(int $page = 1, int $perPage = self::FEED_PER_PAGE): array
    {
        $page = max(1, $page);

        $qb = $this->baseQueryBuilder();
        $qb->andWhere($qb->expr()->exists(
            'SELECT 1 FROM App\Entity\VideoFile vf WHERE vf.video = v AND vf.type = :verticalType AND vf.fileName IS NOT NULL',
        ))
            ->setParameter('verticalType', VideoFileType::MP4_VERTICAL)
            ->orderBy('v.publishedAt', 'DESC');

        $countQb = clone $qb;
        $total = (int) $countQb->select('COUNT(DISTINCT v.id)')->getQuery()->getSingleScalarResult();

        $videos = $qb->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();

        return [
            'videos' => $videos,
            'hasMore' => ($page * $perPage) < $total,
            'page' => $page,
        ];
    }

    /**
     * @param Thematic[] $thematics
     * @param Language[] $languages
     * @param CapsuleFormat[] $formats
     * @param CouncilSession[] $councilSessions
     * @return array{videos: Video[], total: int, hasMore: bool, page: int}
     */
    /**
     * Suggestions de la barre de recherche : mêmes champs que les listes
     * (voir textCondition), les contenus dont le titre contient le texte
     * tapé en tête, puis les plus récents.
     *
     * @return Video[]
     */
    public function suggest(string $text, int $limit = 5): array
    {
        $qb = $this->baseQueryBuilder()->leftJoin('v.speaker', 'qsp');
        $condition = $this->textCondition($qb, $text, 'qsp');
        if ($condition === null) {
            return [];
        }

        return $qb
            ->andWhere($condition)
            ->addSelect('CASE WHEN s.title LIKE :titleText THEN 0 ELSE 1 END AS HIDDEN titleRank')
            ->setParameter('titleText', '%' . self::likeText(trim($text)) . '%')
            ->orderBy('titleRank')
            ->addOrderBy('v.publishedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Recherche par texte, commune aux listes et aux suggestions : chaque mot
     * tapé doit se trouver dans l'un des champs (titre, résumé, mots-clés du
     * sujet, thématique, langue, nom et sigle de l'intervenant), dans
     * n'importe quel ordre : « permis conduire fon » trouve « Permis de
     * conduire : la nouvelle procédure » en fon. Accents et casse ignorés par
     * la collation des colonnes (utf8mb4_0900_ai_ci).
     *
     * @return string|null condition DQL, null si le texte est vide
     */
    private function textCondition(\Doctrine\ORM\QueryBuilder $qb, string $text, string $speakerAlias): ?string
    {
        $words = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY);
        // Mots d'une lettre (« à », « l ») ignorés, sauf s'il n'y a qu'eux.
        $words = array_values(array_unique(array_filter($words, static fn (string $word) => mb_strlen($word) >= 2) ?: $words));
        if ($words === []) {
            return null;
        }

        $fields = ['s.title', 's.summary', 's.keywords', 't.name', 'l.name', $speakerAlias . '.fullName', $speakerAlias . '.sigle'];
        $conditions = [];
        foreach (\array_slice($words, 0, 8) as $index => $word) {
            $parameter = 'textWord' . $index;
            $conditions[] = '(' . implode(' OR ', array_map(static fn (string $field) => $field . ' LIKE :' . $parameter, $fields)) . ')';
            $qb->setParameter($parameter, '%' . self::likeText($word) . '%');
        }

        return implode(' AND ', $conditions);
    }

    /** Texte à chercher dans un LIKE ; apostrophe droite ou typographique au choix : « l'eau » trouve « l’eau ». */
    private static function likeText(string $text): string
    {
        return preg_replace("/['’‘ʼ]/u", '_', addcslashes($text, '%_\\'));
    }

    /**
     * @param list<int> $querySpeakerIds fiches des intervenants reconnus dans le texte recherché
     *   (nom dans le désordre, sigle : voir SpeakerPeriodCriteria::searchPeople)
     */
    public function search(array $thematics, array $languages, array $formats, ?string $query, int $page = 1, int $perPage = self::PER_PAGE, ?string $speakerRole = null, array $councilSessions = [], ?SpeakerPeriodFilter $speakerPeriod = null, array $querySpeakerIds = []): array
    {
        $page = max(1, $page);

        $qb = $this->searchQueryBuilder($thematics, $languages, $formats, $query, $speakerRole, $councilSessions, $speakerPeriod, $querySpeakerIds);
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
     * Mêmes filtres que search(), résultats regroupés par sujet (page Contenus) :
     * la pagination compte les sujets, qui ne sont jamais coupés entre deux pages.
     * Sujets du conseil des ministres le plus récent au plus ancien (puis, dans
     * un même conseil, du plus récemment publié au plus ancien), contenus d'un sujet par langue.
     *
     * @return array{groups: list<array{subject: Subject, videos: Video[]}>, total: int, videoTotal: int, hasMore: bool, page: int, perPage: int}
     */
    public function searchBySubject(array $thematics, array $languages, array $formats, ?string $query, int $page = 1, int $perPage = self::SUBJECTS_PER_PAGE, ?string $speakerRole = null, array $councilSessions = [], ?SpeakerPeriodFilter $speakerPeriod = null, array $querySpeakerIds = []): array
    {
        $page = max(1, $page);
        $qb = $this->searchQueryBuilder($thematics, $languages, $formats, $query, $speakerRole, $councilSessions, $speakerPeriod, $querySpeakerIds);

        $counts = (clone $qb)->select('COUNT(DISTINCT s.id) AS subjects, COUNT(DISTINCT v.id) AS videos')->getQuery()->getSingleResult();
        $total = (int) $counts['subjects'];

        $subjectIds = array_column((clone $qb)
            ->select('s.id AS id, MAX(cs.date) AS HIDDEN councilDate, MAX(v.publishedAt) AS HIDDEN latest')
            ->innerJoin('s.councilSession', 'cs')
            ->groupBy('s.id')
            ->orderBy('councilDate', 'DESC')
            ->addOrderBy('latest', 'DESC')
            ->addOrderBy('s.id', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getScalarResult(), 'id');

        $groups = array_fill_keys($subjectIds, null);
        if ($subjectIds !== []) {
            // Intervenant chargé d'un coup : nom et sigle sur chaque carte.
            $videos = $qb->addSelect('gsp')
                ->leftJoin('v.speaker', 'gsp')
                ->andWhere('s.id IN (:pageSubjects)')
                ->setParameter('pageSubjects', $subjectIds)
                ->orderBy('l.name', 'ASC')
                ->addOrderBy('v.id', 'ASC')
                ->getQuery()
                ->getResult();
            foreach ($videos as $video) {
                $subject = $video->getSubject();
                $groups[$subject->getId()] ??= ['subject' => $subject, 'videos' => []];
                $groups[$subject->getId()]['videos'][] = $video;
            }
        }

        return [
            'groups' => array_values(array_filter($groups)),
            'total' => $total,
            'videoTotal' => (int) $counts['videos'],
            'hasMore' => ($page * $perPage) < $total,
            'page' => $page,
            'perPage' => $perPage,
        ];
    }

    /**
     * Contenus publiés répondant aux filtres des listes (Contenus, Langue, Thématique).
     *
     * @param list<int> $querySpeakerIds voir search()
     */
    private function searchQueryBuilder(array $thematics, array $languages, array $formats, ?string $query, ?string $speakerRole, array $councilSessions, ?SpeakerPeriodFilter $speakerPeriod, array $querySpeakerIds): \Doctrine\ORM\QueryBuilder
    {
        $qb = $this->baseQueryBuilder();
        $speakerPeriod?->apply($qb);

        if ($thematics !== []) {
            $qb->andWhere('t IN (:thematics)')->setParameter('thematics', $thematics);
        }

        if ($languages !== []) {
            $qb->andWhere('l IN (:languages)')->setParameter('languages', $languages);
        }

        if ($councilSessions !== []) {
            $qb->andWhere('s.councilSession IN (:councilSessions)')->setParameter('councilSessions', $councilSessions);
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

        if ($query !== null && trim($query) !== '') {
            // Mots du texte (voir textCondition) ; et les contenus de l'intervenant s'il y est reconnu.
            $qb->leftJoin('v.speaker', 'qsp');
            $condition = $this->textCondition($qb, $query, 'qsp');
            if ($querySpeakerIds !== []) {
                $condition = '(' . $condition . ') OR v.speaker IN (:querySpeakers)';
                $qb->setParameter('querySpeakers', $querySpeakerIds);
            }
            $qb->andWhere($condition);
        }

        if ($speakerRole !== null) {
            $videoIds = $this->findVideoIdsBySpeakerRole($speakerRole);
            $qb->andWhere('v.id IN (:speakerVideoIds)')->setParameter('speakerVideoIds', $videoIds ?: [0]);
        }

        return $qb;
    }

    /**
     * Distingue les contenus portés par un Ministre "de plein exercice" de ceux
     * portés par un Ministre Conseiller à la Présidence, à partir du champ texte
     * libre `role` de l'intervenant (aucune donnée structurée dédiée pour l'instant).
     *
     * @return int[]
     */
    public function findVideoIdsBySpeakerRole(string $roleFilter): array
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

    /**
     * Nombre de contenus publiés disposant d'au moins un fichier de chaque
     * format, utilisé pour afficher le nombre de résultats à côté de chaque
     * case du filtre « Format » sur /videos.
     *
     * @return array<string, int> décompte indexé par CapsuleFormat::value
     */
    public function countAllByFormat(): array
    {
        $counts = [];

        foreach (CapsuleFormat::cases() as $format) {
            $qb = $this->baseQueryBuilder();
            $qb->andWhere($qb->expr()->exists(
                'SELECT 1 FROM App\Entity\VideoFile vf WHERE vf.video = v AND vf.type IN (:fileTypes) AND vf.fileName IS NOT NULL',
            ))->setParameter('fileTypes', $format->getVideoFileTypes());

            $counts[$format->value] = (int) $qb->select('COUNT(DISTINCT v.id)')->getQuery()->getSingleScalarResult();
        }

        return $counts;
    }

    public function countAll(): int
    {
        return (int) $this->onlyVisible($this->createQueryBuilder('v'))
            ->select('COUNT(v.id)')
            ->andWhere('v.status = :status')
            ->setParameter('status', VideoStatus::PUBLIE)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function sumViews(): int
    {
        return (int) ($this->onlyVisible($this->createQueryBuilder('v'))
            ->select('COALESCE(SUM(v.viewsCount), 0)')
            ->andWhere('v.status = :status')
            ->setParameter('status', VideoStatus::PUBLIE)
            ->getQuery()
            ->getSingleScalarResult());
    }
}
