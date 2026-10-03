<?php

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: '`user`')]
#[UniqueEntity(fields: ['email'], message: 'Un compte existe déjà avec cette adresse e-mail : connectez-vous.')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    public const ROLE_SUPER_ADMIN = 'ROLE_SUPER_ADMIN';
    public const ROLE_EDITEUR = 'ROLE_EDITEUR';
    public const ROLE_MODERATEUR = 'ROLE_MODERATEUR';
    public const ROLE_LECTEUR_PRESSE = 'ROLE_LECTEUR_PRESSE';
    /**
     * Compte presse ou média inscrit depuis le site (espace presse) :
     * téléchargements groupés (archives, lots, kits), sans accès au back-office.
     */
    public const ROLE_MEDIA = 'ROLE_MEDIA';

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
        'Média (espace presse)' => self::ROLE_MEDIA,
    ];

    /** Types de média proposés à l'inscription. */
    public const MEDIA_TYPES = [
        'Télévision' => 'television',
        'Radio' => 'radio',
        'Presse écrite' => 'presse',
        'Média en ligne' => 'web',
        'Groupe WhatsApp ou communautaire' => 'communautaire',
        'Institution' => 'institution',
        'Autre' => 'autre',
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

    // Coordonnées recueillies à l'inscription dans l'espace presse.

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $fullName = null;

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $organization = null;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $mediaType = null;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $phone = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $registeredAt = null;

    public function getFullName(): ?string
    {
        return $this->fullName;
    }

    public function setFullName(?string $fullName): static
    {
        $this->fullName = $fullName;

        return $this;
    }

    public function getOrganization(): ?string
    {
        return $this->organization;
    }

    public function setOrganization(?string $organization): static
    {
        $this->organization = $organization;

        return $this;
    }

    public function getMediaType(): ?string
    {
        return $this->mediaType;
    }

    public function setMediaType(?string $mediaType): static
    {
        $this->mediaType = $mediaType;

        return $this;
    }

    public function getMediaTypeLabel(): ?string
    {
        return $this->mediaType !== null ? (array_flip(self::MEDIA_TYPES)[$this->mediaType] ?? $this->mediaType) : null;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): static
    {
        $this->phone = $phone;

        return $this;
    }

    public function getRegisteredAt(): ?\DateTimeImmutable
    {
        return $this->registeredAt;
    }

    public function setRegisteredAt(?\DateTimeImmutable $registeredAt): static
    {
        $this->registeredAt = $registeredAt;

        return $this;
    }

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
