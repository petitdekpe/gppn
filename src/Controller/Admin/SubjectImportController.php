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
        LoggerInterface $logger,
    ): JsonResponse {
        // Corps dépassant post_max_size : PHP le jette en entier, jeton CSRF
        // compris. Sans ce test, l'éditeur lirait « session expirée ».
        $contentLength = (int) $request->server->get('CONTENT_LENGTH');
        if ($contentLength > 0 && $request->request->count() === 0 && $request->files->count() === 0) {
            return $this->error(sprintf(
                'Fichier refusé par PHP : l’envoi pèse %s alors que le serveur accepte au plus %s par requête (réglage post_max_size). Demandez à l’administrateur du serveur de relever post_max_size et upload_max_filesize.',
                $this->formatBytes($contentLength),
                $this->formatBytes($this->iniBytes('post_max_size')),
            ), Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }

        if (!$this->isCsrfTokenValid($this->csrfTokenId($subject), $request->request->getString('_token'))) {
            return $this->error('Jeton de sécurité invalide ou expiré (la page est probablement ouverte depuis trop longtemps) : rechargez la page puis relancez l’envoi.', Response::HTTP_FORBIDDEN);
        }

        $speaker = $speakerRepository->find($request->request->getInt('speaker'));
        $language = $languageRepository->find($request->request->getInt('language'));
        $format = $request->request->getString('format');
        $type = self::FORMATS[$format] ?? null;
        $file = $request->files->get('file');

        $missing = array_filter([
            $speaker === null ? sprintf('intervenant introuvable (n° %d)', $request->request->getInt('speaker')) : null,
            $language === null ? sprintf('langue introuvable (n° %d)', $request->request->getInt('language')) : null,
            $type === null ? sprintf('format « %s » inconnu (attendus : %s)', $format, implode(', ', array_keys(self::FORMATS))) : null,
        ]);
        if ($missing !== []) {
            return $this->error('Envoi refusé : ' . implode(' ; ', $missing) . '. Un élément a peut-être été supprimé entre-temps : rechargez la page.');
        }
        if (!$file instanceof UploadedFile) {
            return $this->error('Aucun fichier dans la requête : le navigateur n’a rien transmis. Retirez la ligne puis redéposez le fichier.');
        }
        if (!$file->isValid()) {
            return $this->error('Le fichier n’a pas été reçu en entier : ' . $this->uploadErrorMessage($file->getError()));
        }

        $violations = $validator->validate($file, new Assert\File(
            maxSize: '2G',
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
            'error' => $message . ' Le fichier n’a pas été enregistré.',
            'detail' => sprintf('%s : %s', (new \ReflectionClass($e))->getShortName(), $e->getMessage()),
            'reference' => $reference ? (string) $reference : null,
        ], Response::HTTP_INTERNAL_SERVER_ERROR);
    }

    private function uploadErrorMessage(int $code): string
    {
        return match ($code) {
            \UPLOAD_ERR_INI_SIZE => sprintf('il dépasse la taille maximale acceptée par PHP (%s, réglage upload_max_filesize). Demandez à l’administrateur du serveur de relever cette limite.', $this->formatBytes($this->iniBytes('upload_max_filesize'))),
            \UPLOAD_ERR_FORM_SIZE => 'il dépasse la taille maximale fixée par le formulaire.',
            \UPLOAD_ERR_PARTIAL => 'la connexion a été coupée en cours d’envoi. Relancez l’envoi.',
            \UPLOAD_ERR_NO_FILE => 'aucun fichier transmis. Retirez la ligne puis redéposez le fichier.',
            \UPLOAD_ERR_NO_TMP_DIR => 'le dossier temporaire de PHP est absent sur le serveur (réglage upload_tmp_dir). À signaler à l’administrateur du serveur.',
            \UPLOAD_ERR_CANT_WRITE => 'PHP n’a pas pu écrire le fichier dans son dossier temporaire (disque du serveur probablement plein). À signaler à l’administrateur du serveur.',
            \UPLOAD_ERR_EXTENSION => 'une extension PHP a interrompu l’envoi. À signaler à l’administrateur du serveur.',
            default => sprintf('erreur d’envoi PHP inconnue (code %d).', $code),
        };
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
