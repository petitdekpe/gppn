<?php

namespace App\Controller\Admin;

use App\Entity\Speaker;
use App\Form\Admin\SpeakerType;
use App\Repository\GovernmentRepository;
use App\Repository\SpeakerRepository;
use App\Service\SpeakerImporter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/intervenants')]
#[IsGranted('ROLE_EDITEUR')]
class SpeakerController extends AbstractController
{
    use BulkActionTrait;

    /**
     * Intervenants rangés par gouvernement : l'actuel en tête, puis du plus
     * récent au plus ancien, et enfin ceux qui n'appartiennent à aucun.
     */
    #[Route('', name: 'admin_speaker_index')]
    public function index(SpeakerRepository $speakerRepository, GovernmentRepository $governmentRepository): Response
    {
        $governments = $governmentRepository->findOrdered();
        $groups = [];
        foreach ($governments as $government) {
            $groups[$government->getId()] = ['government' => $government, 'speakers' => []];
        }
        $groups[0] = ['government' => null, 'speakers' => []];

        foreach ($speakerRepository->findBy([], ['fullName' => 'ASC']) as $speaker) {
            $groups[$speaker->getGovernment()?->getId() ?? 0]['speakers'][] = $speaker;
        }
        if ($groups[0]['speakers'] === []) {
            unset($groups[0]);
        }

        return $this->render('admin/speaker/index.html.twig', [
            'groups' => $groups,
            'governments' => $governments,
            'videoCounts' => $this->videoCounts($speakerRepository),
        ]);
    }

    #[Route('/actions-groupees', name: 'admin_speaker_bulk', methods: ['POST'])]
    public function bulk(Request $request, SpeakerRepository $speakerRepository, GovernmentRepository $governmentRepository, EntityManagerInterface $entityManager): Response
    {
        $ids = $this->bulkIds($request, 'bulk-speaker');
        if ($ids === null) {
            return $this->redirectToRoute('admin_speaker_index');
        }

        $action = $request->request->getString('action');
        if ($action === 'edit') {
            return $this->redirectToRoute('admin_speaker_bulk_edit', ['ids' => implode(',', $ids)]);
        }

        $speakers = $speakerRepository->findBy(['id' => $ids], ['fullName' => 'ASC']);
        $target = $governmentRepository->find($request->request->getInt('target'));
        $skipped = [];

        switch ($action) {
            case 'reappoint':
                if ($target === null) {
                    $this->addFlash('error', 'Choisissez le gouvernement dans lequel reconduire ces intervenants.');

                    return $this->redirectToRoute('admin_speaker_index');
                }
                $present = array_flip(array_map(fn (Speaker $s) => Speaker::nameKey($s->getFullName()), $target->getSpeakers()->toArray()));
                $done = 0;
                foreach ($speakers as $speaker) {
                    $key = Speaker::nameKey($speaker->getFullName());
                    if (isset($present[$key])) {
                        $skipped[] = $speaker->getFullName() . ' (déjà dans ce gouvernement)';

                        continue;
                    }
                    $entityManager->persist($speaker->reappointIn($target));
                    $present[$key] = true;
                    ++$done;
                }
                $message = sprintf('%s reconduit%s dans « %s ». Ajustez fonctions et sigles si les portefeuilles ont changé.', self::plural($done, 'intervenant'), $done > 1 ? 's' : '', $target->getLabel());
                break;
            case 'move':
                foreach ($speakers as $speaker) {
                    $speaker->setGovernment($target);
                }
                $message = sprintf('%s déplacé%s vers « %s ».', self::plural(count($speakers), 'intervenant'), count($speakers) > 1 ? 's' : '', $target?->getLabel() ?? 'Hors gouvernement');
                break;
            case 'delete':
                $counts = $this->videoCounts($speakerRepository);
                $done = 0;
                foreach ($speakers as $speaker) {
                    if (($counts[$speaker->getId()] ?? 0) > 0) {
                        $skipped[] = $speaker->getFullName() . ' (rattaché à des contenus)';

                        continue;
                    }
                    $entityManager->remove($speaker);
                    ++$done;
                }
                $message = self::plural($done, 'intervenant supprimé', 'intervenants supprimés') . '.';
                break;
            default:
                $this->unknownBulkAction();

                return $this->redirectToRoute('admin_speaker_index');
        }

        $entityManager->flush();
        $this->bulkReport($message, $skipped);

        return $this->redirectToRoute('admin_speaker_index');
    }

