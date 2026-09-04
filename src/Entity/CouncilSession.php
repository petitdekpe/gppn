<?php

namespace App\Entity;

use App\Repository\CouncilSessionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;

#[ORM\Entity(repositoryClass: CouncilSessionRepository::class)]
#[ORM\Table(name: 'council_session')]
#[UniqueEntity(fields: ['date'], message: 'Un conseil des ministres existe déjà pour cette date.')]
#[UniqueEntity(fields: ['slug'], message: 'Un conseil des ministres avec ce slug existe déjà.')]
class CouncilSession
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'date_immutable', unique: true)]
    private ?\DateTimeImmutable $date = null;

    #[ORM\Column(length: 200, nullable: true)]
    private ?string $label = null;

    #[ORM\Column(length: 220, unique: true)]
    private string $slug = '';

    /** @var Collection<int, Subject> */
    #[ORM\OneToMany(targetEntity: Subject::class, mappedBy: 'councilSession')]
    private Collection $subjects;

    public function __construct()
    {
        $this->subjects = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDate(): ?\DateTimeImmutable
    {
        return $this->date;
    }

    public function setDate(\DateTimeImmutable $date): static
    {
        $this->date = $date;

        return $this;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function setLabel(?string $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): static
    {
        $this->slug = $slug;

        return $this;
    }

    /**
     * @return Collection<int, Subject>
     */
    public function getSubjects(): Collection
    {
        return $this->subjects;
    }
}
