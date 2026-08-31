<?php

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: '`user`')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    public const ROLE_SUPER_ADMIN = 'ROLE_SUPER_ADMIN';
    public const ROLE_EDITEUR = 'ROLE_EDITEUR';
    public const ROLE_MODERATEUR = 'ROLE_MODERATEUR';
    public const ROLE_LECTEUR_PRESSE = 'ROLE_LECTEUR_PRESSE';

    /**
     * Rôles assignables depuis le back-office, du plus au moins étendu.
     * La hiérarchie effective (qui hérite de quoi) est définie dans config/packages/security.yaml.
     *
     * @var array<string, string>
     */
    public const ASSIGNABLE_ROLES = [
        'Super-administrateur' => self::ROLE_SUPER_ADMIN,
        'Éditeur' => self::ROLE_EDITEUR,
        'Modérateur' => self::ROLE_MODERATEUR,
        'Lecteur presse' => self::ROLE_LECTEUR_PRESSE,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180, unique: true)]
    private string $email = '';

    /** @var list<string> */
    #[ORM\Column]
    private array $roles = [];

    #[ORM\Column]
    private string $password = '';

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;

        return $this;
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    /**
     * @return list<string>
     */
    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';

        return array_unique($roles);
    }

    /**
     * @param list<string> $roles
     */
    public function setRoles(array $roles): static
    {
        $this->roles = $roles;

        return $this;
    }

    /**
     * Rôle unique assigné à l'utilisateur depuis le back-office (voir ASSIGNABLE_ROLES).
     */
    public function getRole(): ?string
    {
        foreach ($this->roles as $role) {
            if (in_array($role, self::ASSIGNABLE_ROLES, true)) {
                return $role;
            }
        }

        return null;
    }

    public function setRole(string $role): static
    {
        $this->roles = [$role];

        return $this;
    }

    public function getRoleLabel(): ?string
    {
        $role = $this->getRole();

        return $role !== null ? (array_flip(self::ASSIGNABLE_ROLES)[$role] ?? $role) : null;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;

        return $this;
    }

    public function eraseCredentials(): void
    {
    }
}
