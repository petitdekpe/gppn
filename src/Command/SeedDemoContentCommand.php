<?php

namespace App\Command;

use App\Entity\CouncilSession;
use App\Entity\Language;
use App\Entity\Speaker;
use App\Entity\Subject;
use App\Entity\Thematic;
use App\Entity\Video;
use App\Entity\VideoFile;
use App\Enum\VideoFileType;
use App\Enum\VideoStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Meuble le site avec des contenus de démonstration construits à partir des
 * fichiers déjà déposés (MP4, MOV, MP3) : aucun fichier n'est inventé, seuls
 * les sujets, langues et thématiques varient.
 *
 * Les fichiers sont attachés par lien physique (hardlink) plutôt que copiés :
 * pas d'espace disque en plus, et la suppression d'un contenu depuis l'admin
 * (Vich efface alors son fichier) ne touche pas aux autres contenus.
 */
#[AsCommand(
    name: 'app:demo:seed',
    description: 'Crée des contenus de démonstration (sujets, langues, thématiques) à partir des fichiers existants.',
)]
final class SeedDemoContentCommand extends Command
{
    private const SLUG_PREFIX = 'demo-';

    /**
     * Combinaisons de fichiers appliquées à tour de rôle, pour voir le site
     * avec tous les cas de figure (vidéo + audio, vidéo multi-résolutions,
     * audio seul, image seule, MOV).
     */
    private const FILE_SETS = [
        [VideoFileType::MP4_VERTICAL, VideoFileType::AUDIO],
        [VideoFileType::MP4_1080P, VideoFileType::MP4_480P, VideoFileType::MP4_VERTICAL],
        [VideoFileType::MP4_VERTICAL],
        [VideoFileType::AUDIO],
        [VideoFileType::MP4_VERTICAL, VideoFileType::AUDIO],
        [VideoFileType::IMAGE],
    ];

    private const SESSIONS = [
        '2026-06-17' => 'Conseil des ministres du 17 juin 2026',
        '2026-07-08' => 'Conseil des ministres du 8 juillet 2026',
        '2026-07-29' => 'Conseil des ministres du 29 juillet 2026',
        '2026-08-19' => 'Conseil des ministres du 19 août 2026',
        '2026-09-09' => 'Conseil des ministres du 9 septembre 2026',
    ];

