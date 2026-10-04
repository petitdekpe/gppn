<?php

namespace App\Controller;

use App\Entity\CouncilSession;
use App\Repository\CouncilSessionRepository;
use App\Repository\VideoRepository;
use App\Service\SpeakerKit;
use App\Service\SpeakerPeriodCriteria;
use App\Service\VideoFileZipBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Pages des intervenants (ministres) : leurs contenus par conseil des
 * ministres, à télécharger en vidéo Mobile, et pour chaque conseil un kit
 * (vidéo TV, vidéo Mobile et audio MP3, contenus de ce seul intervenant) à transmettre à ses
 * télévisions, radios et groupes WhatsApp par un lien signé. Le kit se
 * télécharge librement, sans compte (contrairement aux lots de l'espace média).
 */
class IntervenantController extends AbstractController
{
    #[Route('/intervenants', name: 'app_intervenant_index')]
    public function index(SpeakerPeriodCriteria $criteria): Response
    {
        // Ordre de préséance (gouv.bj/membres), puis les rangs non renseignés
        // (anciens ministres, ministres conseillers absents de gouv.bj) par ordre alphabétique.
        $people = $criteria->people();
        usort($people, static fn (array $a, array $b) => [$a['precedence'] === null, $a['precedence']] <=> [$b['precedence'] === null, $b['precedence']]);

        return $this->render('intervenant/index.html.twig', [
            'ministers' => array_values(array_filter($people, static fn (array $person) => !$person['councillor'])),
            'councillors' => array_values(array_filter($people, static fn (array $person) => $person['councillor'])),
        ]);
    }

    #[Route('/intervenants/{person}', name: 'app_intervenant_show')]
    public function show(string $person, SpeakerPeriodCriteria $criteria, VideoRepository $videoRepository, SpeakerKit $kit): Response
    {
        $entry = $criteria->person($person) ?? throw $this->createNotFoundException('Intervenant introuvable.');

        return $this->render('intervenant/show.html.twig', [
            'person' => $entry,
            'councils' => $kit->groupByCouncil($videoRepository->findPublishedForSpeakers($entry['speakerIds'])),
            'parts' => SpeakerKit::PARTS,
        ]);
    }

    /**
     * Page du kit, ouverte par le lien transmis : liste des fichiers par
     * langue et archive complète. Lien signé, sans compte.
     */
    #[Route('/kit/{person}/{council}/{format}', name: 'app_kit_show', requirements: ['format' => 'kit'])]
    public function kit(string $person, string $council, string $format, Request $request, UriSigner $uriSigner, SpeakerPeriodCriteria $criteria, CouncilSessionRepository $councilSessionRepository, VideoRepository $videoRepository, SpeakerKit $kit): Response
    {
        [$entry, $councilSession] = $this->resolveKit($person, $council, $request, $uriSigner, $criteria, $councilSessionRepository);
        if ($entry === null) {
            return $this->render('intervenant/kit_invalid.html.twig', [], new Response('', Response::HTTP_FORBIDDEN));
        }

        $videos = $videoRepository->findPublishedForSpeakers($entry['speakerIds'], $councilSession);

        return $this->render('intervenant/kit.html.twig', [
            'person' => $entry,
            'council' => $councilSession,
            'format' => $format,
            'formatInfo' => SpeakerKit::FORMATS[$format],
            'parts' => SpeakerKit::PARTS,
            'items' => $kit->items($videos, $format),
            'counts' => $kit->counts($videos, $format),
            'zipUrl' => $kit->zipUrl($entry['slug'], $councilSession, $format),
        ]);
    }

    #[Route('/kit/{person}/{council}/{format}/telecharger', name: 'app_kit_download', requirements: ['format' => 'kit'])]
    public function download(string $person, string $council, string $format, Request $request, UriSigner $uriSigner, SpeakerPeriodCriteria $criteria, CouncilSessionRepository $councilSessionRepository, VideoRepository $videoRepository, SpeakerKit $kit, VideoFileZipBuilder $zipBuilder): Response
    {
        [$entry, $councilSession] = $this->resolveKit($person, $council, $request, $uriSigner, $criteria, $councilSessionRepository);
        if ($entry === null) {
            return $this->render('intervenant/kit_invalid.html.twig', [], new Response('', Response::HTTP_FORBIDDEN));
        }

        $files = $kit->files($videoRepository->findPublishedForSpeakers($entry['speakerIds'], $councilSession), $format);
        if ($files === []) {
            throw $this->createNotFoundException('Ce kit ne contient plus aucun fichier.');
        }

        $title = sprintf('%s — %s — %s', SpeakerKit::FORMATS[$format]['label'], $entry['name'], $councilSession->getTitle());
        // Rangé en Video TV/, Audio MP3/ et Video Mobile/ : chaque destinataire trouve tout de suite ses fichiers.
        $response = new BinaryFileResponse($zipBuilder->build($files, $zipBuilder->attributionSheet($files, $title), $kit->folderFor(...)));
        $response->deleteFileAfterSend(true);
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            sprintf('kit-%s-%s-%s.zip', $format, $entry['slug'], $councilSession->getDate()->format('Y-m-d')),
        );

        return $response;
    }

    /**
     * Lien signé valide, intervenant et conseil existants ; sinon [null, null]
     * (lien recopié de travers, modifié, ou contenu retiré depuis).
     *
     * @return array{0: ?array, 1: ?CouncilSession}
     */
    private function resolveKit(string $person, string $council, Request $request, UriSigner $uriSigner, SpeakerPeriodCriteria $criteria, CouncilSessionRepository $councilSessionRepository): array
    {
        if (!$uriSigner->checkRequest($request)) {
            return [null, null];
        }
        $entry = $criteria->person($person);
        $councilSession = $councilSessionRepository->findOneBy(['slug' => $council]);

        return $entry !== null && $councilSession !== null ? [$entry, $councilSession] : [null, null];
    }
}
