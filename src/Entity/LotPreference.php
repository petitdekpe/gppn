<?php

namespace App\Entity;

use App\Repository\LotPreferenceRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Préférence de lot d'un média (espace média) : langues, formats et
 * intervenant à retrouver en un clic à la prochaine visite. Les sujets et
 * la période ne sont pas enregistrés : ils changent à chaque conseil.
 * Trois préférences au plus par compte (voir MAX).
 */
#[ORM\Entity(repositoryClass: LotPreferenceRepository::class)]
#[ORM\Table(name: 'lot_preference')]
class LotPreference
{
    public const MAX = 3;
    public const NAME_MAX_LENGTH = 60;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column(length: self::NAME_MAX_LENGTH)]
    private string $name = '';

    /** @var list<int> identifiants des langues ; vide = toutes */
    #[ORM\Column(type: 'json')]
    private array $languageIds = [];

    /** @var list<string> valeurs de CapsuleFormat ; vide = tous */
    #[ORM\Column(type: 'json')]
    private array $formats = [];

    /** Personne choisie dans le raccourci « Intervenant » (slug de SpeakerPeriodCriteria). */
    #[ORM\Column(length: 150, nullable: true)]
    private ?string $speaker = null;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    /** @return list<int> */
    public function getLanguageIds(): array
    {
        return $this->languageIds;
    }

    /** @return list<string> */
    public function getFormats(): array
    {
        return $this->formats;
    }

    public function getSpeaker(): ?string
    {
        return $this->speaker;
    }

    /**
     * @param list<int>    $languageIds
     * @param list<string> $formats
     */
    public function setChoices(array $languageIds, array $formats, ?string $speaker): static
    {
        $this->languageIds = $languageIds;
        $this->formats = $formats;
        $this->speaker = $speaker;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