    /**
     * [date du conseil, slug thématique, sigle intervenant, titre, résumé, points clés, langues]
     */
    private const SUBJECTS = [
        ['2026-06-17', 'etat-civil', 'MISP', 'Obtenir sa carte d’identité biométrique en 4 étapes',
            'Pré-enregistrement en ligne, rendez-vous, prise d’empreintes et retrait : le parcours complet pour obtenir sa carte d’identité biométrique sans se déplacer plusieurs fois.',
            "Le pré-enregistrement se fait en ligne ou en mairie\nLa prise d’empreintes est gratuite\nLe retrait se fait sur présentation du récépissé",
            ['fon', 'yoruba', 'dendi']],
        ['2026-06-17', 'sante', 'MS', 'Campagne nationale de vaccination contre la rougeole',
            'Du 1er au 15 juillet, les enfants de 9 mois à 5 ans sont vaccinés gratuitement dans tous les centres de santé et lors des passages des équipes mobiles.',
            "La vaccination est gratuite\nElle concerne les enfants de 9 mois à 5 ans\nLe carnet de santé doit être présenté",
            ['fon', 'goun', 'baatonou']],
        ['2026-07-08', 'agriculture', 'MAEP', 'Primes agricoles : comment en bénéficier',
            'Les producteurs de maïs, de riz et de soja peuvent recevoir une prime à la production. Voici les conditions d’éligibilité et les démarches auprès des ATDA.',
            "S’enregistrer auprès de l’ATDA de sa commune\nLa prime est versée par mobile money\nLes coopératives peuvent déposer un dossier groupé",
            ['fon', 'mahi', 'ditamari', 'fulfulde']],
        ['2026-07-08', 'numerique', 'MTDI', 'Faire ses démarches administratives sur service-public.bj',
            'Acte de naissance, casier judiciaire, certificat de nationalité : plus de 200 démarches sont désormais accessibles en ligne, avec paiement par mobile money.',
            "Créer son compte avec son numéro NPI\nPayer par mobile money\nRecevoir le document par e-mail ou SMS",
            ['fon', 'yoruba']],
        ['2026-07-29', 'education', 'MEMP', 'Rentrée scolaire 2026-2027 : ce qui change',
            'Calendrier, gratuité de l’inscription au primaire, cantines scolaires étendues : les principales mesures pour la rentrée des classes.',
            "L’inscription au primaire public est gratuite\nLes cantines couvrent désormais toutes les communes\nLa rentrée est fixée au 14 septembre",
            ['fon', 'adja', 'dendi', 'waama']],
        ['2026-07-29', 'energie', 'MEEM', 'Compteurs à prépaiement : recharger simplement',
            'Les nouveaux compteurs à prépaiement de la SBEE se rechargent par mobile money ou en agence. Explications pour éviter les coupures.',
            "Recharger avant d’atteindre zéro\nConserver le code de recharge\nSignaler toute anomalie au 7070",
            ['fon', 'goun']],
        ['2026-08-19', 'eau-assainissement', 'MEEM', 'Accès à l’eau potable en milieu rural',
            'Nouvelles adductions d’eau villageoises, tarif social et gestion par les comités locaux : ce que prévoit le programme d’accès universel à l’eau potable.',
            "Un tarif social pour les ménages vulnérables\nDes bornes-fontaines dans chaque village\nLes comités locaux assurent l’entretien",
            ['baatonou', 'ditamari', 'fulfulde']],
        ['2026-08-19', 'emploi-entrepreneuriat', 'MPMEPE', 'Financer son projet avec le fonds d’appui aux jeunes',
            'Les jeunes de 18 à 35 ans porteurs d’un projet peuvent obtenir un financement et un accompagnement. Critères, montants et calendrier des appels.',
            "Avoir entre 18 et 35 ans\nPrésenter un plan d’affaires simple\nUn accompagnement est proposé pendant 12 mois",
            ['fon', 'yoruba', 'mahi']],
        ['2026-09-09', 'protection-sociale', 'MFAS', 'ARCH : s’inscrire à l’assurance maladie',
            'Le volet assurance maladie de l’ARCH couvre les soins de base des personnes les plus vulnérables. Qui peut s’inscrire et comment.',
            "L’inscription se fait à la mairie\nLes soins de base sont pris en charge\nLa carte ARCH est personnelle",
            ['fon', 'idaasha', 'sahoue', 'tori']],
        ['2026-09-09', 'transport', 'MCVT', 'Permis de conduire : la nouvelle procédure',
            'Inscription dématérialisée, examen sur tablette et délivrance plus rapide : le point sur la réforme du permis de conduire.',
            "L’inscription se fait en ligne\nL’examen théorique a lieu sur tablette\nLe permis est délivré sous 72 heures",
            ['fon', 'kotafon']],
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        #[Autowire('%app.downloads_dir%')]
        private readonly string $downloadsDir,
        #[Autowire('%app.covers_dir%')]
        private readonly string $coversDir,
    ) {
        parent::__construct();
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Supprime les contenus de démonstration (et leurs fichiers) au lieu d’en créer.')]
        bool $purge = false,
    ): int {
        if ($purge) {
            return $this->purge($io);
        }

        $existing = $this->entityManager->getRepository(Video::class)->createQueryBuilder('v')
            ->select('COUNT(v.id)')->andWhere('v.slug LIKE :prefix')->setParameter('prefix', self::SLUG_PREFIX . '%')
            ->getQuery()->getSingleScalarResult();
        if ($existing > 0) {
            $io->error(sprintf('%d contenu(s) de démonstration existent déjà. Lancez d’abord la commande avec --purge.', $existing));

            return Command::FAILURE;
        }

        $sources = $this->findSourceFiles();
        if (!isset($sources['video/mp4'], $sources['audio/mpeg'])) {
            $io->error('Il faut au moins un MP4 et un MP3 déjà déposés pour construire les contenus de démonstration.');

            return Command::FAILURE;
        }

        $covers = $this->generateCovers($sources, $io);

        $sessions = [];
        foreach (self::SESSIONS as $date => $label) {
            $sessions[$date] = $this->findOrCreateSession($date, $label);
        }

        mt_srand(2026);
        $index = 0;
        $rows = [];
        $publishedAt = new \DateTimeImmutable('2026-06-18 09:00');

        foreach (self::SUBJECTS as [$date, $thematicSlug, $sigle, $title, $summary, $learningPoints, $languageSlugs]) {
            $subject = (new Subject())
                ->setCouncilSession($sessions[$date])
                ->setThematic($this->findOneOrFail(Thematic::class, ['slug' => $thematicSlug]))
                ->setTitle($title)
                ->setSummary($summary)
                ->setLearningPoints($learningPoints);
            $this->entityManager->persist($subject);

            $speaker = $this->entityManager->getRepository(Speaker::class)->findOneBy(['sigle' => $sigle]);
            $publishedAt = max($publishedAt, new \DateTimeImmutable($date . ' 10:00'));

            foreach ($languageSlugs as $languageSlug) {
                $language = $this->findOneOrFail(Language::class, ['slug' => $languageSlug]);
                $slug = self::SLUG_PREFIX . $this->slugify($title . '-' . $languageSlug);
                $fileSet = self::FILE_SETS[$index % \count(self::FILE_SETS)];
                // Le MOV (vertical 1080×1920) remplace le MP4 un contenu vidéo sur trois.
                $videoSource = (isset($sources['video/quicktime']) && $index % 3 === 2) ? $sources['video/quicktime'] : $sources['video/mp4'];
                $publishedAt = $publishedAt->modify(sprintf('+%d hours', mt_rand(5, 40)));

                $video = (new Video())
                    ->setSlug($slug)
                    ->setStatus(VideoStatus::PUBLIE)
                    ->setLanguage($language)
                    ->setSubject($subject)
                    ->setSpeaker($speaker)
                    ->setDurationSeconds(\in_array(VideoFileType::AUDIO, $fileSet, true) && \count($fileSet) === 1 ? $sources['audio/mpeg']['duration'] : $videoSource['duration'])
                    ->setViewsCount(mt_rand(40, 4800))
                    ->setPublishedAt($publishedAt);

                // Un contenu sur cinq reste sans couverture pour vérifier le repli sur le dégradé de la thématique.
                // Jamais pour un contenu « image seule », dont la couverture est l'unique fichier.
                $withoutCover = $index % 5 === 4 && $fileSet !== [VideoFileType::IMAGE];
                $cover = ($covers !== [] && !$withoutCover) ? $covers[$index % \count($covers)] : null;
                if ($cover !== null) {
                    $coverName = $this->linkInto($cover, $this->coversDir, $slug . '-cover');
                    $video->setCoverImageName($coverName)
                        ->setCoverImageSize(filesize($cover))
                        ->setCoverImageMimeType('image/jpeg')
                        ->setCoverImageOriginalName(basename($coverName))
                        ->setCoverImageUpdatedAt(new \DateTimeImmutable());
                }

                foreach (VideoFileType::cases() as $type) {
                    $file = (new VideoFile())->setType($type);
                    $source = match (true) {
                        !\in_array($type, $fileSet, true) => null,
                        $type === VideoFileType::AUDIO => $sources['audio/mpeg'],
                        $type === VideoFileType::IMAGE => $cover !== null ? ['path' => $cover, 'mime' => 'image/jpeg'] : null,
                        default => $videoSource,
                    };
                    if ($source !== null) {
                        $fileName = $this->linkInto($source['path'], $this->downloadsDir, $slug . '-' . strtolower($type->name));
                        $file->setFileName($fileName)
                            ->setFileSize(filesize($source['path']))
                            ->setMimeType($source['mime'])
                            ->setOriginalName(basename($fileName))
                            ->setUpdatedAt(new \DateTimeImmutable());
                    }
                    $video->addFile($file);
                }

                $this->entityManager->persist($video);
                $rows[] = [$title, $language->getName(), implode(', ', array_map(static fn (VideoFileType $t) => $t->getLabel(), $fileSet)), $cover !== null ? 'oui' : 'non'];
                ++$index;
            }
        }

        $this->entityManager->flush();

        // Les trois contenus vidéo les plus récents alimentent le hero et « À la une » :
        // un contenu audio ou image ferait basculer le hero sur sa vignette de repli.
        $latest = $this->entityManager->getRepository(Video::class)->createQueryBuilder('v')
            ->andWhere('v.slug LIKE :prefix')->setParameter('prefix', self::SLUG_PREFIX . '%')
            ->andWhere('v.coverImageName IS NOT NULL')
            ->andWhere('EXISTS (SELECT 1 FROM App\Entity\VideoFile vf WHERE vf.video = v AND vf.type = :verticalType AND vf.fileName IS NOT NULL)')
            ->setParameter('verticalType', VideoFileType::MP4_VERTICAL)
            ->orderBy('v.publishedAt', 'DESC')->setMaxResults(3)
            ->getQuery()->getResult();
        foreach ($latest as $video) {
            $video->setFeatured(true);
        }
        $this->entityManager->flush();

        $io->table(['Sujet', 'Langue', 'Fichiers', 'Couverture'], $rows);
        $io->success(sprintf('%d contenus créés sur %d sujets.', \count($rows), \count(self::SUBJECTS)));

        return Command::SUCCESS;
    }

