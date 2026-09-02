<?php

namespace App\Entity;

use App\Enum\VideoFileType;
use App\Enum\VideoStatus;
use App\Repository\VideoRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\Common\Collections\Criteria;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;

#[ORM\Entity(repositoryClass: VideoRepository::class)]
#[ORM\Table(name: 'video')]
#[UniqueEntity(fields: ['slug'], message: 'Un contenu avec ce slug existe déjà.')]
class Video
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 200)]
    private string $title = '';

    #[ORM\Column(length: 220, unique: true)]
    private string $slug = '';

    #[ORM\Column(type: 'text')]
    private string $summary = '';

    #[ORM\Column(enumType: VideoStatus::class, options: ['default' => 'publie'])]
    private VideoStatus $status = VideoStatus::BROUILLON;

    #[ORM\ManyToOne(targetEntity: Thematic::class, inversedBy: 'videos')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Thematic $thematic = null;

    #[ORM\ManyToOne(targetEntity: Language::class, inversedBy: 'videos')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Language $language = null;

    #[ORM\ManyToOne(targetEntity: CouncilSession::class, inversedBy: 'videos')]
    #[ORM\JoinColumn(nullable: false)]
    private ?CouncilSession $councilSession = null;

    #[ORM\Column]
    private int $durationSeconds = 0;

    #[ORM\Column]
    private int $viewsCount = 0;

    #[ORM\Column]
    private \DateTimeImmutable $publishedAt;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $learningPoints = null;

    #[ORM\Column]
    private bool $featured = false;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\ManyToOne(targetEntity: Speaker::class, inversedBy: 'videos')]
    private ?Speaker $speaker = null;

    /** @var Collection<int, VideoFile> */
    #[ORM\OneToMany(targetEntity: VideoFile::class, mappedBy: 'video', cascade: ['persist'], orphanRemoval: true)]
    private Collection $files;

    public function __construct()
    {
        $this->publishedAt = new \DateTimeImmutable();
        $this->createdAt = new \DateTimeImmutable();
        $this->files = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): static
    {
        $this->slug = $slug;

        return $this;
    }

    public function getSummary(): string
    {
        return $this->summary;
    }

    public function setSummary(string $summary): static
    {
        $this->summary = $summary;

        return $this;
    }

    public function getStatus(): VideoStatus
    {
        return $this->status;
    }

    public function setStatus(VideoStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getThematic(): ?Thematic
    {
        return $this->thematic;
    }

    public function setThematic(?Thematic $thematic): static
    {
        $this->thematic = $thematic;

        return $this;
    }

    public function getLanguage(): ?Language
    {
        return $this->language;
    }

    public function setLanguage(?Language $language): static
    {
        $this->language = $language;

        return $this;
    }

    public function getCouncilSession(): ?CouncilSession
    {
        return $this->councilSession;
    }

    public function setCouncilSession(?CouncilSession $councilSession): static
    {
        $this->councilSession = $councilSession;

        return $this;
    }

    public function getDurationSeconds(): int
    {
        return $this->durationSeconds;
    }

    public function setDurationSeconds(int $durationSeconds): static
    {
        $this->durationSeconds = $durationSeconds;

        return $this;
    }

    public function getDurationLabel(): string
    {
        $minutes = intdiv($this->durationSeconds, 60);
        $seconds = $this->durationSeconds % 60;

        return sprintf('%d:%02d', $minutes, $seconds);
    }

    public function getViewsCount(): int
    {
        return $this->viewsCount;
    }

    public function setViewsCount(int $viewsCount): static
    {
        $this->viewsCount = $viewsCount;

        return $this;
    }

    public function getPublishedAt(): \DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function setPublishedAt(\DateTimeImmutable $publishedAt): static
    {
        $this->publishedAt = $publishedAt;

        return $this;
    }

    public function getLearningPoints(): ?string
    {
        return $this->learningPoints;
    }

    public function setLearningPoints(?string $learningPoints): static
    {
        $this->learningPoints = $learningPoints;

        return $this;
    }

    /**
     * @return string[]
     */
    public function getLearningPointsList(): array
    {
        if ($this->learningPoints === null || trim($this->learningPoints) === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode("\n", $this->learningPoints)), static fn (string $line) => $line !== ''));
    }

    public function isFeatured(): bool
    {
        return $this->featured;
    }

    public function setFeatured(bool $featured): static
    {
        $this->featured = $featured;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getSpeaker(): ?Speaker
    {
        return $this->speaker;
    }

    public function setSpeaker(?Speaker $speaker): static
    {
        $this->speaker = $speaker;

        return $this;
    }

    /**
     * Toujours triée par type (voir les valeurs préfixées de VideoFileType),
     * que la collection vienne d'être chargée depuis la base ou d'avoir reçu
     * de nouvelles entrées en mémoire (ordre d'insertion non garanti dans ce
     * second cas).
     *
     * @return Collection<int, VideoFile>
     */
    public function getFiles(): Collection
    {
        return $this->files->matching(Criteria::create()->orderBy(['type' => Criteria::ASC]));
    }

    public function addFile(VideoFile $file): static
    {
        if (!$this->files->contains($file)) {
            $this->files->add($file);
            $file->setVideo($this);
        }

        return $this;
    }

    public function removeFile(VideoFile $file): static
    {
        $this->files->removeElement($file);

        return $this;
    }

    public function getVideoFileByType(VideoFileType $type): ?VideoFile
    {
        foreach ($this->files as $file) {
            if ($file->getType() === $type) {
                return $file;
            }
        }

        return null;
    }

    /**
     * Fichier à utiliser pour le lecteur/aperçu : le premier disponible dans
     * l'ordre de VideoFileType::cases() (1080p, 480p, vertical, audio, pdf,
     * image) — un contenu n'a plus de format unique déclaré, ce sont les
     * fichiers réellement déposés qui déterminent ce qui peut être affiché.
     */
    public function getPrimaryPlaybackFile(): ?VideoFile
    {
        foreach (VideoFileType::cases() as $type) {
            $file = $this->getVideoFileByType($type);
            if ($file !== null && $file->getFileName() !== null) {
                return $file;
            }
        }

        return null;
    }
}
