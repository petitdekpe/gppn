<?php

namespace App\Controller\Admin;

use App\Entity\CouncilSession;
use App\Entity\Subject;
use App\Form\Admin\CouncilSessionType;
use App\Form\Admin\SubjectType;
use App\Repository\CouncilSessionRepository;
use App\Repository\SubjectRepository;
use App\Repository\ThematicRepository;
use App\Repository\VideoRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/conseils-des-ministres')]
#[IsGranted('ROLE_EDITEUR')]
class CouncilSessionController extends AbstractController
{
    use BulkActionTrait;

    /**
     * `?pour=contenu` : parcours lancé depuis « Nouveau contenu » (conseil →
     * sujet → retour au nouveau contenu, sujet pré-sélectionné). Valeur
     * fixe plutôt qu'une URL de retour libre, pour éviter toute redirection
     * ouverte.
     */
    public const FLOW_PARAM = 'pour';
    public const FLOW_VIDEO = 'contenu';

    #[Route('', name: 'admin_council_session_index')]
    public function index(Request $request, CouncilSessionRepository $councilSessionRepository, VideoRepository $videoRepository, SubjectRepository $subjectRepository, ThematicRepository $thematicRepository): Response
    {
        $councilSessions = $councilSessionRepository->findBy([], ['date' => 'DESC']);

        $subjectsBySession = [];
        foreach ($subjectRepository->createQueryBuilder('s')
            ->innerJoin('s.thematic', 't')->addSelect('t')
            ->orderBy('s.title', 'ASC')
            ->getQuery()
            ->getResult() as $subject) {
            $subjectsBySession[$subject->getCouncilSession()->getId()][] = $subject;
        }

        $videoCounts = array_map('intval', array_column($videoRepository->createQueryBuilder('v')
            ->select('IDENTITY(v.subject) AS subjectId', 'COUNT(v.id) AS videoCount')
            ->groupBy('v.subject')
            ->getQuery()
            ->getArrayResult(), 'videoCount', 'subjectId'));

        $groups = [];
        foreach ($councilSessions as $councilSession) {
            $subjects = $subjectsBySession[$councilSession->getId()] ?? [];
            $groups[$councilSession->getId()] = [
                'session' => $councilSession,
                'subjects' => $subjects,
                'videoCount' => array_sum(array_map(fn ($subject) => $videoCounts[$subject->getId()] ?? 0, $subjects)),
            ];
        }

        // Calendrier latéral : tous les conseils, même sans sujet (count = sujets).
        $calendar = array_values(array_map(fn (array $group) => [
            'id' => $group['session']->getId(),
            'date' => $group['session']->getDate()->format('Y-m-d'),
            'count' => count($group['subjects']),
        ], $groups));

        $selectedId = $request->query->getInt('conseil');
        if (!isset($groups[$selectedId])) {
            $selectedId = array_key_first($groups);
        }

        return $this->render('admin/council_session/index.html.twig', [
            'groups' => $groups,
            'calendar' => $calendar,
            'selectedSessionId' => $selectedId,
            'videoCounts' => $videoCounts,
            'thematics' => $thematicRepository->findBy([], ['name' => 'ASC']),
        ]);
    }

