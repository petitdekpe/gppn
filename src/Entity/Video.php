<?php

namespace App\Entity;

use App\Enum\CapsuleFormat;
use App\Enum\VideoStatus;
use App\Repository\VideoRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\HttpFoundation\File\File;
use Vich\UploaderBundle\Mapping\Attribute\Uploadable;
use Vich\UploaderBundle\Mapping\Attribute\UploadableField;

#[ORM\Entity(repositoryClass: VideoRepository::class)]
#[ORM\Table(name: 'video')]
#[UniqueEntity(fields: ['slug'], message: 'Une vidéo avec ce slug existe déjà.')]
#[Uploadable]
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

    #[ORM\Column(enumType: CapsuleFormat::class, options: ['default' => 'video'])]
    private CapsuleFormat $format = CapsuleFormat::VIDEO;

    #[ORM\Column(enumType: VideoStatus::class, options: ['default' => 'publie'])]
    private VideoStatus $status = VideoStatus::BROUILLON;

    #[UploadableField(mapping: 'video_source', fileNameProperty: 'sourceFileName', size: 'sourceFileSize')]
    private ?File $sourceFile = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $sourceFileName = null;

    #[ORM\Column(nullable: true)]
    private ?int $sourceFileSize = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $sourceSha256 = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $thumbnailPath = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $hlsPlaylistPath = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\ManyToOne(targetEntity: Thematic::class, inversedBy: 'videos')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Thematic $thematic = null;

    #[ORM\ManyToOne(targetEntity: Language::class, inversedBy: 'videos')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Language $language = null;

    #[ORM\Column]
    private int $durationSeconds = 0;

    #[ORM\Column]
    private int $viewsCount = 0;

    #[ORM\Column]
    private \DateTimeImmutable $publishedAt;

    #[UploadableField(mapping: 'video_download', fileNameProperty: 'downloadFileName', size: 'downloadFileSize', mimeType: 'downloadFileMimeType', originalName: 'downloadFileOriginalName')]
    private ?File $downloadFile = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $downloadFileName = null;

    #[ORM\Column(nullable: true)]
    private ?int $downloadFileSize = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $downloadFileMimeType = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $downloadFileOriginalName = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $learningPoints = null;

    #[ORM\Column]
    private bool $featured = false;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** @var Collection<int, Speaker> */
    #[ORM\ManyToMany(targetEntity: Speaker::class, inversedBy: 'videos')]
    #[ORM\JoinTable(name: 'video_speaker')]
    private Collection $speakers;

    public function __construct()
    {
        $this->publishedAt = new \DateTimeImmutable();
        $this->createdAt = new \DateTimeImmutable();
        $this->speakers = new ArrayCollection();
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

    public function getFormat(): CapsuleFormat
    {
        return $this->format;
    }

    public function setFormat(CapsuleFormat $format): static
    {
        $this->format = $format;

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

    public function getSourceFile(): ?File
    {
        return $this->sourceFile;
    }

    /**
     * Ne pas oublier de toucher `updatedAt` : c'est le seul moyen pour Doctrine
     * de détecter qu'une entité déjà persistée a changé quand seul ce champ
     * (non mappé) est modifié, ce dont Vich a besoin pour déclencher le
     * déplacement du nouveau fichier lors d'une mise à jour.
     */
    public function setSourceFile(?File $sourceFile = null): static
    {
        $this->sourceFile = $sourceFile;

        if ($sourceFile instanceof File) {
            $this->updatedAt = new \DateTimeImmutable();
        }

        return $this;
    }

    public function getSourceFileName(): ?string
    {
        return $this->sourceFileName;
    }

    public function setSourceFileName(?string $sourceFileName): static
    {
        $this->sourceFileName = $sourceFileName;

        return $this;
    }

    public function getSourceFileSize(): ?int
    {
        return $this->sourceFileSize;
    }

    public function setSourceFileSize(?int $sourceFileSize): static
    {
        $this->sourceFileSize = $sourceFileSize;

        return $this;
    }

    public function getSourceSha256(): ?string
    {
        return $this->sourceSha256;
    }

    public function setSourceSha256(?string $sourceSha256): static
    {
        $this->sourceSha256 = $sourceSha256;

        return $this;
    }

    public function getThumbnailPath(): ?string
    {
        return $this->thumbnailPath;
    }

    public function setThumbnailPath(?string $thumbnailPath): static
    {
        $this->thumbnailPath = $thumbnailPath;

        return $this;
    }

    public function getHlsPlaylistPath(): ?string
    {
        return $this->hlsPlaylistPath;
    }

    public function setHlsPlaylistPath(?string $hlsPlaylistPath): static
    {
        $this->hlsPlaylistPath = $hlsPlaylistPath;

        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;

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

    public function getDownloadFile(): ?File
    {
        return $this->downloadFile;
    }

    public function setDownloadFile(?File $downloadFile = null): static
    {
        $this->downloadFile = $downloadFile;

        if ($downloadFile instanceof File) {
            $this->updatedAt = new \DateTimeImmutable();
        }

        return $this;
    }

    public function getDownloadFileName(): ?string
    {
        return $this->downloadFileName;
    }

    public function setDownloadFileName(?string $downloadFileName): static
    {
        $this->downloadFileName = $downloadFileName;

        return $this;
    }

    public function getDownloadFileSize(): ?int
    {
        return $this->downloadFileSize;
    }

    public function setDownloadFileSize(?int $downloadFileSize): static
    {
        $this->downloadFileSize = $downloadFileSize;

        return $this;
    }

    public function getDownloadFileMimeType(): ?string
    {
        return $this->downloadFileMimeType;
    }

    public function setDownloadFileMimeType(?string $downloadFileMimeType): static
    {
        $this->downloadFileMimeType = $downloadFileMimeType;

        return $this;
    }

    public function getDownloadFileOriginalName(): ?string
    {
        return $this->downloadFileOriginalName;
    }

    public function setDownloadFileOriginalName(?string $downloadFileOriginalName): static
    {
        $this->downloadFileOriginalName = $downloadFileOriginalName;

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

    /**
     * @return Collection<int, Speaker>
     */
    public function getSpeakers(): Collection
    {
        return $this->speakers;
    }

    public function addSpeaker(Speaker $speaker): static
    {
        if (!$this->speakers->contains($speaker)) {
            $this->speakers->add($speaker);
        }

        return $this;
    }

    public function removeSpeaker(Speaker $speaker): static
    {
        $this->speakers->removeElement($speaker);

        return $this;
    }
}
