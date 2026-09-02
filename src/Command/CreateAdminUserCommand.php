<?php

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:admin:create-user',
    description: 'Crée ou met à jour un compte d’accès au back-office (aucune fixture ne crée plus de comptes de démonstration).',
)]
final class CreateAdminUserCommand extends Command
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
        parent::__construct();
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'Adresse e-mail du compte')]
        string $email,
        #[Argument(description: 'Mot de passe du compte')]
        string $password,
        #[Option(description: 'Rôle : ' . 'super_admin, editeur, moderateur ou lecteur_presse')]
        string $role = 'editeur',
    ): int {
        $roleMap = [
            'super_admin' => User::ROLE_SUPER_ADMIN,
            'editeur' => User::ROLE_EDITEUR,
            'moderateur' => User::ROLE_MODERATEUR,
            'lecteur_presse' => User::ROLE_LECTEUR_PRESSE,
        ];

        if (!isset($roleMap[$role])) {
            $io->error(sprintf('Rôle invalide « %s ». Valeurs possibles : %s.', $role, implode(', ', array_keys($roleMap))));

            return Command::INVALID;
        }

        $user = $this->userRepository->findOneBy(['email' => $email]);
        $isNew = $user === null;
        $user ??= new User();

        $user->setEmail($email);
        $user->setRole($roleMap[$role]);
        $user->setPassword($this->passwordHasher->hashPassword($user, $password));

        if ($isNew) {
            $this->entityManager->persist($user);
        }

        $this->entityManager->flush();

        $io->success(sprintf(
            '%s le compte %s (%s).',
            $isNew ? 'Créé' : 'Mis à jour',
            $email,
            $user->getRoleLabel(),
        ));

        return Command::SUCCESS;
    }
}
