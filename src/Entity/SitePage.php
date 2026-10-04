<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Page de texte modifiable depuis l'admin (Politique de confidentialité,
 * Mentions légales, coordonnées de la page Nous contacter), rédigée en
 * Markdown. Tant qu'une page n'a jamais été enregistrée, le site affiche son
 * texte par défaut (voir App\Service\SitePages).
 */
#[ORM\Entity]
#[ORM\Table(name: 'site_page')]
class SitePage
{
    public const TITLE_MAX_LENGTH = 150;
    public const CONTENT_MAX_LENGTH = 60000;

    #[ORM\Id]
    #[ORM\Column(length: 40)]
    private string $slug;

    #[ORM\Column(length: self::TITLE_MAX_LENGTH)]
    private string $title;

    #[ORM\Column(type: 'text')]
    private string $content;

    /** Null tant que la page n'a jamais été enregistrée (texte par défaut). */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $updatedBy = null;

    public function __construct(string $slug, string $title, string $content)
    {
        $this->slug = $slug;
        $this->title = $title;
        $this->content = $content;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function setContent(string $content): static
    {
        $this->content = $content;

        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getUpdatedBy(): ?User
    {
        return $this->updatedBy;
    }

    public function markUpdated(?User $user): static
    {
        $this->updatedAt = new \DateTimeImmutable();
        $this->updatedBy = $user;

        return $this;
    }
}