    /**
     * Modification en masse : une grille éditable (nom, fonction, sigle,
     * gouvernement) pour les intervenants cochés, ou tout un gouvernement.
     */
    #[Route('/modifier-en-masse', name: 'admin_speaker_bulk_edit')]
    public function bulkEdit(Request $request, SpeakerRepository $speakerRepository, GovernmentRepository $governmentRepository, EntityManagerInterface $entityManager): Response
    {
        $governmentId = $request->query->getInt('gouvernement');
        $speakers = $governmentId > 0
            ? $speakerRepository->findBy(['government' => $governmentId], ['fullName' => 'ASC'])
            : $speakerRepository->findBy(['id' => array_filter(array_map('intval', explode(',', $request->query->getString('ids'))))], ['fullName' => 'ASC']);
        if ($speakers === []) {
            $this->addFlash('error', 'Aucun intervenant à modifier.');

            return $this->redirectToRoute('admin_speaker_index');
        }

        $governments = $governmentRepository->findOrdered();
        $errors = [];
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('bulk-edit-speaker', $request->request->getString('_token'))) {
                $this->addFlash('error', 'Jeton de sécurité expiré : rechargez la page puis recommencez.');

                return $this->redirect($request->getUri());
            }

            $byId = [];
            foreach ($governments as $government) {
                $byId[$government->getId()] = $government;
            }
            $input = $request->request->all('speakers');
            foreach ($speakers as $speaker) {
                $row = $input[$speaker->getId()] ?? null;
                if (!is_array($row)) {
                    continue;
                }
                $fullName = trim(preg_replace('/\s+/u', ' ', (string) ($row['fullName'] ?? '')));
                $role = trim((string) ($row['role'] ?? ''));
                $sigle = strtoupper(preg_replace('/\s+/u', '', (string) ($row['sigle'] ?? '')));
                $error = match (true) {
                    $fullName === '' => 'le nom complet est obligatoire',
                    mb_strlen($fullName) > 150 || mb_strlen($role) > 150 => 'nom ou fonction trop long (150 caractères au plus)',
                    mb_strlen($sigle) > 20 => 'sigle trop long (20 caractères au plus)',
                    default => null,
                };
                // Valeurs saisies conservées dans la grille, même en erreur.
                $speaker->setFullName($fullName)->setRole($role !== '' ? $role : null)->setSigle($sigle !== '' ? $sigle : null)
                    ->setGovernment($byId[(int) ($row['government'] ?? 0)] ?? null);
                if ($error !== null) {
                    $errors[$speaker->getId()] = $error;
                }
            }