    /**
     * Actions groupées sur les sujets du conseil affiché : changement de
     * thématique, ou suppression des sujets qui n'ont plus de contenu.
     */
    #[Route('/sujets/actions-groupees', name: 'admin_council_session_subject_bulk', methods: ['POST'])]
    public function bulkSubjects(Request $request, SubjectRepository $subjectRepository, ThematicRepository $thematicRepository, VideoRepository $videoRepository, EntityManagerInterface $entityManager): Response
    {
        $ids = $this->bulkIds($request, 'bulk-subject');
        if ($ids === null) {
            return $this->backToList($request, 'admin_council_session_index');
        }

        $action = $request->request->getString('action');
        $subjects = $subjectRepository->findBy(['id' => $ids]);
        $skipped = [];

        if ($action === 'thematic' && ($thematic = $thematicRepository->find($request->request->getInt('target'))) !== null) {
            foreach ($subjects as $subject) {
                $subject->setThematic($thematic);
            }
            $message = sprintf('%s rattaché%s à la thématique « %s ».', self::plural(count($subjects), 'sujet'), count($subjects) > 1 ? 's' : '', $thematic->getName());
        } elseif ($action === 'delete') {
            $deleted = 0;
            foreach ($subjects as $subject) {
                if ($videoRepository->count(['subject' => $subject]) > 0) {
                    $skipped[] = $subject->getTitle() . ' (encore rattaché à des contenus)';

                    continue;
                }
                $entityManager->remove($subject);
                ++$deleted;
            }
            $message = self::plural($deleted, 'sujet supprimé', 'sujets supprimés') . '.';
        } else {
            $this->unknownBulkAction();

            return $this->backToList($request, 'admin_council_session_index');
        }

        $entityManager->flush();
        $this->bulkReport($message, $skipped);

        return $this->backToList($request, 'admin_council_session_index');
    }

    #[Route('/nouveau', name: 'admin_council_session_new')]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        $councilSession = new CouncilSession();
        $form = $this->createForm(CouncilSessionType::class, $councilSession);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->assignSlug($councilSession);
            $entityManager->persist($councilSession);
            $entityManager->flush();

            if ($this->isVideoFlow($request)) {
                // Un contenu se rattache à un sujet : on enchaîne sur sa création.
                $this->addFlash('success', 'Conseil des ministres créé. Ajoutez maintenant le sujet du contenu.');

                return $this->redirectToRoute('admin_council_session_subject_new', [
                    'id' => $councilSession->getId(),
                    self::FLOW_PARAM => self::FLOW_VIDEO,
                ]);
            }

            $this->addFlash('success', 'Conseil des ministres créé.');

