<?php

namespace App\Entity;

use App\Repository\GovernmentRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Gouvernement (composition issue d'une nomination ou d'un remaniement).
 * Chaque intervenant appartient à un gouvernement : reconduire un
 * intervenant dans un nouveau gouvernement crée sa fiche dans celui-ci, et
 * l'ancienne fiche reste attachée aux contenus déjà publiés, avec la
 * fonction qu'il occupait alors.
 */
#[ORM\Entity(repositoryClass: GovernmentRepository::class)]
#[ORM\Table(name: 'government')]
class Government
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Ex. « Gouvernement du 23 mai 2021 », « Remaniement du 2 janvier 2025 ». */
    #[ORM\Column(length: 150)]
    private string $label = '';

    /**
     * Date d'entrée en fonction : sert à retrouver le gouvernement en place
     * à la date d'un conseil des ministres (import en masse).
     */
    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    /** Un seul gouvernement actuel à la fois (voir GovernmentRepository::makeCurrent). */
    #[ORM\Column(name: 'is_current', options: ['default' => false])]
    private bool $current = false;

    /** @var Collection<int, Speaker> */
    #[ORM\OneToMany(targetEntity: Speaker::class, mappedBy: 'government')]
    #[ORM\OrderBy(['fullName' => 'ASC'])]
    private Collection $speakers;

    public function __construct()
    {
        $this->speakers = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function setStartedAt(?\DateTimeImmutable $startedAt): static
    {
        $this->startedAt = $startedAt;

        return $this;
    }

    public function isCurrent(): bool
    {
        return $this->current;
    }

    public function setCurrent(bool $current): static
    {
        $this->current = $current;

        return $this;
    }

    /**
     * @return Collection<int, Speaker>
     */
    public function getSpeakers(): Collection
    {
        return $this->speakers;
    }

    public function __toString(): string
    {
        return $this->label;
    }
}