            if ($errors === []) {
                $entityManager->flush();
                $this->addFlash('success', self::plural(count($speakers), 'intervenant mis à jour', 'intervenants mis à jour') . '.');

                return $this->redirectToRoute('admin_speaker_index');
            }
        }

        return $this->render('admin/speaker/bulk_edit.html.twig', [
            'speakers' => $speakers,
            'governments' => $governments,
            'errors' => $errors,
        ]);
    }

    /**
     * Import en masse : collage depuis un tableur ou fichier CSV, aperçu de
     * ce qui sera créé ou mis à jour, puis confirmation.
     */
    #[Route('/importer', name: 'admin_speaker_import')]
    public function import(Request $request, GovernmentRepository $governmentRepository, SpeakerImporter $importer): Response
    {
        $governments = $governmentRepository->findOrdered();
        $government = $governmentRepository->findCurrent();
        $text = '';
        $plan = null;

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('import-speaker', $request->request->getString('_token'))) {
                $this->addFlash('error', 'Jeton de sécurité expiré : rechargez la page puis recommencez.');

                return $this->redirectToRoute('admin_speaker_import');
            }

            $government = $governmentRepository->find($request->request->getInt('government'));
            $text = $request->request->getString('lines');
            $file = $request->files->get('file');
            if ($file instanceof UploadedFile && $file->isValid()) {
                $text = (string) file_get_contents($file->getPathname());
                // Fichier enregistré par Excel en « CSV (séparateur : point-virgule) » : souvent en Windows-1252.
                if (!mb_check_encoding($text, 'UTF-8')) {
                    $text = mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
                }
            }

            $plan = $importer->plan($text, $government);
            if ($plan === []) {
                $this->addFlash('error', 'Aucune ligne à importer : collez au moins une ligne « Nom complet ; Fonction ; Sigle ».');
                $plan = null;
            } elseif ($request->request->getString('step') === 'confirm') {
                $result = $importer->apply($plan, $government);
                $this->addFlash('success', sprintf('Import terminé dans « %s » : %s, %s.',
                    $government?->getLabel() ?? 'Hors gouvernement',
                    self::plural($result['created'], 'intervenant créé', 'intervenants créés'),
                    self::plural($result['updated'], 'mis à jour', 'mis à jour'),
                ));

                return $this->redirectToRoute('admin_speaker_index');
            }
        }

        return $this->render('admin/speaker/import.html.twig', [
            'governments' => $governments,
            'government' => $government,
            'lines' => $text,
            'plan' => $plan,
            'counts' => $plan === null ? [] : array_count_values(array_column($plan, 'status')),
        ]);
    }

    #[Route('/nouveau', name: 'admin_speaker_new')]
    public function new(Request $request, EntityManagerInterface $entityManager, GovernmentRepository $governmentRepository): Response
    {
        $speaker = (new Speaker())->setGovernment($governmentRepository->findCurrent());
        $form = $this->createForm(SpeakerType::class, $speaker);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($speaker);
            $entityManager->flush();

            $this->addFlash('success', 'Intervenant créé.');

            return $this->redirectToRoute('admin_speaker_index');
        }

        return $this->render('admin/speaker/form.html.twig', [
            'form' => $form,
            'speaker' => $speaker,
        ]);
    }

    #[Route('/{id}/modifier', name: 'admin_speaker_edit')]
    public function edit(Speaker $speaker, Request $request, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createForm(SpeakerType::class, $speaker);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();

            $this->addFlash('success', 'Intervenant mis à jour.');

            return $this->redirectToRoute('admin_speaker_index');
        }

        return $this->render('admin/speaker/form.html.twig', [
            'form' => $form,
            'speaker' => $speaker,
        ]);
    }

    #[Route('/{id}/supprimer', name: 'admin_speaker_delete', methods: ['POST'])]
    public function delete(Speaker $speaker, Request $request, EntityManagerInterface $entityManager): Response
    {
        if ($this->isCsrfTokenValid('delete-speaker-' . $speaker->getId(), $request->request->get('_token'))) {
            // Les contenus pointent vers leur intervenant : la base refuserait la suppression.
            if (!$speaker->getVideos()->isEmpty()) {
                $this->addFlash('error', sprintf('%s est rattaché à %s : réattribuez-les avant de le supprimer.', $speaker->getFullName(), self::plural($speaker->getVideos()->count(), 'contenu')));

                return $this->redirectToRoute('admin_speaker_index');
            }
            $entityManager->remove($speaker);
            $entityManager->flush();

            $this->addFlash('success', 'Intervenant supprimé.');
        }

        return $this->redirectToRoute('admin_speaker_index');
    }

    /**
     * @return array<int, int> nombre de contenus par intervenant
     */
    private function videoCounts(SpeakerRepository $speakerRepository): array
    {
        return array_map('intval', array_column($speakerRepository->createQueryBuilder('s')
            ->select('s.id AS id', 'COUNT(v.id) AS videoCount')
            ->leftJoin('s.videos', 'v')
            ->groupBy('s.id')
            ->getQuery()
            ->getArrayResult(), 'videoCount', 'id'));
    }
}
