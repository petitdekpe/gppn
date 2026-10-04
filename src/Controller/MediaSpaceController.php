<?php

namespace App\Controller;

use App\Entity\Language;
use App\Entity\Subject;
use App\Entity\User;
use App\Entity\VideoFile;
use App\Enum\CapsuleFormat;
use App\Repository\LanguageRepository;
use App\Repository\LotPreferenceRepository;
use App\Repository\SubjectRepository;
use App\Repository\VideoFileRepository;
use App\Search\SpeakerPeriodFilter;
use App\Service\LotPreferences;
use App\Service\MediaAccess;
use App\Service\SpeakerPeriodCriteria;
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
        SpeakerPeriodCriteria $speakerPeriodCriteria,
        MediaAccess $mediaAccess,
        LotPreferences $lotPreferences,
        LotPreferenceRepository $lotPreferenceRepository,
    ): Response {
        // Réservé à la presse et aux médias : connexion ou inscription, puis retour ici (choix conservés).
        $user = $this->getUser();
        if (!$mediaAccess->isMedia() || !$user instanceof User) {
            return $this->redirectToRoute('app_press_login', ['retour' => $request->getRequestUri()]);
        }

        // Préférence choisie : ses langues, formats et intervenant remplacent
        // ceux en cours (la période aussi, qu'elle ne retient pas) ; les sujets cochés restent.
        if ($request->query->has('preference')) {
            $params = $request->query->all();
            $preference = $lotPreferenceRepository->findOneForUser($user, $request->query->getInt('preference'));
            if ($preference !== null) {
                unset($params['langue'], $params['format'], $params['intervenant'], $params['du'], $params['au'], $params['periode']);
                $params += $lotPreferences->queryParams($lotPreferences->choicesOf($preference));
            }
            unset($params['preference']);

            return $this->redirectToRoute('app_media_space', $params);
        }

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

        // Raccourci facultatif « Intervenant et période » : sans sujet coché,
        // il suffit à composer le lot ; avec des sujets cochés, il les restreint.
        $speakerPeriod = $speakerPeriodCriteria->fromParams($request->query->all());
        $lotSubjects = $this->lotSubjects($selectedSubjects, $speakerPeriod, $subjectRepository);

        // Langues et formats cochés, même absents des sujets en cours (par
        // exemple venus d'une préférence avant le choix des sujets) : ils
        // restent cochés et filtrent le lot, comme au téléchargement.
        $availableLanguages = $languageRepository->findAvailableForSubjects($lotSubjects, $speakerPeriod);
        $languageIds = array_map('intval', $request->query->all('langue'));
        $selectedLanguages = $languageIds !== [] ? $languageRepository->findBy(['id' => $languageIds]) : [];
        $languageIds = array_map(static fn (Language $language): int => $language->getId(), $selectedLanguages);
        $languageOptions = $availableLanguages;
        foreach ($selectedLanguages as $language) {
            if (!in_array($language, $languageOptions, true)) {
                $languageOptions[] = $language;
            }
        }

        $availableFormats = $videoFileRepository->findAvailableFormatsForSubjects($lotSubjects, $selectedLanguages, $speakerPeriod);
        $formatValues = array_values(array_filter(array_map(
            static fn (mixed $value): ?string => CapsuleFormat::tryFrom((string) $value)?->value,
            $request->query->all('format'),
        )));
        $selectedFormats = array_map(static fn (string $value): CapsuleFormat => CapsuleFormat::from($value), $formatValues);
        $formatOptions = array_values(array_filter(
            CapsuleFormat::cases(),
            static fn (CapsuleFormat $format): bool => in_array($format, $availableFormats, true) || in_array($format, $selectedFormats, true),
        ));

        $matchingFiles = $videoFileRepository->findForLot($lotSubjects, $selectedLanguages, $selectedFormats, $speakerPeriod);
        $currentChoices = $lotPreferences->choicesFromParams($request->query->all());

        return $this->render('media_space/index.html.twig', [
            'subjectRowsBySession' => $subjectRowsBySession,
            'calendar' => $calendar,
            'selectedSessionId' => $selectedSessionId,
            'selectedSubjectIds' => $subjectIds,
            'availableLanguages' => $availableLanguages,
            'languageOptions' => $languageOptions,
            'selectedLanguageIds' => $languageIds,
            'availableFormats' => $availableFormats,
            'formatOptions' => $formatOptions,
            'selectedFormatValues' => $formatValues,
            'matchingFiles' => $matchingFiles,
            'totalSize' => array_sum(array_map(static fn (VideoFile $file): int => $file->getFileSize() ?? 0, $matchingFiles)),
            'csrfTokenId' => self::CSRF_TOKEN_ID,
            'speakerPeriod' => $speakerPeriod,
            'people' => $speakerPeriodCriteria->people(),
            'periodShortcuts' => $speakerPeriodCriteria->periodShortcuts(),
            'lotSubjectCount' => count($lotSubjects),
        ] + $lotPreferences->panel($user, $currentChoices));
    }

    #[Route('/espace-media/telecharger', name: 'app_media_space_download', methods: ['POST'])]
    public function download(
        Request $request,
        SubjectRepository $subjectRepository,
        LanguageRepository $languageRepository,
        VideoFileRepository $videoFileRepository,
        VideoFileZipBuilder $zipBuilder,
        SpeakerPeriodCriteria $speakerPeriodCriteria,
        MediaAccess $mediaAccess,
    ): Response {
        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }
        // Session expirée depuis l'affichage de la page : connexion, puis retour à l'espace média.
        if (!$mediaAccess->canDownloadBundles()) {
            return $this->redirectToRoute('app_press_login', ['retour' => $this->generateUrl('app_media_space')]);
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
        $speakerPeriod = $speakerPeriodCriteria->fromParams($request->request->all());
        $files = $videoFileRepository->findForLot($this->lotSubjects($subjects, $speakerPeriod, $subjectRepository), $languages, $formats, $speakerPeriod);

        if ($files === []) {
            $this->addFlash('error', 'Aucun fichier téléchargeable ne correspond à cette sélection : choisissez au moins un sujet, ou un intervenant et une période.');

            return $this->redirectToRoute('app_media_space', array_filter([
                'sujet' => $subjectIds,
                'langue' => $languageIds,
                'format' => $formatValues,
                'conseil' => $councilSessionId ?: null,
            ]) + $speakerPeriod->queryParams());
        }

        $zipPath = $zipBuilder->build($files, $zipBuilder->attributionSheet($files));

        $response = new BinaryFileResponse($zipPath);
        $response->deleteFileAfterSend(true);
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            sprintf('lot-gppn-%s.zip', (new \DateTimeImmutable())->format('Y-m-d-His')),
        );

        return $response;
    }

    /**
     * Sujets du lot : ceux cochés ; à défaut, avec le raccourci « Intervenant
     * et période », tous ceux où cet intervenant (ou cette période) a des contenus.
     *
     * @param Subject[] $selectedSubjects
     *
     * @return Subject[]
     */
    private function lotSubjects(array $selectedSubjects, SpeakerPeriodFilter $speakerPeriod, SubjectRepository $subjectRepository): array
    {
        if ($selectedSubjects !== [] || !$speakerPeriod->isActive()) {
            return $selectedSubjects;
        }

        return $subjectRepository->findMatching($speakerPeriod);
    }

}
