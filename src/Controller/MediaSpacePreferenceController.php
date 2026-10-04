<?php

namespace App\Controller;

use App\Entity\LotPreference;
use App\Entity\User;
use App\Repository\LotPreferenceRepository;
use App\Service\LotPreferences;
use App\Service\MediaAccess;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Préférences de lot de l'espace média (voir LotPreferences). Appelé en
 * arrière-plan par lot_preference_controller.js, qui envoie aussi les choix
 * en cours du constructeur de lot : la réponse est le panneau « Mes
 * préférences » à jour, ou le message d'erreur à afficher (422).
 */
#[Route('/espace-media/preferences')]
class MediaSpacePreferenceController extends AbstractController
{
    public function __construct(
        private readonly MediaAccess $mediaAccess,
        private readonly LotPreferences $lotPreferences,
        private readonly LotPreferenceRepository $repository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('', name: 'app_media_space_preference_save', methods: ['POST'])]
    public function save(Request $request): Response
    {
        $user = $this->checkAccess($request);
        $choices = $this->lotPreferences->choicesFromParams($request->request->all());
        $name = trim($request->request->getString('nom'));

        if ($this->lotPreferences->isEmpty($choices)) {
            return $this->error('Choisissez au moins une langue, un format ou un intervenant à enregistrer.');
        }
        if ($name === '') {
            return $this->error('Donnez un nom à cette préférence.');
        }
        if (mb_strlen($name) > LotPreference::NAME_MAX_LENGTH) {
            return $this->error(sprintf('Le nom ne doit pas dépasser %d caractères.', LotPreference::NAME_MAX_LENGTH));
        }

        $preference = $request->request->getInt('remplacer') > 0
            ? $this->repository->findOneForUser($user, $request->request->getInt('remplacer'))
            : null;
        if ($preference === null) {
            if (count($this->repository->findForUser($user)) >= LotPreference::MAX) {
                return $this->error(sprintf('Vous avez déjà %d préférences : choisissez celle à remplacer.', LotPreference::MAX));
            }
            $preference = (new LotPreference())->setUser($user);
            $this->entityManager->persist($preference);
        }
        $preference->setName($name)->setChoices($choices['languageIds'], $choices['formats'], $choices['speaker']);
        $this->entityManager->flush();

        return $this->panel($user, $choices);
    }

    /**
     * Case « Ne plus me proposer » cochée (proposer=0) ou décochée (proposer=1) :
     * enregistrée aussitôt, quelle que soit la façon dont la fenêtre se ferme.
     */
    #[Route('/proposition', name: 'app_media_space_preference_prompt', methods: ['POST'])]
    public function setPrompt(Request $request): Response
    {
        $user = $this->checkAccess($request);
        $user->setLotPreferencePrompt($request->request->getBoolean('proposer'));
        $this->entityManager->flush();

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/{id<\d+>}/supprimer', name: 'app_media_space_preference_delete', methods: ['POST'])]
    public function delete(Request $request, int $id): Response
    {
        $user = $this->checkAccess($request);
        $preference = $this->repository->findOneForUser($user, $id);
        if ($preference !== null) {
            $this->entityManager->remove($preference);
            $this->entityManager->flush();
        }

        return $this->panel($user, $this->lotPreferences->choicesFromParams($request->request->all()));
    }

    private function checkAccess(Request $request): User
    {
        if (!$this->isCsrfTokenValid(LotPreferences::CSRF_TOKEN_ID, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }
        $user = $this->getUser();
        if (!$this->mediaAccess->isMedia() || !$user instanceof User) {
            throw $this->createAccessDeniedException('Réservé aux médias connectés.');
        }

        return $user;
    }

    /** @param array{languageIds: list<int>, formats: list<string>, speaker: ?string} $current */
    private function panel(User $user, array $current): Response
    {
        return $this->render('media_space/_preferences.html.twig', $this->lotPreferences->panel($user, $current));
    }

    private function error(string $message): Response
    {
        return new Response($message, Response::HTTP_UNPROCESSABLE_ENTITY, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
