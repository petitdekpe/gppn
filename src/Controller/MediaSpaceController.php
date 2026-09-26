<?php

namespace App\Controller;

use App\Entity\Language;
use App\Entity\Subject;
use App\Entity\VideoFile;
use App\Enum\CapsuleFormat;
use App\Repository\CouncilSessionRepository;
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
        CouncilSessionRepository $councilSessionRepository,
    ): Response {
        $subjectRows = $subjectRepository->findAllWithVideoCount();

        $subjectIds = array_map('intval', $request->query->all('sujet'));
        $selectedSubjects = array_values(array_filter(
            array_column($subjectRows, 'subject'),
            static fn (Subject $subject): bool => in_array($subject->getId(), $subjectIds, true),
        ));

        // Le conseil des ministres reste facultatif : il ne fait que réduire
        // la liste de sujets proposée à l'étape 1, sans jamais faire partie
        // des critères du lot lui-même (voir VideoFileRepository::findForLot,
        // toujours bâti à partir des sujets/langues/formats sélectionnés).
        $councilSessionRows = array_values(array_filter(
            $councilSessionRepository->findAllWithVideoCount(),
            static fn (array $row): bool => $row['videoCount'] > 0,
        ));
        $councilSessionIds = array_map('intval', $request->query->all('conseil'));
        $visibleSubjectRows = $councilSessionIds === []
            ? $subjectRows
            : array_values(array_filter(
                $subjectRows,
                static fn (array $row): bool => in_array($row['subject']->getCouncilSession()->getId(), $councilSessionIds, true),
            ));

        // Un sujet déjà sélectionné doit rester dans le lot même si un filtre
        // par conseil des ministres masque ensuite sa case à cocher — sans
        // quoi il disparaîtrait silencieusement du lot au prochain rechargement.
        $visibleSubjectIds = array_map(static fn (array $row): int => $row['subject']->getId(), $visibleSubjectRows);
        $hiddenSelectedSubjectIds = array_values(array_diff($subjectIds, $visibleSubjectIds));

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
            'visibleSubjectRows' => $visibleSubjectRows,
            'hiddenSelectedSubjectIds' => $hiddenSelectedSubjectIds,
            'councilSessionRows' => $councilSessionRows,
            'selectedCouncilSessionIds' => $councilSessionIds,
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
        $councilSessionIds = array_map('intval', $request->request->all('conseil'));

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
                'conseil' => $councilSessionIds,
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
