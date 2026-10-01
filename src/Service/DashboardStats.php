<?php

namespace App\Service;

use App\Entity\VideoFeedback;
use App\Enum\VideoFileType;
use App\Enum\VideoStatus;
use App\Enum\WebmStatus;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Agrégats du tableau de bord admin, calculés en SQL direct : ils portent sur
 * tous les contenus, sans le filtre de format appliqué au site public.
 */
class DashboardStats
{
    private const MONTHS = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];

    private const RATING_LABELS = [
        VideoFeedback::RATING_CLEAR => 'Clair',
        VideoFeedback::RATING_UNCLEAR => 'Pas très clair',
        VideoFeedback::RATING_NOT_CLEAR => 'Pas clair',
    ];

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @return array<string, int> nombre de contenus par statut (clé = VideoStatus::value)
     */
    public function videosByStatus(): array
    {
        $counts = array_fill_keys(array_map(fn (VideoStatus $s) => $s->value, VideoStatus::cases()), 0);
        foreach ($this->connection->fetchAllKeyValue('SELECT status, COUNT(*) FROM video GROUP BY status') as $status => $count) {
            $counts[$status] = (int) $count;
        }

        return $counts;
    }

    /**
     * @return array{views: int, published: int, average: int}
     */
    public function views(): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT COALESCE(SUM(views_count), 0) AS views, COUNT(*) AS published FROM video WHERE status = ?',
            [VideoStatus::PUBLIE->value],
        );
        $published = (int) $row['published'];

        return [
            'views' => (int) $row['views'],
            'published' => $published,
            'average' => $published > 0 ? (int) round($row['views'] / $published) : 0,
        ];
    }

    /**
     * @return list<array{id: int, title: string, language: string, views: int}>
     */
    public function topVideos(int $limit = 5): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT v.id, s.title, l.name AS language, v.views_count AS views
             FROM video v
             JOIN subject s ON s.id = v.subject_id
             JOIN language l ON l.id = v.language_id
             WHERE v.status = ?
             ORDER BY v.views_count DESC
             LIMIT ' . $limit,
            [VideoStatus::PUBLIE->value],
        );
    }

    /**
     * @return list<array{name: string, videos: int, views: int}>
     */
    public function byLanguage(): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT l.name, COUNT(v.id) AS videos, COALESCE(SUM(v.views_count), 0) AS views
             FROM language l
             LEFT JOIN video v ON v.language_id = l.id AND v.status = ?
             GROUP BY l.id, l.name
             ORDER BY views DESC, l.name',
            [VideoStatus::PUBLIE->value],
        );
    }

    /**
     * @return list<array{name: string, color: string, videos: int, views: int}>
     */
    public function byThematic(): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT t.name, t.color_hex AS color, COUNT(v.id) AS videos, COALESCE(SUM(v.views_count), 0) AS views
             FROM thematic t
             LEFT JOIN subject s ON s.thematic_id = t.id
             LEFT JOIN video v ON v.subject_id = s.id AND v.status = ?
             GROUP BY t.id, t.name, t.color_hex
             ORDER BY views DESC, t.name',
            [VideoStatus::PUBLIE->value],
        );
    }

    /**
     * Contenus publiés par mois sur les 12 derniers mois (mois en cours inclus).
     *
     * @return list<array{label: string, count: int}>
     */
    public function publishedPerMonth(): array
    {
        $start = new \DateTimeImmutable('first day of this month midnight -11 months');
        $counts = $this->connection->fetchAllKeyValue(
            "SELECT DATE_FORMAT(published_at, '%Y-%m') AS month, COUNT(*)
             FROM video
             WHERE status = ? AND published_at >= ?
             GROUP BY month",
            [VideoStatus::PUBLIE->value, $start->format('Y-m-d H:i:s')],
        );

        $months = [];
        for ($i = 0; $i < 12; ++$i) {
            $month = $start->modify("+$i months");
            $months[] = [
                'label' => self::MONTHS[(int) $month->format('n') - 1] . ($month->format('n') === '1' || $i === 0 ? ' ' . $month->format('y') : ''),
                'count' => (int) ($counts[$month->format('Y-m')] ?? 0),
            ];
        }

        return $months;
    }

    /**
     * @return array{total: int, ratings: list<array{key: string, label: string, count: int, percent: int}>}
     */
    public function feedback(): array
    {
        $counts = $this->connection->fetchAllKeyValue('SELECT rating, COUNT(*) FROM video_feedback GROUP BY rating');
        $total = array_sum(array_map('intval', $counts));

        $ratings = [];
        foreach (self::RATING_LABELS as $key => $label) {
            $count = (int) ($counts[$key] ?? 0);
            $ratings[] = [
                'key' => $key,
                'label' => $label,
                'count' => $count,
                'percent' => $total > 0 ? (int) round($count * 100 / $total) : 0,
            ];
        }

        return ['total' => $total, 'ratings' => $ratings];
    }

    /**
     * @return list<array{title: string, comment: string, rating: string, createdAt: string}>
     */
    public function latestComments(int $limit = 5): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT s.title, f.comment, f.rating, f.created_at AS createdAt
             FROM video_feedback f
             JOIN video v ON v.id = f.video_id
             JOIN subject s ON s.id = v.subject_id
             WHERE f.comment IS NOT NULL AND f.comment <> ''
             ORDER BY f.created_at DESC
             LIMIT " . $limit,
        );

        return array_map(fn (array $row) => $row + ['ratingLabel' => self::RATING_LABELS[$row['rating']] ?? $row['rating']], $rows);
    }

    /**
     * Nombre de contenus publiés proposant chaque type de fichier, plus ceux
     * sans aucun fichier ou sans image de couverture.
     *
     * @return array{published: int, types: list<array{label: string, count: int, percent: int}>, withoutFiles: int, withoutCover: int, defectiveFiles: list<array{videoId: int, title: string, type: string, reason: ?string}>}
     */
    public function fileCoverage(): array
    {
        $published = $this->views()['published'];
        $counts = $this->connection->fetchAllKeyValue(
            'SELECT f.type, COUNT(DISTINCT f.video_id)
             FROM video_file f JOIN video v ON v.id = f.video_id
             WHERE v.status = ? AND f.file_name IS NOT NULL
             GROUP BY f.type',
            [VideoStatus::PUBLIE->value],
        );

        $types = [];
        foreach (VideoFileType::cases() as $type) {
            $count = (int) ($counts[$type->value] ?? 0);
            $types[] = [
                'label' => $type->getLabel(),
                'count' => $count,
                'percent' => $published > 0 ? (int) round($count * 100 / $published) : 0,
            ];
        }

        return [
            'published' => $published,
            'types' => $types,
            'withoutFiles' => (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM video v WHERE v.status = ? AND NOT EXISTS (
                    SELECT 1 FROM video_file f WHERE f.video_id = v.id AND f.file_name IS NOT NULL)',
                [VideoStatus::PUBLIE->value],
            ),
            'withoutCover' => (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM video WHERE status = ? AND cover_image_name IS NULL',
                [VideoStatus::PUBLIE->value],
            ),
            // Masquées du site jusqu'à leur remplacement (voir VideoFileChecker).
            'defectiveFiles' => array_map(static fn (array $row) => [
                'videoId' => (int) $row['video_id'],
                'title' => $row['title'] . ' (' . $row['language'] . ')',
                'type' => VideoFileType::from($row['type'])->getLabel(),
                'reason' => $row['defect_reason'],
            ], $this->connection->fetchAllAssociative(
                'SELECT f.video_id, f.type, f.defect_reason, s.title, l.name AS language
                 FROM video_file f
                 JOIN video v ON v.id = f.video_id
                 JOIN subject s ON s.id = v.subject_id
                 JOIN language l ON l.id = v.language_id
                 WHERE f.defective_at IS NOT NULL AND f.file_name IS NOT NULL
                 ORDER BY f.defective_at DESC',
            )),
        ];
    }

    /**
     * @return array{originals: int, webm: int, covers: int}
     */
    public function storage(): array
    {
        $files = $this->connection->fetchAssociative(
            'SELECT COALESCE(SUM(file_size), 0) AS originals, COALESCE(SUM(webm_file_size), 0) AS webm FROM video_file',
        );

        return [
            'originals' => (int) $files['originals'],
            'webm' => (int) $files['webm'],
            'covers' => (int) $this->connection->fetchOne('SELECT COALESCE(SUM(cover_image_size), 0) FROM video'),
        ];
    }

    /**
     * État des versions WebM de lecture ; `missing` compte les vidéos
     * déposées avant la mise en place de la conversion.
     *
     * @return array{rows: list<array{status: WebmStatus, count: int}>, missing: int}
     */
    public function webmConversions(): array
    {
        $counts = $this->connection->fetchAllKeyValue(
            'SELECT webm_status, COUNT(*) FROM video_file WHERE webm_status IS NOT NULL GROUP BY webm_status',
        );
        $videoTypes = array_values(array_map(
            fn (VideoFileType $t) => $t->value,
            array_filter(VideoFileType::cases(), fn (VideoFileType $t) => $t->hasWebmPlayback()),
        ));
        $missing = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM video_file WHERE file_name IS NOT NULL AND webm_status IS NULL AND type IN (?)',
            [$videoTypes],
            [ArrayParameterType::STRING],
        );

        $rows = array_map(fn (WebmStatus $s) => ['status' => $s, 'count' => (int) ($counts[$s->value] ?? 0)], WebmStatus::cases());

        return ['rows' => $rows, 'missing' => $missing];
    }
}