    private function purge(SymfonyStyle $io): int
    {
        $videos = $this->entityManager->getRepository(Video::class)->createQueryBuilder('v')
            ->andWhere('v.slug LIKE :prefix')->setParameter('prefix', self::SLUG_PREFIX . '%')
            ->getQuery()->getResult();

        $subjects = [];
        foreach ($videos as $video) {
            $subjects[spl_object_id($video->getSubject())] = $video->getSubject();
            foreach ($video->getFiles() as $file) {
                $this->unlinkIfExists($this->downloadsDir, $file->getFileName());
            }
            $this->unlinkIfExists($this->coversDir, $video->getCoverImageName());
            // Fichiers déjà effacés ci-dessus : on vide les noms pour que Vich ne tente rien.
            foreach ($video->getFiles() as $file) {
                $file->setFileName(null);
            }
            $video->setCoverImageName(null);
            $this->entityManager->remove($video);
        }
        $this->entityManager->flush();

        $sessions = [];
        foreach ($subjects as $subject) {
            $this->entityManager->refresh($subject);
            if ($subject->getVideos()->isEmpty()) {
                $sessions[spl_object_id($subject->getCouncilSession())] = $subject->getCouncilSession();
                $this->entityManager->remove($subject);
            }
        }
        $this->entityManager->flush();

        $removedSessions = 0;
        foreach ($sessions as $session) {
            $this->entityManager->refresh($session);
            if ($session->getSubjects()->isEmpty() && \array_key_exists($session->getDate()->format('Y-m-d'), self::SESSIONS)) {
                $this->entityManager->remove($session);
                ++$removedSessions;
            }
        }
        $this->entityManager->flush();

        $io->success(sprintf('%d contenus, %d sujets et %d conseils de démonstration supprimés.', \count($videos), \count($subjects), $removedSessions));

        return Command::SUCCESS;
    }

