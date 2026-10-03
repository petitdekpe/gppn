<?php

namespace App\Controller\Admin;

use App\Entity\User;
use App\Form\Admin\UserType;
use App\Repository\UserRepository;
use App\Service\AdminPaginator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/utilisateurs')]
#[IsGranted('ROLE_SUPER_ADMIN')]
class UserController extends AbstractController
{
    use BulkActionTrait;

    /**
     * Deux onglets : l'équipe (back-office) et les médias inscrits depuis
     * l'espace presse, avec leurs coordonnées.
     */
    #[Route('', name: 'admin_user_index')]
    public function index(UserRepository $userRepository, Request $request): Response
    {
        $tab = $request->query->getString('type') === 'medias' ? 'medias' : 'equipe';
        $results = AdminPaginator::paginate(
            $this->usersOfTab($userRepository, $tab)->orderBy($tab === 'medias' ? 'u.registeredAt' : 'u.email', $tab === 'medias' ? 'DESC' : 'ASC'),
            $request->query->getInt('page', 1),
            50,
        );

        return $this->render('admin/user/index.html.twig', [
            'users' => $results['items'],
            'results' => $results,
            'tab' => $tab,
            'counts' => [
                'equipe' => (int) $this->usersOfTab($userRepository, 'equipe')->select('COUNT(u.id)')->getQuery()->getSingleScalarResult(),
                'medias' => (int) $this->usersOfTab($userRepository, 'medias')->select('COUNT(u.id)')->getQuery()->getSingleScalarResult(),
            ],
            'roles' => User::ASSIGNABLE_ROLES,
        ]);
    }

    /** Coordonnées des médias inscrits, pour un tableur. */
    #[Route('/export-medias.csv', name: 'admin_user_export_media')]
    public function exportMedia(UserRepository $userRepository): Response
    {
        $users = $this->usersOfTab($userRepository, 'medias')->orderBy('u.registeredAt', 'DESC')->getQuery()->getResult();

        $rows = [['Nom', 'Média ou organisation', 'Type de média', 'E-mail', 'Téléphone', 'Inscription']];
        foreach ($users as $user) {
            $rows[] = [$user->getFullName(), $user->getOrganization(), $user->getMediaTypeLabel(), $user->getEmail(), $user->getPhone(), $user->getRegisteredAt()?->format('d/m/Y H:i')];
        }
        // Point-virgule et BOM : ouverture directe et sans accents cassés dans Excel.
        $csv = "\xEF\xBB\xBF" . implode("\r\n", array_map(static fn (array $row) => implode(';', array_map(
            static fn ($cell) => '"' . str_replace('"', '""', (string) $cell) . '"',
            $row,
        )), $rows));

        return new Response($csv, Response::HTTP_OK, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => sprintf('attachment; filename="medias-%s.csv"', (new \DateTimeImmutable())->format('Y-m-d')),
        ]);
    }

    private function usersOfTab(UserRepository $userRepository, string $tab): \Doctrine\ORM\QueryBuilder
    {
        // Rôles stockés en JSON : un compte Média ne porte que ROLE_MEDIA.
        return $userRepository->createQueryBuilder('u')
            ->andWhere($tab === 'medias' ? 'u.roles LIKE :media' : 'u.roles NOT LIKE :media')
            ->setParameter('media', '%"' . User::ROLE_MEDIA . '"%');
    }

    /**
     * Changement de rôle ou suppression de plusieurs comptes. Le compte
     * connecté est toujours épargné : on ne se retire pas ses propres droits.
     */
    #[Route('/actions-groupees', name: 'admin_user_bulk', methods: ['POST'])]
    public function bulk(Request $request, UserRepository $userRepository, EntityManagerInterface $entityManager): Response
    {
        $ids = $this->bulkIds($request, 'bulk-user');
        if ($ids === null) {
            return $this->redirectToRoute('admin_user_index');
        }

        $action = $request->request->getString('action');
        $role = $request->request->getString('target');
        if (!in_array($action, ['role', 'delete'], true) || ($action === 'role' && !in_array($role, User::ASSIGNABLE_ROLES, true))) {
            $this->unknownBulkAction();

            return $this->redirectToRoute('admin_user_index');
        }

        $done = 0;
        $skipped = [];
        foreach ($userRepository->findBy(['id' => $ids]) as $user) {
            if ($user === $this->getUser()) {
                $skipped[] = $user->getEmail() . ' (votre propre compte)';

                continue;
            }
            $action === 'role' ? $user->setRole($role) : $entityManager->remove($user);
            ++$done;
        }
        $entityManager->flush();

        $this->bulkReport($action === 'role'
            ? sprintf('%s : rôle « %s » attribué.', self::plural($done, 'compte'), array_flip(User::ASSIGNABLE_ROLES)[$role])
            : self::plural($done, 'compte supprimé', 'comptes supprimés') . '.', $skipped);

        return $this->redirectToRoute('admin_user_index');
    }

    #[Route('/nouveau', name: 'admin_user_new')]
    public function new(Request $request, EntityManagerInterface $entityManager, UserPasswordHasherInterface $passwordHasher): Response
    {
        $user = new User();
        $form = $this->createForm(UserType::class, $user, ['is_new' => true]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $user->setPassword($passwordHasher->hashPassword($user, $form->get('plainPassword')->getData()));
            $entityManager->persist($user);
            $entityManager->flush();

            $this->addFlash('success', 'Utilisateur créé.');

            return $this->redirectToRoute('admin_user_index');
        }

        return $this->render('admin/user/form.html.twig', [
            'form' => $form,
            'user' => $user,
        ]);
    }

    #[Route('/{id}/modifier', name: 'admin_user_edit')]
    public function edit(User $user, Request $request, EntityManagerInterface $entityManager, UserPasswordHasherInterface $passwordHasher): Response
    {
        $form = $this->createForm(UserType::class, $user, ['is_new' => false]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $plainPassword = $form->get('plainPassword')->getData();
            if ($plainPassword) {
                $user->setPassword($passwordHasher->hashPassword($user, $plainPassword));
            }
            $entityManager->flush();

            $this->addFlash('success', 'Utilisateur mis à jour.');

            return $this->redirectToRoute('admin_user_index');
        }

        return $this->render('admin/user/form.html.twig', [
            'form' => $form,
            'user' => $user,
        ]);
    }

    #[Route('/{id}/supprimer', name: 'admin_user_delete', methods: ['POST'])]
    public function delete(User $user, Request $request, EntityManagerInterface $entityManager): Response
    {
        if (!$this->isCsrfTokenValid('delete-user-' . $user->getId(), $request->request->get('_token'))) {
            return $this->redirectToRoute('admin_user_index');
        }

        if ($user === $this->getUser()) {
            $this->addFlash('error', 'Vous ne pouvez pas supprimer votre propre compte.');

            return $this->redirectToRoute('admin_user_index');
        }

        $entityManager->remove($user);
        $entityManager->flush();

        $this->addFlash('success', 'Utilisateur supprimé.');

        return $this->redirectToRoute('admin_user_index');
    }
}
