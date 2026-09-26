<?php

namespace App\Entity;

use App\Repository\SpeakerRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SpeakerRepository::class)]
#[ORM\Table(name: 'speaker')]
class Speaker
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 150)]
    private string $fullName = '';

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $sigle = null;

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $role = null;

    /** @var Collection<int, Video> */
    #[ORM\OneToMany(targetEntity: Video::class, mappedBy: 'speaker')]
    private Collection $videos;

    public function __construct()
    {
        $this->videos = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFullName(): string
    {
        return $this->fullName;
    }

    public function setFullName(string $fullName): static
    {
        $this->fullName = $fullName;

        return $this;
    }

    public function getSigle(): ?string
    {
        return $this->sigle;
    }

    public function setSigle(?string $sigle): static
    {
        $this->sigle = $sigle;

        return $this;
    }

    public function getRole(): ?string
    {
        return $this->role;
    }

    public function setRole(?string $role): static
    {
        $this->role = $role;

        return $this;
    }

    /**
     * Un « Ministre Conseiller(ère) » se reconnaît au radical « Conseill »
     * de sa fonction (accord féminin « Conseillère » compris) : aucune
     * donnée structurée dédiée pour l'instant.
     */
    public function isMinistreConseiller(): bool
    {
        return str_contains($this->role ?? '', 'Conseill');
    }

    /**
     * Code de l'intervenant dans les noms de fichiers de l'import en masse
     * (INTERVENANT-LANGUE-FORMAT) : le sigle, précédé de « MCC » pour un
     * ministre conseiller, ce qui distingue les sigles partagés (MFAS / MCCMFAS).
     */
    public function getFileCode(): ?string
    {
        if (!$this->sigle) {
            return null;
        }

        return ($this->isMinistreConseiller() ? 'MCC' : '') . $this->sigle;
    }

    /**
     * @return Collection<int, Video>
     */
    public function getVideos(): Collection
    {
        return $this->videos;
    }

    public function __toString(): string
    {
        return $this->fullName;
    }
}