    /**
     * @return array<string, array{path: string, mime: string, duration: int}> indexé par type MIME
     */
    private function findSourceFiles(): array
    {
        $sources = [];
        $files = $this->entityManager->getRepository(VideoFile::class)->createQueryBuilder('f')
            ->innerJoin('f.video', 'v')
            ->andWhere('f.fileName IS NOT NULL')
            ->andWhere('v.slug NOT LIKE :prefix')->setParameter('prefix', self::SLUG_PREFIX . '%')
            ->getQuery()->getResult();

        foreach ($files as $file) {
            $path = $this->downloadsDir . '/' . $file->getFileName();
            $mime = $file->getMimeType();
            if ($mime === null || isset($sources[$mime]) || !is_file($path)) {
                continue;
            }
            $sources[$mime] = ['path' => $path, 'mime' => $mime, 'duration' => $this->probeDuration($path) ?? $file->getVideo()->getDurationSeconds()];
        }

        return $sources;
    }

    /**
     * Extrait quelques images des vidéos sources, recadrées en 16:9, pour
     * servir de couvertures. Sans ffmpeg, les contenus restent sans couverture.
     *
     * @return string[] chemins des images générées
     */
    private function generateCovers(array $sources, SymfonyStyle $io): array
    {
        $dir = sys_get_temp_dir() . '/gppn-demo-covers';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $covers = [];
        foreach (['video/mp4', 'video/quicktime'] as $mime) {
            if (!isset($sources[$mime])) {
                continue;
            }
            $duration = max(1, $sources[$mime]['duration']);
            foreach ([0.08, 0.22, 0.37, 0.52, 0.68, 0.84] as $i => $ratio) {
                $target = sprintf('%s/%s-%d.jpg', $dir, str_replace('/', '-', $mime), $i);
                $process = new Process([
                    'ffmpeg', '-y', '-loglevel', 'error',
                    '-ss', (string) round($duration * $ratio, 1),
                    '-i', $sources[$mime]['path'],
                    '-frames:v', '1',
                    '-vf', 'crop=iw:iw*9/16:0:(ih-iw*9/16)/2-ih*0.12,scale=1280:720',
                    '-q:v', '3',
                    $target,
                ]);
                try {
                    $process->run();
                } catch (\Throwable) {
                    $io->warning('ffmpeg introuvable : les contenus seront créés sans couverture.');

                    return [];
                }
                if ($process->isSuccessful() && is_file($target)) {
                    $covers[] = $target;
                }
            }
        }

        return $covers;
    }

