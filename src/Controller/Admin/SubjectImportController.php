<?php

namespace App\Controller\Admin;

use App\Entity\Language;
use App\Entity\Speaker;
use App\Entity\Subject;
use App\Entity\Video;
use App\Entity\VideoFile;
use App\Enum\VideoFileType;
use App\Repository\LanguageRepository;
use App\Repository\SpeakerRepository;
use App\Repository\VideoRepository;
use App\Service\VideoSlugger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Import en masse des fichiers d'un sujet. Les fichiers sont nommés
 * INTERVENANT-LANGUE-FORMAT (ex. MPMEPE-DENDI-TV.mp4) ; le navigateur en
 * déduit le contenu visé, l'éditeur vérifie et complète, puis chaque fichier
 * est envoyé séparément vers uploadFile(). Un contenu (sujet + langue +
 * intervenant) absent est créé en brouillon ; la publication reste manuelle.
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
    ): JsonResponse {
        if (!$this->isCsrfTokenValid($this->csrfTokenId($subject), $request->request->getString('_token'))) {
            return $this->error('Session expirée : rechargez la page puis relancez l’envoi.', Response::HTTP_FORBIDDEN);
        }

        $speaker = $speakerRepository->find($request->request->getInt('speaker'));
        $language = $languageRepository->find($request->request->getInt('language'));
        $type = self::FORMATS[$request->request->getString('format')] ?? null;
        $file = $request->files->get('file');

        if ($speaker === null || $language === null || $type === null) {
            return $this->error('Intervenant, langue ou format manquant.');
        }
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            return $this->error('Le fichier n’a pas été reçu : ' . ($file instanceof UploadedFile ? $file->getErrorMessage() : 'fichier absent') . '.');
        }

        $violations = $validator->validate($file, new Assert\File(
            maxSize: '2G',
            mimeTypes: $type->isAudio() ? self::AUDIO_MIME_TYPES : self::VIDEO_MIME_TYPES,
            mimeTypesMessage: $type->isAudio() ? 'Ce fichier n’est pas un audio pris en charge.' : 'Ce fichier n’est pas une vidéo prise en charge.',
        ));
        if (count($violations) > 0) {
            return $this->error($violations[0]->getMessage());
        }

        // Un contenu = sujet + langue + intervenant.
        $video = $videoRepository->findOneBy(['subject' => $subject, 'language' => $language, 'speaker' => $speaker], ['id' => 'ASC']);
        $created = $video === null;
        if ($created) {
            $video = (new Video())
                ->setSubject($subject)
                ->setLanguage($language)
                ->setSpeaker($speaker);
            $videoSlugger->assign($video);
            $entityManager->persist($video);
        }

        $videoFile = $video->getVideoFileByType($type);
        if ($videoFile?->getFileName() !== null && !$request->request->getBoolean('replace')) {
            return $this->error('Un fichier occupe déjà cet emplacement : cochez « Remplacer » pour l’écraser.', Response::HTTP_CONFLICT);
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

        $entityManager->flush();

        return new JsonResponse([
            'videoId' => $video->getId(),
            'created' => $created,
            'editUrl' => $this->generateUrl('admin_video_edit', ['id' => $video->getId()]),
        ]);
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
