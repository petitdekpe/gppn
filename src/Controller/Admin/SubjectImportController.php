<?php

namespace App\Controller\Admin;

use App\Entity\Language;
use App\Entity\Speaker;
use App\Entity\Subject;
use App\Entity\Video;
use App\Entity\VideoFile;
use App\Enum\VideoFileType;
use App\Enum\VideoStatus;
use App\Repository\LanguageRepository;
use App\Repository\SpeakerRepository;
use App\Repository\VideoRepository;
use App\Service\ChunkedUploadStorage;
use App\Service\VideoSlugger;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemException;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\ExceptionInterface as MessengerException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

use function Sentry\captureException;

/**
 * Import en masse des fichiers d'un sujet. Les fichiers sont nommés
 * INTERVENANT-LANGUE-FORMAT (ex. MPMEPE-DENDI-TV.mp4) ; le navigateur en
 * déduit le contenu visé, l'éditeur vérifie et complète, puis chaque fichier
 * est envoyé séparément vers uploadFile(). Un contenu (sujet + langue +
 * intervenant) absent est créé ; tout contenu alimenté par l'import est
 * publié, sauf s'il avait été masqué volontairement.
 */
#[Route('/admin/sujets/{id}/import-en-masse')]
#[IsGranted('ROLE_EDITEUR')]
class SubjectImportController extends AbstractController
{
    /**
     * Formats de l'import : la version allégée (480p) n'en fait pas partie.
     */
    private const FORMATS = [
        'TV' => VideoFileType::MP4_1080P,
        'MOBILE' => VideoFileType::MP4_VERTICAL,
        'AUDIO' => VideoFileType::AUDIO,
    ];

    private const CREATE_LOCK = 'gppn-video-create';
    private const MAX_FILE_SIZE = 2 * 1024 ** 3;

    /** Morceaux assez petits pour qu'une coupure coûte peu, assez gros pour limiter le nombre de requêtes. */
    private const CHUNK_SIZE = 8 * 1024 ** 2;

    private const CSRF_MESSAGE = 'Jeton de sécurité invalide ou expiré (la page est probablement ouverte depuis trop longtemps) : rechargez la page puis relancez l’envoi.';

    private const VIDEO_MIME_TYPES = ['video/mp4', 'video/quicktime', 'video/webm', 'video/x-matroska'];
    private const AUDIO_MIME_TYPES = ['audio/mpeg', 'audio/mp4', 'audio/x-m4a', 'audio/wav', 'audio/x-wav', 'audio/ogg'];

    #[Route('', name: 'admin_subject_import')]
    public function index(Subject $subject, SpeakerRepository $speakerRepository, LanguageRepository $languageRepository, VideoRepository $videoRepository): Response
    {
        $speakers = $speakerRepository->findBy([], ['fullName' => 'ASC']);

        return $this->render('admin/subject_import/index.html.twig', [
            'subject' => $subject,
            'config' => [
                'uploadUrl' => $this->generateUrl('admin_subject_import_file', ['id' => $subject->getId()]),
                'statusUrl' => $this->generateUrl('admin_subject_import_status', ['id' => $subject->getId()]),
                'chunkUrl' => $this->generateUrl('admin_subject_import_chunk', ['id' => $subject->getId()]),
                'chunkSize' => $this->chunkSize(),
                'csrfToken' => $this->container->get('security.csrf.token_manager')->getToken($this->csrfTokenId($subject))->getValue(),
                'formats' => array_keys(self::FORMATS),
                'speakers' => array_map(fn (Speaker $speaker) => [
                    'id' => $speaker->getId(),
                    'sigle' => $speaker->getSigle(),
                    'code' => $speaker->getFileCode(),
                    'name' => $speaker->getFullName(),
                ], $speakers),
                'languages' => array_map(fn (Language $language) => [
                    'id' => $language->getId(),
                    'name' => $language->getName(),
                ], $languageRepository->findBy([], ['name' => 'ASC'])),
                'contents' => array_map(fn (Video $video) => [
                    'id' => $video->getId(),
                    'speakerId' => $video->getSpeaker()?->getId(),
                    'languageId' => $video->getLanguage()->getId(),
                    'slots' => array_map(
                        fn (VideoFileType $type) => $video->getVideoFileByType($type)?->getFileName() !== null
                            ? ($video->getVideoFileByType($type)->getOriginalName() ?? $video->getVideoFileByType($type)->getFileName())
                            : null,
                        self::FORMATS,
                    ),
                ], $videoRepository->findBy(['subject' => $subject])),
            ],
            'speakersWithoutSigle' => array_filter($speakers, fn (Speaker $speaker) => !$speaker->getSigle()),
        ]);
    }

