<?php

namespace App\Controller;

use App\Entity\Language;
use App\Entity\Subject;
use App\Entity\VideoFile;
use App\Enum\CapsuleFormat;
use App\Repository\LanguageRepository;
use App\Repository\SubjectRepository;
use App\Repository\VideoFileRepository;
use App\Service\VideoFileZipBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

class MediaSpaceController extends AbstractController
{
    private const CSRF_TOKEN_ID = 'media_space_download';

    #[Route('/espace-media', name: 'app_media_space')]
    public function index(
        Request $request,
        SubjectRepository $subjectRepository,
        LanguageRepository $languageRepository,
        VideoFileRepository $videoFileRepository,
    ): Response {
        $subjectRows = $subjectRepository->findAllWithVideoCount();

        $subjectIds = array_map('intval', $request->query->all('sujet'));
        $selectedSubjects = array_values(array_filter(
            array_column($subjectRows, 'subject'),
            static fn (Subject $subject): bool => in_array($subject->getId(), $subjectIds, true),
        ));

        // Étape 1 : un calendrier des conseils des ministres (comme dans
        // l'admin) n'affiche que les sujets du conseil choisi. Ce n'est qu'un
        // affichage : tous les sujets sont rendus, et un sujet coché reste
        // dans le lot quand on change de conseil (voir VideoFileRepository::findForLot,
        // toujours bâti à partir des sujets/langues/formats sélectionnés).
        $subjectRowsBySession = [];
        foreach ($subjectRows as $row) {
            $subjectRowsBySession[$row['subject']->getCouncilSession()->getId()][] = $row;
        }
        $calendar = array_values(array_map(static fn (array $rows): array => [
            'id' => $rows[0]['subject']->getCouncilSession()->getId(),
            'date' => $rows[0]['subject']->getCouncilSession()->getDate()->format('Y-m-d'),
            'count' => count($rows),
        ], $subjectRowsBySession));

        // Conseil affiché : celui demandé, sinon celui du premier sujet coché, sinon le plus récent.
        $requestedSession = $request->query->all()['conseil'] ?? null;
        $selectedSessionId = (int) (is_array($requestedSession) ? reset($requestedSession) : $requestedSession);
        if (!isset($subjectRowsBySession[$selectedSessionId])) {
            $selectedSessionId = $selectedSubjects !== []
                ? $selectedSubjects[0]->getCouncilSession()->getId()
                : array_key_first($subjectRowsBySession);
        }

        $availableLanguages = $languageRepository->findAvailableForSubjects($selectedSubjects);
        $languageIds = array_map('intval', $request->query->all('langue'));
        $selectedLanguages = array_values(array_filter(
            $availableLanguages,
            static fn (Language $language): bool => in_array($language->getId(), $languageIds, true),
        ));

        $availableFormats = $videoFileRepository->findAvailableFormatsForSubjects($selectedSubjects, $selectedLanguages);
        $formatValues = array_values(array_filter(array_map(
            static fn (mixed $value): ?string => CapsuleFormat::tryFrom((string) $value)?->value,
            $request->query->all('format'),
        )));
        $selectedFormats = array_values(array_filter(
            $availableFormats,
            static fn (CapsuleFormat $format): bool => in_array($format->value, $formatValues, true),
        ));

        $matchingFiles = $videoFileRepository->findForLot($selectedSubjects, $selectedLanguages, $selectedFormats);

        return $this->render('media_space/index.html.twig', [
            'subjectRowsBySession' => $subjectRowsBySession,
            'calendar' => $calendar,
            'selectedSessionId' => $selectedSessionId,
            'selectedSubjectIds' => $subjectIds,
            'availableLanguages' => $availableLanguages,
            'selectedLanguageIds' => $languageIds,
            'availableFormats' => $availableFormats,
            'selectedFormatValues' => $formatValues,
            'matchingFiles' => $matchingFiles,
            'totalSize' => array_sum(array_map(static fn (VideoFile $file): int => $file->getFileSize() ?? 0, $matchingFiles)),
            'csrfTokenId' => self::CSRF_TOKEN_ID,
        ]);
    }

    #[Route('/espace-media/telecharger', name: 'app_media_space_download', methods: ['POST'])]
    public function download(
        Request $request,
        SubjectRepository $subjectRepository,
        LanguageRepository $languageRepository,
        VideoFileRepository $videoFileRepository,
        VideoFileZipBuilder $zipBuilder,
    ): Response {
        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $subjectIds = array_map('intval', $request->request->all('sujet'));
        $languageIds = array_map('intval', $request->request->all('langue'));
        $formatValues = $request->request->all('format');
        $councilSessionId = $request->request->getInt('conseil');

        $subjects = $subjectIds ? $subjectRepository->findBy(['id' => $subjectIds]) : [];
        $languages = $languageIds ? $languageRepository->findBy(['id' => $languageIds]) : [];
        $formats = array_filter(array_map(
            static fn (mixed $value): ?CapsuleFormat => CapsuleFormat::tryFrom((string) $value),
            $formatValues,
        ));

        // Recalculé côté serveur à partir des critères soumis plutôt que
        // d'une liste d'identifiants de fichiers : un lot peut représenter
        // plusieurs dizaines de fichiers, et ne fait confiance qu'aux
        // sujets/langues/formats réellement choisis (voir VideoFileRepository::findForLot).
        $files = $videoFileRepository->findForLot($subjects, $languages, $formats);

        if ($files === []) {
            $this->addFlash('error', 'Merci de sélectionner au moins un sujet correspondant à un contenu téléchargeable.');

            return $this->redirectToRoute('app_media_space', array_filter([
                'sujet' => $subjectIds,
                'langue' => $languageIds,
                'format' => $formatValues,
                'conseil' => $councilSessionId ?: null,
            ]));
        }

        $zipPath = $zipBuilder->build($files, $this->buildAttributionSheet($files));

        $response = new BinaryFileResponse($zipPath);
        $response->deleteFileAfterSend(true);
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            sprintf('lot-gppn-%s.zip', (new \DateTimeImmutable())->format('Y-m-d-His')),
        );

        return $response;
    }

    /**
     * @param VideoFile[] $files
     */
    private function buildAttributionSheet(array $files): string
    {
        $lines = [
            'LE GOUVERNEMENT PLUS PRÈS DE NOUS — FICHE D’ATTRIBUTION',
            'Lot généré le ' . (new \DateTimeImmutable())->format('d/m/Y à H:i'),
            '',
            'Contenu de ce lot :',
        ];

        foreach ($files as $file) {
            $video = $file->getVideo();
            $lines[] = sprintf(
                '- %s | %s | %s | %s',
                $video->getTitle(),
                $video->getThematic()->getName(),
                $video->getLanguage()->getName(),
                $file->getType()->getLabel(),
            );
        }

        $lines[] = '';
        $lines[] = 'Mention obligatoire à la diffusion : « Le Gouvernement Plus Près de Nous - République du Bénin. »';

        return implode("\n", $lines);
    }
}