            return $this->redirectToRoute('admin_council_session_index', ['conseil' => $councilSession->getId()]);
        }

        return $this->render('admin/council_session/form.html.twig', [
            'form' => $form,
            'councilSession' => $councilSession,
            'videoFlow' => $this->isVideoFlow($request),
        ]);
    }

    #[Route('/{id}/modifier', name: 'admin_council_session_edit')]
    public function edit(CouncilSession $councilSession, Request $request, EntityManagerInterface $entityManager, SubjectRepository $subjectRepository, VideoRepository $videoRepository): Response
    {
        $form = $this->createForm(CouncilSessionType::class, $councilSession);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->assignSlug($councilSession);
            $entityManager->flush();

            $this->addFlash('success', 'Conseil des ministres mis à jour.');

            return $this->redirectToRoute('admin_council_session_edit', ['id' => $councilSession->getId()]);
        }

        $subjects = $subjectRepository->createQueryBuilder('s')
            ->innerJoin('s.thematic', 't')->addSelect('t')
            ->andWhere('s.councilSession = :councilSession')
            ->setParameter('councilSession', $councilSession)
            ->orderBy('s.title', 'ASC')
            ->getQuery()
            ->getResult();

        $videoCounts = [];
        foreach ($subjects as $subject) {
            $videoCounts[$subject->getId()] = $videoRepository->count(['subject' => $subject]);
        }

        return $this->render('admin/council_session/form.html.twig', [
            'form' => $form,
            'councilSession' => $councilSession,
            'subjects' => $subjects,
            'videoCounts' => $videoCounts,
        ]);
    }

    #[Route('/{id}/supprimer', name: 'admin_council_session_delete', methods: ['POST'])]
    public function delete(CouncilSession $councilSession, Request $request, EntityManagerInterface $entityManager, SubjectRepository $subjectRepository): Response
    {
        if (!$this->isCsrfTokenValid('delete-council-session-' . $councilSession->getId(), $request->request->get('_token'))) {
            return $this->redirectToRoute('admin_council_session_index');
        }

        // Un sujet exige un conseil des ministres (colonne non nullable) :
        // il faut donc bloquer dès qu'un sujet existe, même sans contenu.
        if ($subjectRepository->count(['councilSession' => $councilSession]) > 0) {
            $this->addFlash('error', 'Impossible de supprimer un conseil des ministres encore rattaché à des sujets.');

            return $this->redirectToRoute('admin_council_session_index');
        }

        $entityManager->remove($councilSession);
        $entityManager->flush();

        $this->addFlash('success', 'Conseil des ministres supprimé.');

        return $this->redirectToRoute('admin_council_session_index');
    }

    #[Route('/{id}/sujets/nouveau', name: 'admin_council_session_subject_new')]
    public function newSubject(CouncilSession $councilSession, Request $request, EntityManagerInterface $entityManager): Response
    {
        $subject = new Subject();
        $subject->setCouncilSession($councilSession);
        $form = $this->createForm(SubjectType::class, $subject);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($subject);
            $entityManager->flush();

            if ($this->isVideoFlow($request)) {
                $this->addFlash('success', 'Sujet créé et sélectionné pour votre nouveau contenu.');

                return $this->redirectToRoute('admin_video_new', ['sujet' => $subject->getId()]);
            }

            $this->addFlash('success', 'Sujet créé.');

            return $this->redirectToRoute('admin_council_session_index', ['conseil' => $councilSession->getId()]);
        }

        return $this->render('admin/council_session/subject_form.html.twig', [
            'form' => $form,
            'councilSession' => $councilSession,
            'subject' => $subject,
            'videoFlow' => $this->isVideoFlow($request),
        ]);
    }

    #[Route('/{id}/sujets/{subjectId}/modifier', name: 'admin_council_session_subject_edit')]
    public function editSubject(CouncilSession $councilSession, #[MapEntity(id: 'subjectId')] Subject $subject, Request $request, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createForm(SubjectType::class, $subject);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();

            $this->addFlash('success', 'Sujet mis à jour.');

            return $this->redirectToRoute('admin_council_session_index', ['conseil' => $councilSession->getId()]);
        }

        return $this->render('admin/council_session/subject_form.html.twig', [
            'form' => $form,
            'councilSession' => $councilSession,
            'subject' => $subject,
        ]);
    }

    #[Route('/{id}/sujets/{subjectId}/supprimer', name: 'admin_council_session_subject_delete', methods: ['POST'])]
    public function deleteSubject(CouncilSession $councilSession, #[MapEntity(id: 'subjectId')] Subject $subject, Request $request, EntityManagerInterface $entityManager, VideoRepository $videoRepository): Response
    {
        if (!$this->isCsrfTokenValid('delete-subject-' . $subject->getId(), $request->request->get('_token'))) {
            return $this->redirectToRoute('admin_council_session_index', ['conseil' => $councilSession->getId()]);
        }

        if ($videoRepository->count(['subject' => $subject]) > 0) {
            $this->addFlash('error', 'Impossible de supprimer un sujet encore rattaché à des contenus.');

            return $this->redirectToRoute('admin_council_session_index', ['conseil' => $councilSession->getId()]);
        }

        $entityManager->remove($subject);
        $entityManager->flush();

        $this->addFlash('success', 'Sujet supprimé.');

        return $this->redirectToRoute('admin_council_session_index', ['conseil' => $councilSession->getId()]);
    }

    private function isVideoFlow(Request $request): bool
    {
        return $request->query->get(self::FLOW_PARAM) === self::FLOW_VIDEO;
    }

    /**
     * Le slug est dérivé de la date plutôt que saisi à la main : la date
     * étant déjà contrainte unique, cela garantit un slug unique et stable
     * sans exposer un champ supplémentaire dans le formulaire.
     */
    private function assignSlug(CouncilSession $councilSession): void
    {
        $date = $councilSession->getDate();
        if ($date === null) {
            return;
        }

        $councilSession->setSlug('conseil-du-' . $date->format('Y-m-d'));
    }
}