    /**
     * Octets déjà reçus pour un envoi : 0 pour un nouveau fichier, davantage
     * pour un envoi interrompu que le navigateur reprend là où il s'est arrêté.
     */
    #[Route('/fichier/etat', name: 'admin_subject_import_status', methods: ['GET'])]
    public function uploadStatus(Subject $subject, Request $request, ChunkedUploadStorage $chunks): JsonResponse
    {
        $uploadId = $request->query->getString('upload');
        if (!ChunkedUploadStorage::isValidId($uploadId)) {
            return $this->error('Identifiant d’envoi invalide : rechargez la page.', Response::HTTP_BAD_REQUEST);
        }
        $chunks->purgeStale();

        return new JsonResponse(['offset' => $chunks->offset($this->chunkKey($subject, $uploadId))]);
    }

    /**
     * Un morceau du fichier, en corps brut : le fichier arrive en petites
     * requêtes qu'une coupure réseau n'oblige pas à tout renvoyer.
     */
    #[Route('/fichier/morceau', name: 'admin_subject_import_chunk', methods: ['POST'])]
    public function uploadChunk(Subject $subject, Request $request, ChunkedUploadStorage $chunks, LoggerInterface $logger): JsonResponse
    {
        if (!$this->isCsrfTokenValid($this->csrfTokenId($subject), (string) $request->headers->get('X-CSRF-Token'))) {
            return $this->error(self::CSRF_MESSAGE, Response::HTTP_FORBIDDEN);
        }

        $uploadId = $request->query->getString('upload');
        $offset = $request->query->getInt('offset');
        $total = $request->query->getInt('total');
        $length = (int) $request->server->get('CONTENT_LENGTH');
        if (!ChunkedUploadStorage::isValidId($uploadId) || $offset < 0 || $length <= 0) {
            return $this->error('Morceau mal formé (identifiant, position ou taille manquant) : rechargez la page.', Response::HTTP_BAD_REQUEST);
        }
        if ($total > self::MAX_FILE_SIZE) {
            return $this->error(sprintf('Fichier trop volumineux (%s) : la limite de l’import est de %s.', $this->formatBytes($total), $this->formatBytes(self::MAX_FILE_SIZE)), Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }
        if ($offset + $length > $total) {
            return $this->error('Le morceau dépasse la taille annoncée du fichier : retirez la ligne puis redéposez le fichier.', Response::HTTP_BAD_REQUEST);
        }
        $postMax = $this->iniBytes('post_max_size');
        if ($postMax > 0 && $length > $postMax) {
            return $this->error(sprintf('Morceau refusé par PHP : %s pour %s autorisés (réglage post_max_size). Rechargez la page pour que la taille des morceaux s’adapte.', $this->formatBytes($length), $this->formatBytes($postMax)), Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }

        try {
            $result = $chunks->append($this->chunkKey($subject, $uploadId), $offset, $length, $request->getContent(true));
        } catch (\Throwable $e) {
            $logger->error('Import en masse : morceau non écrit.', ['exception' => $e]);
            $reference = captureException($e);

            return new JsonResponse([
                'error' => 'Le serveur n’a pas pu enregistrer un morceau du fichier : ' . $e->getMessage(),
                'reference' => $reference ? (string) $reference : null,
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        // Position inattendue : le navigateur se recale sur celle du serveur.
        return new JsonResponse(['offset' => $result['offset']], $result['accepted'] ? Response::HTTP_OK : Response::HTTP_CONFLICT);
    }

    /**
     * Dernière étape : le fichier est complet côté serveur, on le range dans
     * son contenu (créé au besoin) et on publie ce contenu.
     */
    #[Route('/fichier', name: 'admin_subject_import_file', methods: ['POST'])]
    public function uploadFile(
        Subject $subject,
        Request $request,
        SpeakerRepository $speakerRepository,
        LanguageRepository $languageRepository,
        VideoRepository $videoRepository,
        VideoSlugger $videoSlugger,
        ValidatorInterface $validator,
        EntityManagerInterface $entityManager,
        ChunkedUploadStorage $chunks,
        LoggerInterface $logger,
    ): JsonResponse {
        if (!$this->isCsrfTokenValid($this->csrfTokenId($subject), $request->request->getString('_token'))) {
            return $this->error(self::CSRF_MESSAGE, Response::HTTP_FORBIDDEN);
        }

        $speaker = $speakerRepository->find($request->request->getInt('speaker'));
        $language = $languageRepository->find($request->request->getInt('language'));
        $format = $request->request->getString('format');
        $type = self::FORMATS[$format] ?? null;
        $uploadId = $request->request->getString('upload');

        $missing = array_filter([
            $speaker === null ? sprintf('intervenant introuvable (n° %d)', $request->request->getInt('speaker')) : null,
            $language === null ? sprintf('langue introuvable (n° %d)', $request->request->getInt('language')) : null,
            $type === null ? sprintf('format « %s » inconnu (attendus : %s)', $format, implode(', ', array_keys(self::FORMATS))) : null,
        ]);
        if ($missing !== []) {
            return $this->error('Envoi refusé : ' . implode(' ; ', $missing) . '. Un élément a peut-être été supprimé entre-temps : rechargez la page.');
        }
        if (!ChunkedUploadStorage::isValidId($uploadId)) {
            return $this->error('Identifiant d’envoi invalide : rechargez la page.', Response::HTTP_BAD_REQUEST);
        }

        $key = $this->chunkKey($subject, $uploadId);
        $received = $chunks->offset($key);
        $expected = $request->request->getInt('size');
        if ($received === 0 || $received !== $expected) {
            return $this->error(sprintf(
                'Fichier incomplet sur le serveur : %s reçus sur %s. Relancez l’envoi, il reprendra là où il s’est arrêté.',
                $this->formatBytes($received),
                $this->formatBytes($expected),
            ), Response::HTTP_CONFLICT);
        }

        $file = $chunks->file($key, $request->request->getString('name') ?: 'fichier');

        $violations = $validator->validate($file, new Assert\File(
            maxSize: self::MAX_FILE_SIZE,
            mimeTypes: $type->isAudio() ? self::AUDIO_MIME_TYPES : self::VIDEO_MIME_TYPES,
            maxSizeMessage: 'Fichier trop volumineux ({{ size }} {{ suffix }}) : la limite de l’import est de {{ limit }} {{ suffix }}.',
            mimeTypesMessage: $type->isAudio()
                ? 'Ce fichier n’est pas un audio pris en charge (type détecté : {{ type }} ; acceptés : MP3, M4A, WAV, OGG).'
                : 'Ce fichier n’est pas une vidéo prise en charge (type détecté : {{ type }} ; acceptés : MP4, MOV, WebM, MKV).',
        ));
        if (count($violations) > 0) {
            return $this->error($violations[0]->getMessage());
        }

        try {
            // Un contenu = sujet + langue + intervenant.
            [$video, $created] = $this->findOrCreateContent($subject, $language, $speaker, $videoRepository, $videoSlugger, $entityManager);

            $videoFile = $video->getVideoFileByType($type);
            if ($videoFile?->getFileName() !== null && !$request->request->getBoolean('replace')) {
                return new JsonResponse([
                    'error' => sprintf('Le format %s de ce contenu contient déjà « %s » : cochez « Remplacer » pour l’écraser.', $format, $videoFile->getOriginalName() ?? $videoFile->getFileName()),
                    'conflict' => true,
                ], Response::HTTP_CONFLICT);
            }
            if ($videoFile === null) {
                $videoFile = (new VideoFile())->setType($type);
                $video->addFile($videoFile);
            }
            $videoFile->setFile($file);

            // Durée lue par le navigateur, seulement si le contenu n'en a pas encore.
            $duration = (int) round((float) $request->request->get('duration'));
            if ($video->getDurationSeconds() === 0 && $duration > 0 && !$type->isAudio()) {
                $video->setDurationSeconds($duration);
            }

            // Tout contenu alimenté par l'import est mis en ligne. Un contenu
            // masqué l'a été volontairement : il le reste.
            $published = false;
            if ($video->getStatus() === VideoStatus::BROUILLON) {
                $video->setStatus(VideoStatus::PUBLIE)->setPublishedAt(new \DateTimeImmutable());
                $published = true;
            }

            $warning = null;
            try {
                $entityManager->flush();
            } catch (MessengerException $e) {
                // Levée après la validation en base (postFlush) : le fichier est
                // bien enregistré, seule la conversion WebM n'est pas en file.
                $logger->error('Import en masse : conversion WebM non mise en file.', ['exception' => $e, 'video' => $video->getId()]);
                $reference = captureException($e);
                $warning = 'Fichier enregistré, mais la conversion WebM de lecture n’a pas pu être mise en file (file de messages injoignable : ' . $e->getMessage() . '). À signaler à l’administrateur du serveur (commande app:video:transcode-webm).' . ($reference ? ' Référence Sentry : ' . $reference . '.' : '');
            }
        } catch (\Throwable $e) {
            return $this->serverError($e, $logger, $file);
        }

        $chunks->remove($key);

        return new JsonResponse([
            'videoId' => $video->getId(),
            'created' => $created,
            'published' => $published,
            'status' => $video->getStatus()->getLabel(),
            'warning' => $warning,
            'editUrl' => $this->generateUrl('admin_video_edit', ['id' => $video->getId()]),
        ]);
    }

    /**
     * Les contenus d'un même sujet partent en parallèle et reçoivent tous un
     * slug tiré du titre du sujet : sans verrou, deux créations simultanées
     * calculaient le même slug et la seconde échouait (erreur 500). Le contenu
     * est donc créé seul, en brouillon, sous verrou MySQL ; il n'est publié
     * qu'avec son fichier.
     *
     * @return array{Video, bool}
     */
    private function findOrCreateContent(Subject $subject, Language $language, Speaker $speaker, VideoRepository $videoRepository, VideoSlugger $videoSlugger, EntityManagerInterface $entityManager): array
    {
        $criteria = ['subject' => $subject, 'language' => $language, 'speaker' => $speaker];
        $video = $videoRepository->findOneBy($criteria, ['id' => 'ASC']);
        if ($video !== null) {
            return [$video, false];
        }

        $connection = $entityManager->getConnection();
        if ((int) $connection->fetchOne('SELECT GET_LOCK(?, 30)', [self::CREATE_LOCK]) !== 1) {
            throw new \RuntimeException('Un autre envoi crée un contenu depuis plus de 30 secondes : relancez l’envoi dans un instant.');
        }

        try {
            // Un autre envoi a pu créer ce même contenu pendant l'attente.
            $video = $videoRepository->findOneBy($criteria, ['id' => 'ASC']);
            if ($video !== null) {
                return [$video, false];
            }

            $video = (new Video())
                ->setSubject($subject)
                ->setLanguage($language)
                ->setSpeaker($speaker);
            $videoSlugger->assign($video);
            $entityManager->persist($video);
            $entityManager->flush();

            return [$video, true];
        } finally {
            $connection->fetchOne('SELECT RELEASE_LOCK(?)', [self::CREATE_LOCK]);
        }
    }

    /**
     * Erreur imprévue : consignée et remontée à Sentry, puis décrite à
     * l'éditeur au lieu d'une page 500 muette.
     */
    private function serverError(\Throwable $e, LoggerInterface $logger, UploadedFile $file): JsonResponse
    {
        $logger->error('Import en masse : échec de l’enregistrement.', ['exception' => $e, 'file' => $file->getClientOriginalName()]);
        $reference = captureException($e);

        $message = match (true) {
            $e instanceof FilesystemException => 'Le fichier n’a pas pu être écrit dans le stockage du serveur (disque plein, partage réseau indisponible ou droits insuffisants).',
            $e instanceof UniqueConstraintViolationException => 'La base de données a refusé l’enregistrement : une valeur censée être unique existe déjà (souvent deux envois simultanés du même contenu). Relancez l’envoi de ce fichier.',
            $e instanceof DbalException => 'La base de données a refusé l’enregistrement ou n’a pas répondu.',
            default => 'Erreur inattendue du serveur pendant l’enregistrement.',
        };

        return new JsonResponse([
            'error' => $message . ' Le fichier n’a pas été rangé dans son contenu, mais il reste sur le serveur : « Réessayer les échecs » relancera l’enregistrement sans le renvoyer.',
            'detail' => sprintf('%s : %s', (new \ReflectionClass($e))->getShortName(), $e->getMessage()),
            'reference' => $reference ? (string) $reference : null,
        ], Response::HTTP_INTERNAL_SERVER_ERROR);
    }

    /**
     * Taille des morceaux, ramenée sous post_max_size si PHP est réglé plus bas
     * (8 Mo par défaut, soit tout juste la taille visée).
     */
    private function chunkSize(): int
    {
        $postMax = $this->iniBytes('post_max_size');

        return $postMax > 0 ? max(256 * 1024, min(self::CHUNK_SIZE, $postMax - 256 * 1024)) : self::CHUNK_SIZE;
    }

    /** Partiel propre au sujet et à l'utilisateur connecté. */
    private function chunkKey(Subject $subject, string $uploadId): string
    {
        return sprintf('%d-%s-%s', $subject->getId(), substr(hash('sha256', (string) $this->getUser()?->getUserIdentifier()), 0, 12), $uploadId);
    }

    private function iniBytes(string $key): int
    {
        $value = trim((string) ini_get($key));
        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };
    }

    private function formatBytes(int $bytes): string
    {
        return $bytes >= 1024 ** 3
            ? str_replace('.', ',', sprintf('%.1f Go', $bytes / 1024 ** 3))
            : sprintf('%d Mo', max(1, (int) round($bytes / 1024 ** 2)));
    }

    private function csrfTokenId(Subject $subject): string
    {
        return 'subject-import-' . $subject->getId();
    }

    private function error(string $message, int $status = Response::HTTP_UNPROCESSABLE_ENTITY): JsonResponse
    {
        return new JsonResponse(['error' => $message], $status);
    }
}