    private function probeDuration(string $path): ?int
    {
        $process = new Process(['ffprobe', '-v', 'error', '-show_entries', 'format=duration', '-of', 'default=nw=1:nk=1', $path]);
        try {
            $process->run();
        } catch (\Throwable) {
            return null;
        }

        return $process->isSuccessful() ? (int) round((float) trim($process->getOutput())) : null;
    }

    /**
     * Lien physique vers le fichier source (repli sur une copie si le système
     * de fichiers ne le permet pas), sous un nom unique à la manière de Vich.
     */
    private function linkInto(string $source, string $dir, string $baseName): string
    {
        $extension = strtolower(pathinfo($source, PATHINFO_EXTENSION));
        $name = sprintf('%s-%s.%s', $baseName, uniqid(), $extension);
        $target = $dir . '/' . $name;

        if (!@link($source, $target) && !copy($source, $target)) {
            throw new \RuntimeException(sprintf('Impossible de créer « %s ».', $target));
        }

        return $name;
    }

    private function unlinkIfExists(string $dir, ?string $name): void
    {
        if ($name !== null && is_file($dir . '/' . $name)) {
            unlink($dir . '/' . $name);
        }
    }

    private function findOrCreateSession(string $date, string $label): CouncilSession
    {
        $day = new \DateTimeImmutable($date);
        $session = $this->entityManager->getRepository(CouncilSession::class)->findOneBy(['date' => $day]);
        if ($session === null) {
            $session = (new CouncilSession())->setDate($day)->setLabel($label)->setSlug('conseil-du-' . $date);
            $this->entityManager->persist($session);
        }

        return $session;
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    private function findOneOrFail(string $class, array $criteria): object
    {
        return $this->entityManager->getRepository($class)->findOneBy($criteria)
            ?? throw new \RuntimeException(sprintf('%s introuvable : %s', $class, json_encode($criteria)));
    }

    private function slugify(string $text): string
    {
        return (new AsciiSlugger('fr'))->slug(str_replace('’', ' ', $text))->lower()->toString();
    }
}
